<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Cache;
use Settings;

class PostAttachment extends Model
{
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'post_attachments';

    /**
     * The database primary key.
     *
     * @var string
     */
    protected $primaryKey = 'attachment_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'post_id',
        'file_id',
        'thumbnail_id',
        'filename',
        'is_spoiler',
        'is_deleted',
        'position',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * Indicates if Laravel should set created_at and updated_at timestamps.
     *
     * @var array
     */
    public $timestamps = false;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var array
     */
    public $incrementing = false;

    /**
     * Attributes which do not exist but should be appended to the JSON output.
     *
     * @var array
     */
    protected $appends = [
        'thumbnail_url',
        'file_url',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'board',
        'post',
    ];

     /**
      * Cheeky static to store default Settings::get('attachmentName') after first call
      *
      * @var string
      */
     public static $nameFormat;

    public function board()
    {
        return $this->hasOneThrough(Board::class, Post::class, 'post_id', 'board_uri', 'post_id', 'board_uri');
    }

    public function post()
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function file()
    {
        return $this->belongsTo(FileStorage::class, 'file_id');
    }

    public function thumbnail()
    {
        return $this->belongsTo(FileStorage::class, 'thumbnail_id');
    }

    /**
     * Ties database triggers to the model.
     *
     * @static
     */
    public static function boot()
    {
        parent::boot();

        // Setup event bindings...

        // Fire events on post created.
        static::created(function (PostAttachment $attachment) {
            if (!is_link($attachment->file->getFullPath())) {
                $attachment->file->upload_count += 1;
                $attachment->file->save();
            }
        });
    }

    /**
     * Supplies a download name.
     *
     * @return string
     */
    public function getDownloadName()
    {
        return $this->getFormattedName() . "." . $this->file->guessExtension();
        //return pathinfo($this->attributes['filename'], PATHINFO_FILENAME) . "." . $this->file->guessExtension();
    }

    /**
     * Returns the attachment's extension.
     *
     * @return string
     */
    public function getExtension()
    {
        return pathinfo($this->attributes['filename'], PATHINFO_EXTENSION );
    }

    public function getFileUrlAttribute()
    {
        if ($this->isDeleted()) {
            return $this->board->getAssetUrl('file_deleted');
        }

        return $this->getUrl();
    }

    /**
     * Returns a filename that is compliant with the site configuration.
     *
     * @return string
     */
    public function getFormattedName($thumbnail = false)
    {
        $nameFormat = static::$nameFormat;

        // Get admin settings for filename format.
        if (is_null(static::$nameFormat)) {
            static::$nameFormat = $nameFormat = Settings::get('attachmentName');
        }

        $bits['t'] = $this->post->created_at->timestamp;
        $bits['i'] = 0;
        $bits['n'] = $bits['t'];

        if (isset($this->attributes['position'])) {
            $bits['i'] = $this->attributes['position'];
        }

        if (isset($this->attributes['filename'])) {
            $bits['n'] = pathinfo($this->attributes['filename'], PATHINFO_FILENAME);
        }

        $attachmentName = $nameFormat;

        foreach ($bits as $bitKey => $bitVal) {
            $attachmentName = str_replace("%{$bitKey}", $bitVal, $attachmentName);
        }

        if ($thumbnail) {
            $attachmentName .= "s";
        }

        return $attachmentName;
    }

    /**
     * Returns a few posts for the front page.
     *
     * @static
     *
     * @param int  $number  How many to pull.
     * @param bool $sfwOnly If we only want SFW boards.
     *
     * @return Collection of static
     */
    public static function getRecentImages($number = 16)
    {
        $sfw = static::getRecentImagesByWorksafe($number, true);
        return $sfw->sortByDesc('attachment_id');
        //$nsfw = static::getRecentImagesByWorksafe($number, false);
        //return $sfw->merge($nsfw)->sortByDesc('attachment_id');
    }

