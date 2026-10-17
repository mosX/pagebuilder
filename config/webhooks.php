<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outgoing requests of webhooks and automations
    |--------------------------------------------------------------------------
    |
    | Requests to private and reserved networks (localhost, 10.x, 192.168.x, cloud
    | metadata, …) are blocked to prevent SSRF. Allow them only for local testing.
    |
    */

    'allow_private' => (bool) env('WEBHOOKS_ALLOW_PRIVATE', false),

    'timeout' => 10,

];
