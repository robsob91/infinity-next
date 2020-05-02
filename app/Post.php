<?php

namespace App;

use App\Contracts\Auth\Permittable;
use App\Contracts\Support\Formattable as FormattableContract;
use App\Services\ContentFormatter;
use App\Support\Formattable;
use App\Support\Geolocation;
use App\Support\IP;
use App\Traits\TakePerGroup;
use App\Traits\EloquentBinary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Jsonable;
use Cache;
use DB;
use DateTime;
use DateTimeInterface;
use File;
use Request;
use Event;
use App\Events\ThreadNewReply;

/**
 * Model representing posts and threads for boards.
 *
 * @category   Model
 *
 * @author     Joshua Moon <josh@jaw.sh>
 * @copyright  2016 Infinity Next Development Group
 * @license    http://www.gnu.org/licenses/agpl-3.0.en.html AGPL3
 *
 * @since      0.5.1
 */
class Post extends Model implements FormattableContract, Htmlable, Jsonable
{
    use Formattable;
    use EloquentBinary;
    use TakePerGroup;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'posts';

    /**
     * The primary key that is used by ::get().
     *
     * @var string
     */
    protected $primaryKey = 'post_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'board_uri',
        'board_id',
        'reply_to',
        'reply_to_board_id',
        'reply_count',
        'reply_file_count',
        'reply_last',
        'bumped_last',

        'created_at',
        'updated_at',
        'stickied',
        'stickied_at',
        'bumplocked_at',
        'locked_at',
        'featured_at',

        'author_ip',
        'author_ip_nulled_at',
        'author_id',
        'author_country',
        'capcode_id',
        'subject',
        'author',
        'tripcode',
        'email',
        'password',
        'flag_id',

        'body',
        'body_has_content',
        'body_too_long',
        'body_parsed',
        'body_parsed_preview',
        'body_parsed_at',
        'body_html',
        'body_signed',
        'body_rtl',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        // Post Items
        'author_ip',
        'password',
        'body',
        'body_parsed',
        'body_parsed_at',
        'body_html',

