<?php

namespace App\Auth;

use App\Ban;
use App\Board;
use App\Option;
use App\Permission;
use App\Post;
use App\Role;
use App\RoleCache;
use App\Support\IP\IP;
use App\Support\IP\CIDR;
use Illuminate\Database\Eloquent\Collection;
use Request;
use Cache;

// note: I am trying to move all permission logic out of this trait and into proper gates/policies.
trait Permittable
{
    /**
     * Accountability is the property which determines if a user is high-risk.
     * These users inherit different permissions and limitations to prevent
     * attacks that involve illegal material if set to false.
     *
     * @var bool
     */
     protected $accountable = true;

    /**
     * The $permission array is an associative set of permissions.
     * Each key represents a board. NULL as a key means global scope.
     * If a value is not set, it should be implicitly false.
     *
     * @var array
     */
     protected $permissions;

    /**
     * Getter for the $accountable property.
     *
     * @return bool
     */
    public function isAccountable()
    {
        if (!is_bool($this->accountable)) {
            $this->accountable = true;
        }

        return $this->accountable;
    }

    public function setAccountable($accountable) {
        $this->accountable = !!$accountable;
        return $this->accountable;
    }

    /**
     * Getter for the $anonymous property.
     * Distinguishes this model from an Anonymous user.
     * Applied on the model, not the trait.
     *
     * @return bool
     */
    public function isAnonymous()
    {
        return $this->anonymous;
    }

    /**
     * Uses flexible argument options to challenge a permission/board
     * combination against the user's permission mask.
     *
     * @param string $permission The permission ID we're checking for.
     * @param  \App\Board|\App\Post|string|null  Optional. Board, Post, board_uri string, or NULL. If NULL, checks only global permissions.
     *
     * @return bool
     */
    public function permission($permission, $board = null)
    {
        if ($permission instanceof Permission) {
            $permission = $permission->permission_id;
        }

        if ($board instanceof Board || $board instanceof Post) {
            $board = $board->board_uri;
        }
        elseif (!is_string($board)) {
            $board = null;
        }

        return $this->getPermission($permission, $board);
    }

