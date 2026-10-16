<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media library disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk for files uploaded by companies. "public" serves them from
    | /storage (run `php artisan storage:link`); switch to "s3" in production.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

];
