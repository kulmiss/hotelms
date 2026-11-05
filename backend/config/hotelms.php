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

];