        // Relationships
        // 'bans',
        'board',
        // 'citedBy',
        // 'citedPosts',
        // 'editor',
        'thread',
        // 'replies',
        // 'reports',
    ];

    /**
     * Attributes which do not exist but should be appended to the JSON output.
     *
     * @var array
     */
    protected $appends = [
        'html',
        'locked',
        'content_raw',
        'content_html',
        'recently_created',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'author_ip' => 'ip',
        'reply_last' => 'datetime',
        'bumped_last' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'stickied_at' => 'datetime',
        'bumplocked_at' => 'datetime',
        'locked_at' => 'datetime',
        'body_parsed_at' => 'datetime',
        'author_ip_nulled_at' => 'datetime',
    ];

    public function attachments()
    {
        return $this->hasMany(PostAttachment::class, 'post_id')
            ->with('file', 'thumbnail')
            ->orderBy('position', 'asc');
    }

    public function backlinks()
    {
        return $this->hasMany(PostCite::class, 'cite_id', 'post_id');
    }

    public function bans()
    {
        return $this->hasMany(Ban::class, 'post_id');
    }

    public function board()
    {
        return $this->belongsTo(Board::class, 'board_uri');
    }

    public function capcode()
    {
        return $this->hasOne(Role::class, 'role_id', 'capcode_id');
    }

    public function cites()
    {
        return $this->hasMany(PostCite::class, 'post_id');
    }

    public function citedPosts()
    {
        return $this->belongsToMany(static::class, 'post_cites', 'post_id');
    }

    public function citedByPosts()
    {
        return $this->belongsToMany(static::class, 'post_cites', 'cite_id', 'post_id');
    }

    public function dice()
    {
        return $this->belongsToMany(Dice::class, 'post_dice', 'post_id', 'dice_id')
            ->withPivot('command_text', 'order');
    }

    public function editor()
    {
        return $this->hasOne(User::class, 'user_id', 'updated_by');
    }

    public function flag()
    {
        return $this->hasOne(BoardAsset::class, 'board_asset_id', 'flag_id');
    }

    public function thread()
    {
        return $this->belongsTo(static::class, 'reply_to', 'post_id');
    }

    public function thumbnails()
    {
        return $this->belongsToMany(FileStorage::class, 'post_attachments', 'post_id', 'thumbnail_id')
            ->withPivot('attachment_id', 'filename', 'is_spoiler', 'is_deleted', 'position')
            ->orderBy('pivot_position', 'asc');
    }

    public function replies()
    {
        return $this->hasMany(static::class, 'reply_to', 'post_id');
    }

    public function replyFiles()
    {
        return $this->hasManyThrough(PostAttachment::class, Post::class, 'reply_to', 'post_id');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'post_id');
    }


    /**
     * Counts the number of currently related reports that can be promoted.
     *
     * @param  \App\Contracts\Auth\Permittable  $user
     *
     * @return int
     */
    public function countReportsCanPromote(Permittable $user)
    {
        $count = 0;

        foreach ($this->reports as $report) {
            if ($user->can('promote', $report)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Counts the number of currently related reports that can be demoted.
     *
     * @param  \App\Contracts\Auth\Permittable  $user
     *
     * @return int
     */
    public function countReportsCanDemote(Permittable $user)
    {
        $count = 0;

        foreach ($this->reports as $report) {
            if ($user->can('demote', $report)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Checks a supplied password against the set one.
     *
     * @param string $password
     *
     * @return bool
     */
    public function checkPassword($password)
    {
        $hash = $this->makePassword($password, false);

        return !is_null($hash) && !is_null($this->password) && password_verify($hash, $this->password);
    }

    /**
     * Removes post caches.
     */
    public function forget()
    {
        Cache::tags(["post_{$this->post_id}"])->flush();
        Cache::tags([
            "board_{$this->board_uri}",
            "board_id_{$this->board_id}",
        ])->flush();
    }

    /**
     * Returns backlinks for this post which are permitted by board config.
     *
     * @param \App\Board|null $board Optional. Board to check against. If null, assumes this post's board.
     *
     * @return Collection of \App\PostCite
     */
    public function getAllowedBacklinks(Board $board = null)
    {
        if (is_null($board)) {
            $board = $this->board;
        }

        $backlinks = collect();

        foreach ($this->backlinks as $backlink) {
            if ($board->isBacklinkAllowed($backlink)) {
                $backlinks->push($backlink);
            }
        }

        return $backlinks;
    }

    /**
     * Returns a small, unique code to identify an author in one thread.
     *
     * @return string
     */
    public function makeAuthorId()
    {
        if ($this->author_ip === null) {
            return '000000';
        }

        $hashParts = [];
        $hashParts[] = config('app.key');
        $hashParts[] = $this->board_uri;
        $hashParts[] = $this->reply_to_board_id ?: $this->board_id;
        $hashParts[] = $this->author_ip;

        $hash = implode('-', $hashParts);
        $hash = hash('sha256', $hash);
        $hash = substr($hash, 12, 6);

        return $hash;
    }

    /**
     * Returns a SHA1 hash (in text or binary) representing an originality/r9k checksum.
     *
     * @static
     *
     * @param string $body   The body to be checksum'd.
     * @param bool   $binary Optional. If the return should be binary. Defaults false.
     *
     * @return string|binary
     */
    public static function makeChecksum($text, $binary = false)
    {
        $postRobot = preg_replace('/\s+/', '', $text);
        $checksum = sha1($postRobot, $binary);

        if ($binary) {
            return binary_sql($checksum);
        }

        return $checksum;
    }

    /**
     * Bcrypts a password using relative information.
     *
     * @param string $password The password to be set. If empty password is given, no password will be set.
     * @param bool   $encrypt  Optional. Indicates if the hash should be bcrypted. Defaults true.
     *
     * @return string
     */
    public function makePassword($password = null, $encrypt = true)
    {
        $hashParts = [];

        if ((bool) $password) {
            $hashParts[] = config('app.key');
            $hashParts[] = $this->board_uri;
            $hashParts[] = $password;
            $hashParts[] = $this->board_id;
        }

        $parts = implode('|', $hashParts);

        if ($encrypt) {
            return bcrypt($parts);
        }

        return $parts;
    }

    /**
     * Turns the author id into a consistent color.
     *
     * @param bool $asArray
     *
     * @return string In the format of rgb(xxx,xxx,xxx) or as an array.
     */
    public function getAuthorIdBackgroundColor($asArray = false)
    {
        $authorId = $this->author_id;
        $colors = [];
        $colors[] = crc32(substr($authorId, 0, 2)) % 254 + 1;
        $colors[] = crc32(substr($authorId, 2, 2)) % 254 + 1;
        $colors[] = crc32(substr($authorId, 4, 2)) % 254 + 1;

        if ($asArray) {
            return $colors;
        }

        return 'rgba('.implode(',', $colors).',0.75)';
    }

    /**
     * Takess the author id background color and determines if we need a white or black text color.
     *
     * @return string In the format of rgba(xxx,xxx,xxx,x)
     */
    public function getAuthorIdForegroundColor()
    {
        $colors = $this->getAuthorIdBackgroundColor(true);

        if (array_sum($colors) < 382) {
            return 'rgb(255,255,255)';
        }

        foreach ($colors as $color) {
            if ($color > 200) {
                return 'rgb(0,0,0)';
            }
        }

        return 'rgb(0,0,0)';
    }

    /**
     * Returns the raw input for a post for the JSON output.
     *
     * @return string
     */
    public function getAuthorIdAttribute()
    {
        if ($this->board->getConfig('postsThreadId', false)) {
            return $this->attributes['author_id'];
        }

        return;
    }

    /**
     * Language direction of this post.
     *
     * @return string|null
     */
    public function getBodyDirectionAttr()
    {
        $rtl = $this->body_rtl;

        if (is_null($rtl)) {
            return '';
        }

        return 'dir="'.($rtl ? 'rtl' : 'ltr').'"';
    }

    /**
     *
     *
     */
    public function getBodyExcerpt($length)
    {
        return substr($this->body, 0, $length);
    }

    /**
     * Returns the fully rendered HTML content of this post.
     *
     * @param bool $skipCache
     *
     * @return string
     */
    public function getBodyFormatted($skipCache = false)
    {
        if (!$skipCache) {
            // Markdown parsed content
            if (!is_null($this->body_html)) {
                if (!mb_check_encoding($this->body_html, 'UTF-8')) {
                    return '<tt style="color:red;">Invalid encoding. This should never happen!</tt>';
                }

                return $this->body_html;
            }

            // Raw HTML input
            if (!is_null($this->body_parsed)) {
                return $this->body_parsed;
            }
        }

        if ($this->board->getConfig('postsMarkdown')) {
            $ContentFormatter = new ContentFormatter();
            $this->body_too_long = false;
            $this->body_parsed = $ContentFormatter->formatPost($this);
            $this->body_parsed_preview = null;
            $this->body_parsed_at = $this->freshTimestamp();
            $this->body_has_content = $ContentFormatter->hasContent();
            $this->body_rtl = $ContentFormatter->isRtl();

            if (!mb_check_encoding($this->body_parsed, 'UTF-8')) {
                return '<tt style="color:red;">Invalid encoding. This should never happen!</tt>';
            }

            // If our body is too long, we need to pull the first X characters and do that instead.
            // We also set a token indicating this post has hidden content.
            if (mb_strlen($this->body) > 1200) {
                $this->body_too_long = true;
                $this->body_parsed_preview = $ContentFormatter->formatPost($this, 1000);
            }
        }
        else {
            $this->body_too_long = false;
            $this->body_parsed = str_replace(["\n"], "<br />", e($this->body));
            $this->body_parsed_preview = null;
            $this->body_parsed_at = $this->freshTimestamp();
            $this->body_has_content = !!strlen($this->body);
            $this->body_rtl = false;

            // If our body is too long, we need to pull the first X characters and do that instead.
            // We also set a token indicating this post has hidden content.
            if (mb_strlen($this->body) > 1200) {
                $this->body_too_long = true;
                $this->body_parsed_preview = $ContentFormatter->formatPost($this, 1000);
            }
        }

        // We use an update here instead of just saving $post because, in this method
        // there will frequently be additional properties on this object that cannot
        // be saved. To make life easier, we just touch the object.
        static::where(['post_id' => $this->post_id])->update([
            'body_has_content' => $this->body_has_content,
            'body_too_long' => $this->body_too_long,
            'body_parsed' => $this->body_parsed,
            'body_parsed_preview' => $this->body_parsed_preview,
            'body_parsed_at' => $this->body_parsed_at,
            'body_rtl' => $this->body_rtl,
        ]);

        return $this->body_parsed;
    }

    /**
     * Returns a partially rendered HTML preview of this post.
     *
     * @param bool $skipCache
     *
     * @return string
     */
    public function getBodyPreview($skipCache = false)
    {
        $body_parsed = $this->getBodyFormatted($skipCache);

        if ($this->body_too_long !== true || !isset($this->body_parsed_preview)) {
            return $body_parsed;
        }

        return $this->body_parsed_preview;
    }

    /**
     * Returns the raw input for a post for the JSON output.
     *
     * @return string
     */
    public function getContentRawAttribute($value)
    {
        if (!$this->trashed() && isset($this->attributes['body'])) {
            return $this->attributes['body'];
        }

        return;
    }

    /**
     * Returns the rendered interior HTML for a post for the JSON output.
     *
     * @return string
     */
    public function getContentHtmlAttribute($value)
    {
        if (!$this->trashed() && isset($this->attributes['body'])) {
            return $this->getBodyFormatted();
        }

        return;
    }

    /**
     * Returns a name for the country. This is usually the ISO 3166-1 alpha-2 code.
     *
     * @return string|null
     */
    public function getCountryCode()
    {
        if (!is_null($this->author_country)) {
            if ($this->author_country == '') {
                return 'unknown';
            }

            return $this->author_country;
        }

        return;
    }

    /**
     * Hacks until I figure something better out.
     */
    public $renderCatalog = false;
    public $renderMultiboard = false;
    public $renderPartial = false;

    /**
     * Useful for APIs.
     */
    public function getLockedAttribute()
    {
        return !is_null($this->locked_at);
    }

    /**
     * Returns the fully rendered HTML of a post in the JSON output.
     *
     * @return string
     */
    public function getHtmlAttribute()
    {
        if (!$this->trashed()) {
            return $this->toHtml(
                $this->renderCatalog,
                $this->renderMultiboard,
                $this->renderPartial
            );
        }

        return;
    }

    /**
     * Returns the recently created flag for the JSON output.
     *
     * @return string
     */
    public function getRecentlyCreatedAttribute()
    {
        return $this->wasRecentlyCreated;
    }

    /**
     * Returns a count of current reply relationships.
     *
     * @return int
     */
    public function getReplyCount()
    {
        return $this->getRelation('replies')->count();
    }

    /**
     * Returns a count of current reply relationships.
     *
     * @return int
     */
    public function getReplyFileCount()
    {
        $files = 0;

        foreach ($this->getRelation('replies') as $reply) {
            $files += $reply->getRelation('attachments')->count();
        }

        return $this->reply_file_count < $files ? $this->reply_file_count : max(0, $files);
    }

    /**
     * Returns a splice of the replies based on the 2channel style input.
     *
     * @param string $uri
     *
     * @return static|bool Returns $this with modified replies relationship, or false if input error.
     */
    public function getReplySplice($splice)
    {
        // Matches:
        // l50   OP and last 50 posts
        // l2    OP and last 2 posts
        // 600-  OP and all posts from 600 onwards
        // 10-20 OP and posts ten through twenty
        // 600   OP and post 600 only
        // -100  OP and first 100 posts
        // Indices start at 1, which includes OP.
        if (preg_match('/^(?<last>l)?(?<start>\d+)?(?P<between>-)?(?P<end>\d+)?$/', $splice, $m) === 1) {
            $count = $this->replies->count();
            $last = isset($m['last']) && $m['last'] == 'l' ? true : false;
            $start = isset($m['start']) && $m['start'] != '' ? (int) $m['start'] : false;
            $between = isset($m['between']) && $m['between'] == '-' ? true : false;
            $end = isset($m['end']) && $m['end'] != '' ? (int) $m['end']   : false;
            $length = null;

            // Fetching last posts?
            if ($last === true) {
                // Pull last X.
                if ($start !== false && $between == false && $end === false) {
                    $start = $count - $start;
                    $length = $count;
                } else {
                    return false;
                }
            }
            // Pull between two indices.
            elseif ($between === true) {
                // Have we specified an X-Y range?
                if ($start !== false && $end !== false) {
                    // Abort if we've specified an incorrect range.
                    if ($start <= 0 || $start > $end) {
                        return false;
                    }

                    $start -= 2;
                    $length = $end - $start - 1;
                }
                // Have we specified a -X (pull first X posts) range?
                elseif ($start === false && $end !== false) {
                    $start = 0;
                    $length = $end - 1;

                    if ($length < 0) {
                        return false;
                    }
                }
                // Have we specified a X- (pull from post X up) range?
                elseif ($start !== false && $end === false) {
                    $start -= 2;
                    $length = $count;
                } else {
                    return false;
                }
            }
            // Pull a single post.
            elseif ($start !== false) {
                if ($start > 1) {
                    $length = 1;
                }
                // If we're requesting OP, we want no children.
                elseif ($start == 1) {
                    $length = 0;
                } else {
                    return false;
                }
            } else {
                return false;
            }

            $start = max($start, 0);

            return $this->setRelation('replies', $this->replies->splice($start, $length));
        }

        return false;
    }

    public function getTimeSince()
    {
        $sec = $this->created_at->diffInSeconds();

        if ($sec > 172800) {
            return $this->created_at->diffInDays()."d";
        }
        elseif ($sec > 3600) {
            return $this->created_at->diffInHours()."h";

        }
        elseif ($sec > 60) {
            return $this->created_at->diffInMinutes()."m";
        }
        else {
            return $sec."s";
        }
    }

    public function getTripcodeHtml() : string
    {
        $html = "";

        if ($this->body_signed) {
            $html .= "<span class=\"tripcode tripcode-pgp\" title=\"{$this->tripcode}\">";
            $html .= "<span class=\"prints prints-leading\">";
            $prints =mb_str_split($this->tripcode, 4);

            foreach ($prints as $i => $print) {
                $html .= "<span class=\"print\">{$print}</span> ";

                if ($i + 2 == count($prints)) {
                    break; // break one early so we can use it last
                }
            }

            $html .= "</span>";
            $html .= "<span class=\"print\">" . $prints[$i+1] . "</span>";
            $html .= "</span>";
        }
        elseif ($this->tripcode) {
            if (mb_strlen($this->tripcode) > 10) {
                $html .= "<span class=\"tripcode tripcode-secure\">!!{$this->tripcode}</span>";
            }
            else {
                $html .= "<span class=\"tripcode tripcode-insecure\">!{$this->tripcode}</span>";
            }
        }

        return $html;
    }

    /**
     * Returns a relative URL for an API route to this post.
     *
     * @param string $route Optional route addendum.
     * @param array $params Optional array of parameters to be added.
     * @param bool $abs Options indicator if the URL is to be absolute.
     *
     * @return string
     */
    public function getApiUrl($route = "index", array $params = [], $abs = false)
    {
        if ($this->reply_to_board_id) {
            $url_id = $this->reply_to_board_id;
        } else {
            $url_id = $this->board_id;
        }

        return route(implode('.', array_filter(['api', 'board', $route,])), [
                'board'   => $this->board_uri,
                'post_id' => $url_id,
            ] + $params,
            $abs
        );
    }

    /**
     * Returns a relative URL for opening this post.
     *
     * @return string
     */
    public function getUrl($splice = null)
    {
        $url_hash = "";

        if ($this->reply_to_board_id) {
            $url_id = $this->reply_to_board_id;
            $url_hash = "#{$this->board_id}";
        }
        else {
            $url_id = $this->board_id;
        }

        return route('board.thread', [
            'board' => $this->board_uri,
            'post_id' => $url_id,
            'splice' => $splice,
        ], false) . $url_hash;
    }

    /**
     * Returns a relative URL for replying to this post.
     *
     * @return string
     */
    public function getReplyUrl($splice = null)
    {
        if ($this->reply_to_board_id) {
            $url_id = $this->reply_to_board_id;
        } else {
            $url_id = $this->board_id;
        }

        return route('board.thread', [
            'board' => $this->board_uri,
            'post_id' => $url_id,
            'splice' => $splice,
        ], false)."#reply-{$this->board_id}";
    }

    /**
     * Returns a post moderation URL for this post.
     *
     * @return string
     */
    public function getModUrl($route = "index", array $params = [], $abs = false)
    {
        return route(
            implode('.', array_filter([
                'board',
                'post',
                $route,
            ])),
            [
                'board'   => $this->attributes['board_uri'],
                'post_id' => $this->attributes['board_id'],
            ] + $params,
            $abs
        );
    }

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTime  $date
     *
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->timestamp;
    }

    /**
     * Determines if the post is made from the client's remote address.
     *
     * @return bool
     */
    public function isAuthoredByClient()
    {
        if (!isset($this->attributes['author_ip']) || is_null($this->attributes['author_ip'])) {
            return false;
        }

        return new IP($this->attributes['author_ip']) === new IP();
    }

    /**
     * Determines if this is a bumpless post.
     *
     * @return bool
     */
    public function isBumpless()
    {
        if ($this->attributes['email'] == 'sage') {
            return true;
        }

        return false;
    }

    /**
     * Determines if this thread cannot be bumped.
     *
     * @return bool
     */
    public function isBumplocked()
    {
        return isset($this->attributes['bumplocked_at']) && !!$this->attributes['bumplocked_at'];
    }

    /**
     * Determines if this is cyclic.
     *
     * @return bool
     */
    public function isCyclic()
    {
        return false;
    }

    /**
     * Determines if this is deleted.
     *
     * @return bool
     */
    public function isDeleted()
    {
        return isset($this->attributes['deleted_at']) && !!$this->attributes['deleted_at'];
    }

    /**
     * Determines if this is the first reply in a thread.
     *
     * @return bool
     */
    public function isOp()
    {
        return !isset($this->attributes['reply_to']) || is_null($this->attributes['reply_to']);
    }

    /**
     * Determines if this thread is locked.
     *
     * @return bool
     */
    public function isLocked()
    {
        return !is_null($this->locked_at);
    }

    /**
     * Determines if this thread is stickied.
     *
     * @return bool
     */
    public function isStickied()
    {
        return isset($this->attributes['stickied_at']) && !!$this->attributes['stickied_at'];
    }

    /**
     * Returns the author IP in a human-readable format.
     *
     * @return string
     */
    public function getAuthorIpAsString()
    {
        if ($this->hasAuthorIp()) {
            return $this->author_ip->toText();
        }

        return false;
    }

    /**
     * Returns author_ip as an instance of the support class.
     *
     * @return \App\Support\IP|null
     */
    public function getAuthorIpAttribute()
    {
        if (!isset($this->attributes['author_ip'])) {
            return;
        }

        if ($this->attributes['author_ip'] instanceof IP) {
            return $this->attributes['author_ip'];
        }

        $this->attributes['author_ip'] = new IP($this->attributes['author_ip']);

        return $this->attributes['author_ip'];
    }

    /**
     * Returns the bit size of the IP.
     *
     * @return int (32 or 128)
     */
    public function getAuthorIpBitSize()
    {
        if ($this->hasAuthorIp()) {
            return strpos($this->getAuthorIpAsString(), ':') === false ? 32 : 128;
        }

        return false;
    }

    /**
     * Returns a user-friendly list of ranges available for this IP.
     *
     * @return array
     */
    public function getAuthorIpRangeOptions()
    {
        $bitsize = $this->getAuthorIpBitSize();
        $range = range(0, $bitsize);
        $masks = [];

        foreach ($range as $mask) {
            $affectedIps = number_format(pow(2, $bitsize - $mask), 0);
            $masks[$mask] = trans_choice("board.ban.ip_range_{$bitsize}", $mask, [
                'mask' => $mask,
                'ips' => $affectedIps,
            ]);
        }

        return $masks;
    }

    /**
     * Returns the board model for this post.
     *
     * @return \App\Board
     */
    public function getBoard()
    {
        return $this->board()
            ->get()
            ->first();
    }

    /**
     * Returns a human-readable capcode string.
     *
     * @return string
     */
    public function getCapcodeName()
    {
        if ($this->capcode_capcode) {
            return trans_choice((string) $this->capcode_capcode, 0);
        } elseif ($this->capcode_id) {
            return $this->capcode->getCapcodeName();
        }

        return '';
    }

    /**
     * Parses the post text for citations.
     *
     * @return array
     */
    public function getCitesFromText()
    {
        return ContentFormatter::getCites($this);
    }

    /**
     * Parses the post text for dice throws.
     *
     * @return Collection
     */
    public function getDiceFromText()
    {
        return ContentFormatter::getDice($this);
    }

    /**
     * Returns a SHA1 checksum for this post's text.
     *
     * @param  bool Option. If return should be binary. Defaults false.
     *
     * @return string|binary
     */
    public function getChecksum($binary = false)
    {
        return $this->makeChecksum($this->body, $binary);
    }

    /**
     * Returns the last post made by this user across the entire site.
     *
     * @static
     *
     * @param string $ip
     *
     * @return \App\Post
     */
    public static function getLastPostForIP($ip = null)
    {
        if (is_null($ip)) {
            $ip = new IP();
        }

        return self::whereAuthorIP($ip)
            ->orderBy('created_at', 'desc')
            ->take(1)
            ->get()
            ->first();
    }

    /**
     * Returns the page on which this thread appears.
     * If the post is a reply, it will return the page it appears on in the thread, which is always 1.
     *
     * @return \App\Post
     */
    public function getPage()
    {
        if ($this->isOp()) {
            $board = $this->board()->with('settings')->get()->first();
            $visibleThreads = $board->threads()->thread()->where('bumped_last', '>=', $this->bumped_last)->count();
            $threadsPerPage = (int) $board->getConfig('postsPerPage', 10);

            return floor(($visibleThreads - 1) / $threadsPerPage) + 1;
        }

        return 1;
    }

    /**
     * Returns the post model for the most recently featured post.
     *
     * @static
     *
     * @param int $dayRange Optional. Number of days at most that the last most featured post can be in. Defaults 3.
     *
     * @return \App\Post
     */
    public static function getPostFeatured($dayRange = 3)
    {
        $oldestPossible = Carbon::now()->subDays($dayRange);

        return static::where('featured_at', '>=', $oldestPossible)
            ->withEverything()
            ->orderBy('featured_at', 'desc')
            ->first();
    }

    /**
     * Returns the post model using the board's URI and the post's local board ID.
     *
     * @static
     *
     * @param string $board_uri
     * @param int    $board_id
     *
     * @return \App\Post
     */
    public static function getPostForBoard($board_uri, $board_id)
    {
        return static::where([
                'board_uri' => $board_uri,
                'board_id' => $board_id,
            ])
            ->first();
    }

    /**
     * Returns the model for this post's original post (what it is a reply to).
     *
     * @return \App\Post
     */
    public function getOp()
    {
        return $this->thread()
            ->get()
            ->first();
    }

    /**
     * Returns a few posts for the front page.
     *
     * @static
     *
     * @param int  $number  How many to pull.
     * @param bool $sfwOnly If we only want SFW boards.
     *
     * @return \Illuminate\Database\Eloquent\Collection of Post
     */
    public static function getRecentPosts($number = 16, $sfwOnly = true)
    {
        return static::where('body_has_content', true)
            ->whereHas('board', function ($query) use ($sfwOnly) {
                $query->where('is_indexed', '=', true);
                $query->where('is_overboard', '=', true);

                if ($sfwOnly) {
                    $query->where('is_worksafe', '=', true);
                }
            })
            ->with('board')
            ->with(['board.assets' => function ($query) {
                $query->whereBoardIcon();
            }])
            ->limit($number)
            ->orderBy('post_id', 'desc')
            ->get();
    }

    /**
     * Returns the latest reply to a post.
     *
     * @return Post|null
     */
    public function getReplyLast()
    {
        return $this->replies()
            ->orderBy('post_id', 'desc')
            ->whereBump()
            ->first();
    }

    /**
     * Returns all replies to a post.
     *
     * @return \Illuminate\Database\Eloquent\Collection of Post
     */
    public function getReplies()
    {
        if (isset($this->replies)) {
            return $this->replies;
        }

        return $this->replies()
            ->withEverything()
            ->orderBy('post_id', 'asc')
            ->get();
    }

    /**
     * Returns the last few replies to a thread for index views.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRepliesForIndex()
    {
        return $this->replies()
            ->forIndex()
            ->get()
            ->reverse();
    }

    /**
     * Returns a set of posts for an update request.
     *
     * @static
     *
     * @param Carbon $sinceTime
     * @param Board  $board
     * @param Post   $thread
     * @param bool   $includeHTML If the posts should also have very large 'content_html' values.
     *
     * @return Collection of Posts
     */
    public static function getUpdates($sinceTime, Board $board, Post $thread, $includeHTML = false)
    {
        $posts = static::whereInUpdate($sinceTime, $board, $thread)->get();

        if ($includeHTML) {
            foreach ($posts as $post) {
                $post->setAppendHtml(true);
            }
        }

        return $posts;
    }

    /**
     * Returns if this post has an attached IP address.
     *
     * @return bool
     */
    public function hasAuthorIp()
    {
        return isset($this->attributes['author_ip']) && $this->attributes['author_ip'] !== null;
    }

    public function hasAttachments()
    {
        return $this->getRelation('attachments')->count() > 0;
    }

    /**
     * Determines if this post has a body message.
     *
     * @return bool
     */
    public function hasBody()
    {
        $body = false;
        $body_html = false;

        if (isset($this->attributes['body'])) {
            $body = strlen(trim((string) $this->attributes['body'])) > 0;
        }

        if (isset($this->attributes['body_html'])) {
            $body_html = strlen(trim((string) $this->attributes['body_html'])) > 0;
        }

        return $body || $body_html;
    }

    public function hasDetails()
    {
        return $this->attributes['author'] || $this->attributes['subject'];
    }

    /**
     * Get the appends attribute.
     * Not normally available to models, but required for API responses.
     *
     * @param array $appends
     *
     * @return array
     */
    public function getAppends()
    {
        return $this->appends;
    }

    /**
     * Pull threads for the overboard.
     *
     * @static
     *
     * @param int $page
     * @param bool|null $worksafe If we should only allow worksafe/nsfw.
     * @param array $include Boards to include.
     * @param array $exclude Boards to exclude.
     * @param bool $catalog Catalog view.
     * @param integer $updatedSince
     *
     * @return Collection of static
     */
    public static function getThreadsForOverboard($page = 0, $worksafe = null, array $include = [], array $exclude = [], $catalog = false, $updatedSince = null)
    {
        $postsPerPage = $catalog ? 150 : 10;
        $boards = [];
        $threads = static::whereHas('board', function ($query) use ($worksafe, $include, $exclude) {
            $query->where('is_indexed', true);
            $query->where('is_overboard', true);

            $query->where(function ($query) use ($worksafe, $include, $exclude) {
                $query->where(function ($query) use ($worksafe, $exclude) {
                    if (!is_null($worksafe)) {
                        $query->where('is_worksafe', $worksafe);
                    }
                    if (count($exclude)) {
                        $query->whereNotIn('boards.board_uri', $exclude);
                    }
                });

                if (count($include)) {
                    $query->orWhereIn('boards.board_uri', $include);
                }
            });
        })->thread();

        // Add replies
        $threads = $threads
            ->withEverythingAndReplies()
            ->with(['replies' => function ($query) use ($catalog) {
                if ($catalog) {
                    $query->where('body_has_content', true)->orderBy('post_id', 'desc')->limit(10);
                }
                else {
                    $query->forIndex();
                }
            }]);

        if ($updatedSince) {
            $threads->where('posts.bumped_last', '>', Carbon::createFromTimestamp($updatedSince));
        }

        $threads = $threads
            ->orderBy('bumped_last', 'desc')
            ->skip($postsPerPage * ($page - 1))
            ->take($postsPerPage)
            ->get();

        // The way that replies are fetched forIndex pulls them in reverse order.
        // Fix that.
        foreach ($threads as $thread) {
            if (!isset($boards[$thread->board_uri])) {
                $boards[$thread->board_uri] = Board::getBoardWithEverything($thread->board_uri);
            }

            $thread->setRelation('board', $boards[$thread->board_uri]);

            $replyTake = $thread->stickied_at ? 1 : 5;

            $thread->body_parsed = $thread->getBodyFormatted();
            $thread->replies = $thread->replies
                ->sortBy('post_id')
                ->splice(-$replyTake, $replyTake);

            $thread->replies->each(function($reply) use ($boards) {
                $reply->setRelation('board', $boards[$reply->board_uri]);
            });

            $thread->prepareForCache();
        }

        return $threads;
    }

    /**
     * Prepares a thread and its relationships for a complete cache.
     *
     * @return \App\Post
     */
    public function prepareForCache($board = null)
    {
        //# TODO ##
        // Find a better way to do this.
        // Call these methods so we typecast the IP as an IP class before
        // we invoke memory caching.
        $this->author_ip = new IP($this->author_ip);
        $board = $this->getRelation('board' ?: $this->load('board'));

        foreach ($this->replies as $reply) {
            $this->author_ip = new IP($this->author_ip);
            $reply->setRelation('board', $board);
        }

        return $this;
    }

    /**
     * Sets the value of $this->appends to the input.
     * Not normally available to models, but required for API responses.
     *
     * @param array $appends
     *
     * @return array
     */
    public function setAppends(array $appends)
    {
        return $this->appends = $appends;
    }

    /**
     * Quickly add html to the append list for this model.
     *
     * @param bool $add defaults true
     *
     * @return Post
     */
    public function setAppendHtml($add = true)
    {
        $appends = $this->getAppends();

        if ($add) {
            $appends[] = 'html';
        }
        elseif (($key = array_search('html', $appends)) !== false) {
            unset($appends[$key]);
        }

        $this->setAppends($appends);

        return $this;
    }

    /**
     * Stores author_ip as an instance of the support class.
     *
     * @param \App\Support\IP|string|null $value The IP to store.
     *
     * @return \App\Support\IP|null
     */
    public function setAuthorIpAttribute($value)
    {
        if (!is_null($value) && !is_binary($value)) {
            $value = new IP($value);
        }

        return $this->attributes['author_ip'] = $value;
    }

    /**
     * Sets the bumplock property timestamp.
     *
     * @param bool $bumplock
     *
     * @return \App\Post
     */
    public function setBumplock($bumplock = true)
    {
        if ($bumplock) {
            $this->bumplocked_at = $this->freshTimestamp();
        } else {
            $this->bumplocked_at = null;
        }

        return $this;
    }

    /**
     * Sets the deleted timestamp.
     *
     * @param bool $delete
     *
     * @return \App\Post
     */
    public function setDeleted($delete = true)
    {
        if ($delete) {
            $this->deleted_at = $this->freshTimestamp();
        } else {
            $this->deleted_at = null;
        }

        return $this;
    }

    /**
     * Sets the locked property timestamp.
     *
     * @param bool $lock
     *
     * @return \App\Post
     */
    public function setLocked($lock = true)
    {
        if ($lock) {
            $this->locked_at = $this->freshTimestamp();
        } else {
            $this->locked_at = null;
        }

        return $this;
    }

    /**
     * Sets the sticky property of a post and updates relevant timestamps.
     *
     * @param bool $sticky
     *
     * @return \App\Post
     */
    public function setSticky($sticky = true)
    {
        if ($sticky) {
            $this->stickied = true;
            $this->stickied_at = $this->freshTimestamp();
        } else {
            $this->stickied = false;
            $this->stickied_at = null;
        }

        return $this;
    }

    public function scopeAndAttachments($query)
    {
        return $query->with(['attachments' => function ($eagerQuery) {
            $eagerQuery->orderBy('position')->with('file', 'thumbnail');
        }]);
    }

    public function scopeAndBacklinks($query)
    {
        return $query->with([
            'backlinks' => function ($query) {
                $query->has('post');
                $query->orderBy('post_id', 'asc');
            },
            'backlinks.post' => function ($query) {
                $query->select('post_id', 'board_uri', 'board_id', 'reply_to', 'reply_to_board_id');
            },
        ]);
    }

    public function scopeAndBoard($query)
    {
        return $query->with('board');
    }

    public function scopeAndBans($query)
    {
        return $query->with(['bans' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }]);
    }

    public function scopeAndCapcode($query)
    {
        return $query
            ->leftJoin('roles', function ($join) {
                $join->on('roles.role_id', '=', 'posts.capcode_id');
            })
            ->addSelect(
                'roles.capcode as capcode_capcode',
                'roles.role as capcode_role',
                'roles.name as capcode_name'
            );
    }

    public function scopeAndCites($query)
    {
        return $query->with('cites', 'cites.cite');
    }

    public function scopeAndDice($query)
    {
        return $query->with(['dice' => function ($query) {
            $query->orderBy('post_dice.order', 'desc');
        }]);
    }

    public function scopeAndEditor($query)
    {
        return $query
            ->leftJoin('users', function ($join) {
                $join->on('users.user_id', '=', 'posts.updated_by');
            })
            ->addSelect(
                'users.username as updated_by_username'
            );
    }

    public function scopeAndFlag($query)
    {
        return $query->with('flag');
    }

    public function scopeAndFirstAttachment($query)
    {
        return $query->with(['attachments' => function ($query) {
            $query->limit(1);
        }]);
    }

    public function scopeAndReplies($query)
    {
        return $query->with(['replies' => function ($query) {
            $query->withEverything();
        }]);
    }

    public function scopeAndPromotedReports($query)
    {
        return $query->with(['reports' => function ($query) {
            $query->whereOpen();
            $query->wherePromoted();
        }]);
    }

    public function scopeWhereAuthorIP($query, $ip)
    {
        $ip = new IP($ip);

        return $query->where('author_ip', $ip->toText());
    }

    public function scopeWhereBump($query)
    {
        return $query->where('email', '<>', "sage");
    }

    public function scopeWhereBumpless($query)
    {
        return $query->where('email', "sage");
    }

    public function scopeIpString($query, $ip)
    {
        return $query->whereAuthorIP($ip);
    }

    public function scopeIpBinary($query, $ip)
    {
        return $query->whereAuthorIP($ip);
    }

    public function scopeThread($query)
    {
        return $query->where('reply_to', null);
    }

    public function scopeRecent($query)
    {
        return $query->where('created_at', '>=', static::freshTimestamp()->subDay());
    }

    public function scopeForIndex($query)
    {
        return $query->withEverythingForReplies()
            ->orderBy('post_id', 'desc')
            ->takePerGroup('reply_to', 5);
    }

    public function scopeReplyTo($query, $replies = false)
    {
        if ($replies instanceof \Illuminate\Database\Eloquent\Collection) {
            $thread_ids = [];

            foreach ($replies as $thread) {
                $thread_ids[] = (int) $thread->post_id;
            }

            return $query->whereIn('reply_to', $thread_ids);
        } elseif (is_numeric($replies)) {
            return $query->where('reply_to', '=', $replies);
        } else {
            return $query->where('reply_to', 'not', null);
        }
    }

    public function scopeWithEverything($query)
    {
        return $query
            ->withEverythingForReplies()
            ->andBoard();
    }

    public function scopeWithEverythingAndReplies($query)
    {
        return $query
            ->withEverything()
            ->with(['replies' => function ($query) {
                $query->withEverythingForReplies();
                $query->orderBy('board_id', 'asc');
            }]);
    }

    public function scopeWithEverythingForReplies($query)
    {
        return $query
            ->addSelect('posts.*')
            ->andAttachments()
            ->andBans()
            ->andBacklinks()
            ->andCapcode()
            ->andCites()
            ->andDice()
            ->andEditor()
            ->andFlag()
            ->andPromotedReports();
    }

    public function scopeWhereHasReports($query)
    {
        return $query->whereHas('reports', function ($query) {
            $query->whereOpen();
        });
    }

    public function scopeWhereHasReportsFor($query, Permittable $user)
    {
        return $query->whereHas('reports', function ($query) use ($user) {
            $query->whereOpen();
            $query->whereResponsibleFor($user);
        })
            ->with(['reports' => function ($query) use ($user) {
                $query->whereOpen();
                $query->whereResponsibleFor($user);
            }]);
    }

    public function scopeWhereInThread($query, Post $thread)
    {
        if ($thread->attributes['reply_to_board_id']) {
            return $query->where(function ($query) use ($thread) {
                $query->where('board_id', $thread->attributes['reply_to_board_id']);
                $query->orWhere('reply_to_board_id', $thread->attributes['reply_to_board_id']);
            });
        } else {
            return $query->where(function ($query) use ($thread) {
                $query->where('board_id', $thread->attributes['board_id']);
                $query->orWhere('reply_to_board_id', $thread->attributes['board_id']);
            });
        }
    }

    /**
     * Logic for pulling posts for API updates.
     *
     * @param DbQuery $query     Provided by Laravel.
     * @param Board   $board
     * @param Carbon  $sinceTime
     * @param Post    $thread    Board ID.
     *
     * @return $query
     */
    public function scopeWhereInUpdate($query, $sinceTime, Board $board, Post $thread)
    {
        // Find posts in this board.
        return $query->where('posts.board_uri', $board->board_uri)
            // Include deleted posts.
            ->withTrashed()
            // Only pull posts in this thread, or that is this thread.
            ->where(function ($query) use ($thread) {
                $query->where('posts.reply_to_board_id', $thread->board_id);
                $query->orWhere('posts.board_id', $thread->board_id);
            })
            // Nab posts that've been updated since our sinceTime.
            ->where(function ($query) use ($sinceTime) {
                $query->where('posts.updated_at', '>', $sinceTime);
                $query->orWhere('posts.deleted_at', '>', $sinceTime);
            })
            // Fetch accessory tables too.
            ->withEverything()
            // Order by board id in reverse order (so they appear in the thread right).
            ->orderBy('posts.board_id', 'asc');
    }

    /**
     *Renders a single post.
     *
     * @return  string  HTML
     */
    public function toHtml($catalog = false, $multiboard = false, $preview = false) : string
    {
        if ($this->isDeleted()) {
            return "";
        }

        $user = user();
        $this->load('board');

        $rememberTags = [
            "board_{$this->board_uri}",
            "post_{$this->post_id}",
            "post_html",
        ];
        $rememberTimer = now()->addDay();
        $rememberKey = "board.{$this->board_uri}.post_html.{$this->board_id}";
        $rememberClosure = function () use ($catalog, $multiboard, $preview, $user) {
            $this->setRelation('attachments', $this->attachments);

            foreach ($this->attachments as $attachment) {
                $attachment->setRelation('post', $this);
                $attachment->setRelation('board', $this->getRelation('board'));
            }

            return view($catalog ? 'content.board.catalog' : 'content.board.post', [
                // Models
                'board' => $this->board,
                'post' => $this,
                'user' => $user,

                // Statuses
                'catalog' => $catalog,
                'reply_to' => $this->reply_to ?? false,
                'multiboard' => $multiboard,
                'preview' => $preview,
            ])->render();
        };

        if ($catalog) {
            $rememberKey .= ".catalog";
            $rememberTags[] = 'catalog_post';
        }
        if ($multiboard) {
            $rememberKey .= ".multiboard";
            $rememberTags[] = 'multiboard_post';
        }
        if ($preview) {
            $rememberKey .= ".preview";
            $rememberTags[] = 'preview_post';
        }
        // This is a pure form of the post we want to keep for a long time.
        if (!$catalog && !$multiboard && !$preview) {
            $rememberTimer = $rememberTimer->addWeek();
        }

        return Cache::tags($rememberTags)->remember($rememberKey, $rememberTimer, $rememberClosure);
    }

    public function jsonSerialize()
    {
        $json = parent::jsonSerialize();

        $json['attachments'] = $json['attachments'] ?? $this->attachments;

        if ($this->isOp()) {
            $json['replies'] = $json['replies'] ?? $this->replies;
        }

        return $json;
    }

    /**
     * Sends a redirect to the post's page.
     *
     * @param string $action
     *
     * @return Response
     */
    public function redirect($action = null)
    {
        return redirect($this->getUrl($action));
    }

    /**
     * Returns a thread with its replies for a thread view and leverages cache.
     *
     * @static
     *
     * @param string $board_uri Board primary key.
     * @param int    $board_id  Local board id.
     * @param string $uri       Optional. URI string for splicing the thread. Defaults to null, for no splicing.
     *
     * @return static
     */
    public static function getForThreadView($board_uri, $board_id, $uri = null)
    {
        // Prepare the board so that we do not have to make redundant searches.
        $board = null;

        if ($board_uri instanceof Board) {
            $board = $board_uri;
            $board_uri = $board->board_uri;
        }
        else {
            $board = $this->board;
        }

        $thread = static::where([
            'posts.board_uri' => $board_uri,
            'posts.board_id' => $board_id,
        ])->withEverythingAndReplies()->first();

        if ($thread) {
            $thread->setRelation('board', $board);
            $thread->setRelation('attachments', $thread->attachments);
        }

        return $thread;
    }
}
