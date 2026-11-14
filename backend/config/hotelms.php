<?php

return [

    /*
    | Proxy or CDN in front of the server (see App\Support\TrustedProxies and docs/hosting.md). Empty = none.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    // A mail server typed in Super Admin may be inside the server's own network only when this is true (SSRF, L5).
    'mail_allow_private_host' => (bool) env('MAIL_ALLOW_PRIVATE_HOST', false),

    /*
    | The platform owner account, created by SuperAdminSeeder on first start.
    | The password is temporary: it must be changed at first sign-in.
    | Leave the password empty in production to get a random one.
    */
    // Default of the "two-step sign-in for admins" switch until the Super Admin changes it (tests turn it off).
    'two_factor_admins' => (bool) env('HOTELMS_TWO_FACTOR_ADMINS', true),
    // The HotelMS team must always use two-step sign-in (owner Q1, 14 Nov). Not a switch in the app: only for
    // development and tests (HOTELMS_TWO_FACTOR_TEAM=false). Never set it on a live server.
    'two_factor_team' => (bool) env('HOTELMS_TWO_FACTOR_TEAM', true),

    // How long a second change to the same booking waits for the first one (OneAtATime middleware).
    'lock_wait_seconds' => (int) env('HOTELMS_LOCK_WAIT_SECONDS', 10),

    // Database backups (php artisan hotelms:backup). Keep the folder outside public_html.
    'backups' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'copy_path' => env('BACKUP_COPY_PATH'), // second location (e.g. a mounted disk elsewhere in Ethiopia)
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        // Backups are encrypted with this key (php artisan hotelms:backup-key). NOT the APP_KEY: keep a copy
        // in your password manager, away from the server. Without it, backups cannot be opened.
        'key' => env('BACKUP_KEY'),
    ],

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@hotelms.test'),
        'password' => env('SUPER_ADMIN_PASSWORD', ''),
    ],

    /*
    | Super Admin team roles (claude/super-admin.md). The owner always has
    | everything. These are the defaults; the Owner changes them on the Team screen
    | (saved as the platform setting "platform_permissions").
    */
    // Developer documentation shown in Super Admin → Developer docs (default: docs/ next to the app, or ../docs).
    'docs_path' => env('DOCS_PATH'),
    // Deployment guide PDFs (Super Admin → Developer docs). Empty = deploy-guide/pdf next to the app.
    'deploy_guide_path' => env('DEPLOY_GUIDE_PATH'),

    'platform_roles' => ['owner', 'support', 'sales', 'finance', 'tech'],

    'platform_permissions' => [
        'hotels.view' => ['support', 'sales', 'finance', 'tech'],
        'hotels.create' => ['sales'],
        'hotels.impersonate' => ['support', 'tech'],
        'users.reset_password' => ['support', 'sales', 'tech'],
        'billing.manage' => ['finance'],
        'switches.manage' => ['tech'],
        'support.answer' => ['support', 'sales', 'tech'],
        'system.view' => ['tech'],
        'releases.manage' => ['tech'],
        'content.manage' => ['support'],
        'watchdog.view' => ['tech'], // security, bug and data-leak warnings
        'docs.view' => ['tech'], // the developer documentation (docs/ folder)
        'team.manage' => [],
    ],

];
