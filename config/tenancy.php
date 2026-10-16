<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform super admin
    |--------------------------------------------------------------------------
    |
    | Used by SuperAdminSeeder to create the first platform administrator
    | and the company they start working in.
    |
    */

    'super_admin' => [
        'name' => env('SUPERADMIN_NAME', 'Super Admin'),
        'email' => env('SUPERADMIN_EMAIL', 'admin@pagebuilder.test'),
        'password' => env('SUPERADMIN_PASSWORD', 'password'),
        'company' => env('SUPERADMIN_COMPANY', 'Main Company'),
    ],

];