    /**
     * Accepts a permission and checks if *any* board allows it.
     *
     * @return bool
     */
    public function permissionAny($permission)
    {
        if ($this->permission($permission)) {
            return true;
        }

        if (!($permission instanceof Permission)) {
            $permission = Permission::findOrFail($permission);
        }

        $boards = $permission->getBoardsWithPermissions($this, true);

        foreach ($boards as $board_uri) {
            if ($this->getPermission($permission, $board_uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a list of direct, extant Board URIs where this permission exists.
     * The goal of this is to weed out loose permissions provided by global permissions.
     *
     * @return array (of board_uri)
     */
    public function canInBoards($permission)
    {
        $boards = [];

        if (!($permission instanceof Permission)) {
            $permission = Permission::find($permission);
            if (!($permission instanceof Permission) || !$permission->exists) {
                throw new \InvalidArgumentException("Permission does not exist.");
            }
        }

        $boardsWithRights = $permission->getBoardsWithPermissions($this, false);
        $boards = [];

        foreach ($boardsWithRights as $board_uri) {
            if (is_null($board_uri) || strlen($board_uri) === 0) {
                continue;
            }

            if ($this->getPermission($permission, $board_uri)) {
                $boards[] = $board_uri;
            }
        }

        return $boards;
    }

    /**
     * Can this user administrate ANY board?
     *
     * @return bool
     */
    public function canAdminBoards()
    {
        return $this->permission('sys.boards');
    }

    /**
     * Can this user clear the system cache?
     *
     * @return bool
     */
    public function canAdminCache()
    {
        return $this->permission('sys.cache');
    }

    /**
     * Can this user administrate the system logs?
     *
     * @return bool
     */
    public function canAdminLogs()
    {
        return $this->permission('sys.logs');
    }

    /**
     * Can this user administrate groups and their permissions?
     *
     * @return bool
     */
    public function canAdminRoles()
    {
        return $this->permission('sys.roles');
    }

    /**
     * Can this user administrate the system config?
     *
     * @return bool
     */
    public function canAdminPayments()
    {
        return $this->permission('sys.payments');
    }

    /**
     * Can this user administrate the system config?
     *
     * @return bool
     */
    public function canAdminPermissions()
    {
        return $this->permission('sys.');
    }

    /**
     * Can this user delete on this board?
     *
     * @return bool
     */
    public function canDeleteLocally(Board $board)
    {
        return $this->permission('board.post.delete.other', $board);
    }

    /**
     * Can this user delete on this board with a password?
     *
     * @return bool
     */
    public function canDeletePostWithPassword(Board $board)
    {
        return $this->permission('board.post.delete.self', $board);
    }

    /**
     * Can edit a post with a password?
     *
     * @param \App\Board $board
     *
     * @return bool
     */
    public function canEditPostWithPassword(Board $board)
    {
        return $this->permission('board.post.edit.self', $board);
    }

    /**
     * Can this user edit this staff member on this board?
     *
     * @return bool
     */
    public function canEditBoardStaffMember(PermissionUserContract $user, Board $board)
    {
        if ($this->canAdminConfig()) {
            return true;
        }

        if ($user->user_id == $this->user_id) {
            return false;
        }

        return $this->permission('board.config', $board);
    }

    /**
     * Can this user edit a board's URI?s.
     *
     * @return bool
     */
    public function canEditBoardUri(Board $board)
    {
        return false;
    }

    /**
     * Can this user post in locked threads?
     *
     * @return bool
     */
    public function canPostInLockedThreads(Board $board = null)
    {
        return $this->permission('board.post.lock_bypass', $board);
    }

    /**
     * Can this user post a new reply to an existing thread.
     *
     * @return bool
     */
    public function canPostReply(Board $board = null)
    {
        return $this->permission('board.post.create.reply', $board);
    }

    /**
     * Returns a list of boards that this user can manage ban appeals in.
     *
     * @return array of board URis.
     */
    public function canManageAppealsIn()
    {
        return $this->canInBoards('board.user.unban');
    }

    /**
     * Can remove attachments from post with password?
     *
     * @param \App\Board $board
     *
     * @return bool
     */
    public function canRemoveAttachmentWithPassword(Board $board)
    {
        return $this->permission('board.image.delete.self', $board);
    }

    /**
     * Can this user delete on this board?
     *
     * @return bool
     */
    public function canSpoilerAttachmentLocally(Board $board)
    {
        return $this->permission('board.image.spoiler.other', $board);
    }

    /**
     * Can spoiler/unspoiler attachments from post with password?
     *
     * @param \App\Board $board
     *
     * @return bool
     */
    public function canSpoilerAttachmentWithPassword(Board $board)
    {
        return $this->permission('board.image.spoiler.self', $board);
    }

    /**
     * Can this user delete posts across the entire site?
     *
     * @return bool
     */
    public function canSpoilerAttachmentGlobally()
    {
        return $this->permission('board.image.spoiler.other');
    }

    /**
     * Can this user view this ban?
     *
     * @param  \App\Ban The ban we're checking to see if we can view.
     *
     * @return bool
     */
    public function canViewBan(Ban $ban)
    {
        return $this->permission('board.bans', $ban->board_uri);
    }

    /**
     * Can this user see another's raw IP?
     *
     * @return bool
     */
    public function canViewUnindexedBoards()
    {
        return $this->permission('site.board.view_unindexed');
    }

    /**
     * Can this user view a board setting lock?
     *
     * @param \App\Board  $board  Board which this setting belongs to.
     * @param \App\Option $option Option, usually with BoardSetting data embedded, that is being checked.
     *
     * @return bool
     */
    public function canViewSettingLock(Board $board, Option $option)
    {
        return $option->isLocked() || $this->canEditSettingLock($board, $option);
    }

    /**
     * Drops the user's permission cache.
     */
    public function forgetPermissions()
    {
        // Delete all cache rows
        RoleCache::where('user_id', $this->isAnonymous() ? null : $this->user_id)
            ->delete();

        Cache::tags("user.{$this->user_id}")->flush();
    }

    /**
     * Returns a complete list of roles that this user may delegate to others.
     *
     * @param Board|null $board If not null, will refine search to a single board.
     *
     * @return Collection|array
     */
    public function getAssignableRoles(Board $board = null)
    {
        return $board->roles()
            ->where(function ($query) use ($board) {
                if (is_null($board) && $this->canAdminRoles()) {
                    $query->whereStaff();
                }
                elseif ($this->permission('board.user.role', $board)) {
                    $query->whereJanitor();
                }
            })
            ->get();
    }

    public function getBodyClassesAttribute()
    {
        $classes = [];
        $classes[] = $this->can('be-accountable') ? 'can-be-accountable' : 'can-not-be-accountable';
        $classes[] = $this->can('global-ban') ? 'can-global-ban' : 'can-not-global-ban';
        $classes[] = $this->can('global-delete') ? 'can-global-delete' : 'can-not-global-delete';
        $classes[] = $this->can('global-history') ? 'can-global-history' : 'can-not-global-history';
        $classes[] = $this->can('global-report') ? 'can-global-report' : 'can-not-global-report';
        $classes[] = $this->can('global-feature') ? 'can-global-feature' : 'can-not-global-feature';
        $classes[] = $this->can('global-bumplock') ? 'can-global-bumplock' : 'can-not-global-bumplock';
        $classes[] = $this->can('ban-file') ? 'can-ban-file' : 'can-not-ban-file';

        return implode(" ", $classes);
    }

    /**
     * Returns a list of board_uris where the canEditConfig permission is given.
     *
     * @return Collection of Board
     */
    public function getBoardsWithAssetRights()
    {
        return $this->getBoardsWithConfigRights();
    }

    /**
     * Returns a list of board_uris where the canEditConfig permission is given.
     *
     * @return Collection of Board
     */
    public function getBoardsWithConfigRights()
    {
        if ($this->isAnonymous()) {
            return collect();
        }

        $whitelist = true;
        $boardlist = [];

        if ($this->permission('board.config')) {
            $whitelist = false;
        }

        $boardlist = $this->canInBoards('board.config');
        $boardlist = array_unique($boardlist);

        if ($whitelist && empty($boardlist)) {
            return collect();
        }


        return Board::where(function ($query) use ($whitelist, $boardlist) {
            if ($whitelist && !in_array(null, $boardlist)) {
                $query->whereIn('board_uri', $boardlist);
            }
        })
            ->andAssets()
            ->andCreator()
            ->andOperator()
            ->andStaffAssignments()
            ->paginate(25);
    }

    /**
     * Returns a list of board_uris where the canEditConfig permission is given.
     *
     * @return Collection of Board
     */
    public function getBoardsWithStaffRights()
    {
        return $this->getBoardsWithConfigRights();
    }


    /**
     * Gets the user's roles with capcodes for this board.
     * A capcode is a text colum associated with a role.
     *
     * @param \App\Board $board
     *
     * @return array|Collection
     */
    public function getCapcodes(Board $board)
    {
        if (!$this->isAnonymous()) {
            // Only return roles
            return $this->roles->filter(function ($role) use ($board) {
                if (!$role->capcode) {
                    return false;
                }

                if (is_null($role->board_uri) || $role->board_uri == $board->board_uri) {
                    return true;
                }
            });
        }

        return collect([]);
    }

    /**
     * Determine the user's permission for a specific item.
     *
     * @param string      $permission The permission ID we are checking.
     * @param string|null $board_uri  The board URI we're checking against. NULL means global only.
     *
     * @return bool
     */
    protected function getPermission($permission, $board_uri = null)
    {
        if ($permission instanceof Permission) {
            $permission = $permission->permission_id;
        }

        $boardPermissions = $this->getPermissionsForBoard($board_uri);
        $globalPermissions = $this->getPermissionsForBoard();

        // Check for a board permisison.
        if (isset($boardPermissions[$permission])) {
            return $boardPermissions[$permission];
        }
        // Check for a global permission.
        elseif (isset($globalPermissions[$permission])) {
            return $globalPermissions[$permission];
        }

        // Assume false if not explicitly set.
        return false;
    }

    /**
     * Returns permissions for global+board belonging to our current route.
     *
     * @param string|null $board_uri
     *
     * @return array
     */
    public function getPermissionsForBoard($board_uri = null)
    {
        // Default permission mask is normal.
        $mask = 'normal';

        // If the user is from Tor, they are instead unaccountable.
        if (!$this->isAccountable()) {
            $mask = 'unaccountable';
        }

        return $this->getPermissionsWithRoutes($board_uri, $mask);
    }

    /**
     * Returns permission masks for each route.
     * This is where permissions are interpreted.
     *
     * @param string|null $board_uri
     *
     * @return array
     */
    protected function getPermissionMask($board_uri = null)
    {
        // Get our routes.
        $routes = $this->getPermissionRoutes();

        // Build a route name to empty array relationship.
        $permissions = array_combine(
            array_keys($routes),
            array_map(function ($n) {
                return [];
            },
        $routes));

        // There are two kinds of permission assignments.
        // 1. Permissions that belong to the route.
        // 2. Permissions directly assigned to the user.

        // When a permission is a part of a major mask branch (identified in getPermissionRoutes),
        // then any role with that role name becomes a part of the mask.

        // When a permission is directly assigned to the user, then only that mask and its
        // inherited mask are incorporated. Inheritance only goes up one step for right now.

        $allGroups = [];

        // Pull each route and add its groups to the master collection.
        foreach ($routes as $branch => $roleGroups) {
            $allGroups = array_merge($allGroups, $roleGroups);
        }

        // We only want uniques.
        $allGroups = array_unique($allGroups);

        // In order to determine if we want to include a role in a specific mask,
        // we must also pull a user's roles to see what is directly applied to them.
        $userRoles = !$this->isAnonymous() ? $this->roles->modelKeys() : [];

        $parentRoles = Role::where('system', true)->with('permissions')->get()->getDictionary();

        // Write out a monster query to pull precisely what we need to build our permission masks.
        $query = Role::where(function ($query) use ($board_uri, $allGroups) {
            $query->where(function ($query) use ($board_uri) {
                $query->orWhereNull('board_uri');
                $query->orWhere('board_uri', $board_uri);
            });

            // Pull any role that belongs to our masks's route.
            $query->whereIn('roles.role', $allGroups);

            // If we're not anonymous, we also need directly assigned roles.
            if (!$this->isAnonymous()) {
                $query->orWhereHas('users', function ($query) {
                    //$query->where( \DB::raw("`user_roles`.`user_id`"), $this->user_id);
                    $query->where('user_roles.user_id', !$this->isAnonymous() ? $this->user_id : null);
                });
            }
            else {
                $query->whereDoesntHave('users');
            }
        });

        // Gather our inherited roles, their permissions, and our permissions.
        $query->with('permissions');

        $query->orderBy('weight');

        // Gather our inherited roles, their permissions, and our permissions.
        // Execute query
        $query->chunk(100, function ($roles) use ($routes, $parentRoles, $userRoles, &$permissions) {
            RoleCache::addRolesToPermissions($roles, $routes, $parentRoles, $userRoles, $permissions);
        });

        return $permissions;
    }

    /**
     * Returns a complete array of all possible routes and what roles belong to them.
     *
     * @return array
     */
    protected function getPermissionRoutes()
    {
        // When building a permission mask, there are two main branches we can take.
        // "Normal", and "Unaccountable".

        // When the permission mask is finalized, it will still have these two branches.
        // But, depending on the user's conditions, it may have alternate routes within.

        // This is set up with the hope that we will be easily able to change how permission
        // masks are build in the future. Keep in mind that the masks's individual weights
        // still matter when determining what the user can actually do.

        $routes = [
            'normal' => [],
            'unaccountable' => [],
        ];

        // Both branches base off anonymous.
        $routes['normal'][] = 'anonymous';
        $routes['unaccountable'][] = 'anonymous';

        // The unaccountable branch uses a special role.
        // This would generally be for Tor users.
        $routes['unaccountable'][] = 'unaccountable';

        // Finally, if the user is registered, we add another role.
        // This is a bit of a placeholder. There are no permissions
        // by default that only affect registered users.
        if (!$this->isAnonymous()) {
            $routes['normal'][] = 'registered';
            $routes['unaccountable'][] = 'registered';
        }

        // All users are beholden to the absolute role.
        $routes['normal'][] = 'absolute';
        $routes['unaccountable'][] = 'absolute';

        return $routes;
    }

    /**
     * Return the user's entire permission object,
     * build it if nessecary.
     *
     * @param string $board_uri
     * @param string $route
     *
     * @return array
     */
    protected function getPermissionsWithRoutes($board_uri = null, $route = null)
    {
        if (!isset($this->permissions)) {
            $this->permissions = [];
        }

        if (!isset($this->permissions[$route][$board_uri])) {
            $cache = RoleCache::firstOrNew([
                'user_id' => !$this->isAnonymous() ? $this->user_id : null,
                'board_uri' => is_null($board_uri)  ? null : $board_uri,
            ]);

            if (!$cache->exists) {
                $value = $this->getPermissionMask($board_uri);
                $cache->value = json_encode($value);
                $cache->save();
            }
            else {
                $value = json_decode($cache->value, true);
            }

            $this->permissions = array_merge_recursive($this->permissions, $value);

            if (!isset($this->permissions[$route][$board_uri])) {
                $this->permissions[$route][$board_uri] = [];
            }
        }

        if (!is_null($route)) {
            if (isset($this->permissions[$route][$board_uri])) {
                return $this->permissions[$route][$board_uri];
            }

            return [];
        }

        return $this->permissions;
    }


    /**
     * Returns the name of the user that should be displayed in public.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return $this->isAnonymous() ? trans('board.anonymous') : $this->username;
    }

    /**
     * Returns a human-readable username HTML string with a profile link.
     *
     * @return string HTML
     */
    public function getUsernameHTML()
    {
        if ($this->isAnonymous()) {
            return '<span class="username">'.Lang::get('board.anonymous').'</span>';
        }

        return "<a href=\"{$this->getUrl()}\" class=\"username\">{$this->username}</a>";
    }

    /**
     * Returns a human-readable IP address based on user permissions.
     * This will obfuscate it if we do not have permission to view raw IPs.
     *
     * @param string|CIDR $ip Normal IP string or a CIDR support object.
     *
     * @return string Either $ip or an ip_less version.
     */
    public function getTextForIP($ip)
    {
        if ($this->can('ip-address')) {
            return (string) $ip;
        }

        if (($ip instanceof IP || $ip instanceof CIDR) && $ip->getStart() != $ip->getEnd()) {
            return ip_less($ip->getStart()).'/'.$ip->getPrefix();
        }


        return ip_less($ip);
    }
}