    protected static function getRecentImagesByWorksafe($number = 16, $sfw = false)
    {
        $query = static::where('is_spoiler', false)
            ->where('is_deleted', false)
            ->whereHas('file', function ($query) {
                $timestamp = now()->subMinutes(5);
                $query->whereHas('sourcePivots');
                $query->where('last_uploaded_at', '<=', $timestamp);
                //$query->whereDate('last_uploaded_at', '<=', $timestamp);
                //$query->whereTime('last_uploaded_at', '<=', $timestamp);
                $query->groupBy('file_id');
            })
            ->whereHas('post.board', function ($query) use ($sfw) {
                $query->where('is_indexed', '=', true);
                $query->where('is_overboard', '=', true);
                $query->where('is_worksafe', '=', $sfw);
            })
            ->with('file', 'thumbnail', 'post.board')
            ->take($number);

        if ($query->getQuery()->getConnection() instanceof \Illuminate\Database\PostgresConnection) {
            // PostgreSQL does not support the MySQL standards non-compliant group_by syntax.
            // DISTINCT itself selects distinct combinations [attachment_id,file_id], not just file_id.
            // We have to use raw SQL to accomplish this.
            // $query->select(
            //     \DB::raw('distinct on (file_id) *')
            // );

            $query->orderBy('file_id', 'desc');
            //$query->groupBy('file_id');
        } else {
            $query->orderBy('attachment_id', 'desc');
            $query->groupBy('file_id');
        }

        return $query->get();
    }

    /**
     * Returns a removal URL.
     *
     * @param \App\Board $board
     *
     * @return string
     */
    public function getRemoveUrl()
    {
        return route('board.file.delete', [
            'attachment' => $this->attributes['attachment_id'],
            'board' => $this->post->attributes['board_uri'],
        ], false);
    }

