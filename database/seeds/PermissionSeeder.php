<?php

use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| Adding Permissions
|--------------------------------------------------------------------------
|
| Adding permissions is easy!
| Keep in min all new permission values default to false.
|
| 1. Add a unique token to the slugs() function of the PermissionSeeder.
| 2. If you want it to appear in the UI, place it in a 'permissions' array
|        in one of the groups of the PermissionGroupSeeder.
| 3. (Opt) If you intend for it to be true by default on specific groups,
|        also open the RoleSeeder.php file and add it to the RolePermissionSeeder.
| 4. Run the artisan command `db:seed` and `cache:clear`.
|
*/

use App\Permission;

class PermissionSeeder extends Seeder {

    public function run()
    {
        $this->command->info("Seeding permissions.");

        $permission_count = Permission::count();

        foreach ($this->slugs() as $slug)
        {
            Permission::firstOrCreate([
                'permission_id' => $slug
            ]);
        }

        $permission_count = Permission::count() - $permission_count;

        $this->command->info("Done. Seeded {$permission_count} new permission(s).");
    }

    private function slugs()
    {
        return [
            "board.config",
            "board.bans",
            "board.logs",
            "board.create",
            "board.create.banned",
            "board.delete",
            "board.history",
            "board.reassign",
            "board.reports",
            "board.attachment.upload",
            "board.attachment.delete.self",
            "board.attachment.delete.other",
            "board.attachment.spoiler.upload",
            "board.attachment.spoiler.self",
            "board.attachment.spoiler.other",
            "board.post.create.thread",
            "board.post.create.reply",
            "board.post.delete.self",
            "board.post.delete.other",
            "board.post.edit.self",
            "board.post.edit.other",
            "board.post.sticky",
            "board.post.lock",
            "board.post.bumplock",
            "board.post.suppress",
            "board.post.lock_bypass",
            "board.post.report",
            "board.user.ban.reason",
            "board.user.ban.free",
            "board.user.role",
            "board.user.unban",

            "site.attachment.create",
            "site.attachment.ban",
            "site.board.feature",
            "site.board.view_unindexed",
            "site.board.setting_lock",
            "site.pm",
            "site.post.report",
            "site.profile.edit.other",
            "site.profile.edit.self",
            "site.profile.view",
            "site.user.create",
            "site.user.merge",
            "site.user.raw_ip",
            "site.reports",

            "sys.boards",
            "sys.cache",
            "sys.config",
            "sys.logs",
            "sys.nocaptcha",
            "sys.roles",
            "sys.payments",
            "sys.permissions",
            "sys.tools",
            "sys.users",

        ];
    }
}


use App\PermissionGroup;
use App\PermissionGroupAssignment;

class PermissionGroupSeeder extends Seeder {

    public function run()
    {
        $this->command->info('Seeding permission groups and relationships.');

        PermissionGroupAssignment::truncate();

        foreach ($this->slugs() as $slug)
        {
            $permissionGroupPermissionModels = $slug['permissions'];
            unset($slug['permissions']);

            $permissionGroup = PermissionGroup::firstOrNew([
                'group_name' => $slug['group_name'],
            ]);

            $permissionGroup->group_name      = $slug['group_name'];
            $permissionGroup->display_order   = $slug['display_order'];
            $permissionGroup->is_system_only  = !!(isset($slug['is_system_only']) ? $slug['is_system_only'] : false);
            $permissionGroup->is_account_only = !!(isset($slug['is_account_only']) ? $slug['is_account_only'] : false);

            $permissionGroup->save();

            foreach ($permissionGroupPermissionModels as $permissionGroupIndex => $permissionGroupPermission)
            {
                $permissionGroupPermissionModel = $permissionGroup
                    ->assignments()
                    ->firstOrNew([
                        'permission_id' => $permissionGroupPermission,
                    ]);

                $permissionGroupPermissionModel->display_order = $permissionGroupIndex * 10;
                $permissionGroupPermissionModel->save();

                $permissionGroupPermissionModels[] = $permissionGroupPermissionModel;
            }
        }
    }

    private function slugs()
    {
        return [
            [
                'group_name'    => "board_controls",
                'display_order' => 100,

                'permissions'   => [
                    "board.config",
                    "board.bans",
                    "board.logs",
                    "board.delete",
                    "board.reassign",
                ],
            ],
            [
                'group_name'    => "board_images",
                'display_order' => 200,

                'permissions'   => [
                    "board.attachment.upload",
                    "board.attachment.delete.self",
                    "board.attachment.delete.other",
                    "board.attachment.spoiler.upload",
                    "board.attachment.spoiler.self",
                    "board.attachment.spoiler.other",
                ],
            ],
            [
                'group_name'    => "board_posts",
                'display_order' => 300,

                'permissions'   => [
                    "board.post.create.thread",
                    "board.post.create.reply",
                    "board.post.delete.other",
                    "board.post.delete.self",
                    "board.post.edit.self",
                    "board.post.edit.other",
                    "board.post.report",
                ],
            ],
            [
                'group_name'    => "board_moderation",
                'display_order' => 350,
                'permissions'   => [
                    "board.post.sticky",
                    "board.post.lock",
                    "board.post.bumplock",
                    "board.reports",
                    "board.history",
                ],
            ],
            [
                'group_name'    => "board_users",
                'display_order' => 400,

                'permissions'   => [
                    "board.user.ban.free",
                    "board.user.ban.reason",
                    "board.user.role",
                    "board.user.unban",
                ],
            ],

            [
                'group_name'    => "site_tools",
                'is_system_only'=> true,
                'display_order' => 500,

                'permissions'   => [
                    "board.create",
                    "board.create.banned",
                    "site.attachment.create",
                    "site.attachment.ban",
                    "site.board.feature",
                    "site.board.view_unindexed",
                    "site.pm",
                    "site.post.report",
                    "site.user.create",
                    "site.user.merge",
                    "site.user.raw_ip",
                    "site.reports",
                ],
            ],
            [
                'group_name'    => "system_tools",
                'is_system_only'=> true,
                'display_order' => 600,

                'permissions'   => [
                    "sys.boards",
                    "sys.cache",
                    "sys.config",
                    "sys.logs",
                    "sys.roles",
                    "sys.nocaptcha",
                    "sys.payments",
                    "sys.permissions",
                    "sys.tools",
                    "sys.users",
                ],
            ],
        ];
    }
}
