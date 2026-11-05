<?php

return [

    /*
    | The platform owner account, created by SuperAdminSeeder on first start.
    | The password is temporary: it must be changed at first sign-in.
    | Leave the password empty in production to get a random one.
    */
    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@hotelms.test'),
        'password' => env('SUPER_ADMIN_PASSWORD', ''),
    ],

    /*
    | Super Admin team roles (claude/super-admin.md). The owner always has
    | everything. A later phase makes this editable on screen.
    */
    'platform_roles' => ['owner', 'support', 'sales', 'finance', 'tech'],

    'platform_permissions' => [
        'hotels.view' => ['support', 'sales', 'finance', 'tech'],
        'hotels.create' => ['sales'],
        'hotels.impersonate' => ['support', 'tech'],
        'users.reset_password' => ['support', 'sales', 'tech'],
        'billing.manage' => ['finance'],
        'switches.manage' => ['tech'],
        'team.manage' => [],
    ],

];