    /**
     * Truncates the middle of a filename to show extension.
     *
     * @return string Filename.
     */
    public function getShortFilename()
    {
        $filename = urldecode($this->filename);

        if (mb_strlen($filename) <= 20) {
            return $filename;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = mb_substr($name, 0, 15);

        return "{$name}... .{$ext}";
    }

    /**
     * Returns a spoiler URL.
     *
     * @param \App\Board $board
     *
     * @return string
     */
    public function getSpoilerUrl()
    {
        return route('board.file.spoiler', [
            'attachment' => $this->attributes['attachment_id'],
            'board' => $this->post->attributes['board_uri'],
        ], false);
    }

    public function getThumbnailUrlAttribute()
    {
        return $this->getThumbnailUrl();
    }

    /**
     * Returns an unspoiler URL.
     *
     * @param \App\Board $board
     *
     * @return string
     */
    public function getUnspoilerUrl()
    {
        return route('board.file.unspoiler', [
            'attachment' => $this->attributes['attachment_id'],
            'board' => $this->post->attributes['board_uri'],
        ], false);
    }

    public function isDeleted()
    {
        return !!$this->attributes['is_deleted'] || is_null($this->file);
    }

    /**
     * Returns an XML valid attachment HTML string that handles missing thumbnail URLs.
     *
     * @param  null|string|int  $maxWidth Optional. Maximum width constraint. Accepts 'auto'. Defaults null.
     *
     * @return string as HTML
     */
    public function toHtml($maxDimension = null)
    {
        $board = $this->board;
        $file = null;
        $thumbnail = null;
        $deleted = $this->isDeleted();
        $spoiler = !!$this->attributes['is_spoiler'];

        if ($deleted) {
            $url = $board->getAssetUrl('file_deleted');
        }
        else {
            $file = $this->file;
            $ext = $file->guessExtension();
            $url = media_url("static/img/filetypes/{$ext}.svg", false);
            $thumbnail = $this->thumbnail;

            if ($spoiler) {
                $url = $board->getAssetUrl('file_spoiler');
            }
            elseif ($this->file->isImageVector()) {
                $url = $this->getUrl();
            }
            elseif ($file->isAudio() || $file->isImage() || $file->isVideo() || $file->isDocument()) {
                if ($thumbnail instanceof FileStorage) {
                    $url = $this->getThumbnailUrl();
                }
                elseif ($file->isAudio()) {
                    $url = media_url("static/img/assets/audio.gif", false);
                }
            }
        }

        $classes = $deleted ? null : $file->getHtmlClasses();
        $hash = $deleted ? null : $file->attributes['hash'];
        $mime = $deleted ? null : $file->attributes['mime'];

        // Measure dimensions.
        $height = 'auto';
        $width = 'auto';
        $maxHeight = $maxDimension ? "{$maxDimension}px" : "none";
        $maxWidth = $maxDimension ? "{$maxDimension}px" : "none";
        $oHeight = $thumbnail ? $thumbnail->attributes['file_height'] : Settings::get('attachmentThumbnailSize', 200);
        $oWidth = $thumbnail ? $thumbnail->attributes['file_width'] : Settings::get('attachmentThumbnailSize', 200);

        if (!is_int($maxDimension)) {
            $maxDimension = max($oHeight, $oWidth);
        }

        // configuration for an actual thumbnail image
        if ($thumbnail instanceof FileStorage && !$spoiler && !$deleted) {
            $height = $oHeight.'px';
            $width = $oWidth.'px';

            if ($maxDimension == "auto") {
                $height = "auto";
                $width = "auto";
            }
            else {
                if ($oWidth > $oHeight) {
                    $height = 'auto';
                    $width = $maxDimension.'px';
                }
                elseif ($oWidth < $oHeight) {
                    $height = $maxDimension.'px';
                    $width = 'auto';
                }
                else {
                    $height = 'auto';
                    $width = 'auto';
                }
            }
        }
        // board assets and placeholder file extension images
        else {
            $width = $maxDimension ? "{$maxDimension}px" : Settings::get('attachmentThumbnailSize', 200).'px';
            $height = 'auto';
        }

        return "<div class=\"attachment-wrapper\">" .
            "<img class=\"attachment-img {$classes}\" src=\"{$url}\" data-mime=\"{$mime}\" data-sha256=\"{$hash}\" style=\"height: {$height}; width: {$width}; max-height: {$maxHeight}; max-width: {$maxWidth};\"/>" .
        "</div>";
    }

    /**
     * Supplies a filename for thumbnails.
     *
     * @return string
     */
    public function getThumbnailName()
    {
        return $this->getFormattedName(true) . "." . $this->thumbnail->guessExtension();
    }

    /**
     * Supplies a clean URL for downloading an attachment on a board.
     *
     * @return string
     */
    public function getThumbnailUrl()
    {
        if ($this->isDeleted()) {
            return $this->board->getAssetUrl('file_deleted');
        }

        if ($this->attributes['is_spoiler']) {
            return $this->board->getSpoilerUrl();
        }
        else if ($this->attributes['is_deleted'] || is_null($this->attributes['thumbnail_id'])) {
            return;
        }

        $params = [
            'hash' => $this->thumbnail->attributes['hash'],
            'filename' => $this->getThumbnailName(),
        ];

        if (!config('app.url_media', false)) {
            $params['board'] = $this->post->attributes['board_uri'];
        }

        return route('static.file.hash', $params, false);
    }
    /**
     * Supplies a clean URL for downloading an attachment on a board.
     *
     * @return string
     */
    public function getUrl()
    {
        $params = [
            'hash' => $this->file->attributes['hash'],
            'filename' => $this->getDownloadName(),
        ];

        if (!config('app.url_media', false)) {
            $params['board'] = $this->post->attributes['board_uri'];
        }

        return route('static.file.hash', $params, config('app.url_media', false));
    }

    public function scopeWhereForBoard($query, Board $board)
    {
        return $query->whereHas('post.board', function ($query) use ($board) {
            $query->where('board_uri', $board->board_uri);
        });
    }
}
