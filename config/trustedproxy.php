<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Proxies whose X-Forwarded-* headers may be trusted. Left unset, no proxy is
    | trusted: the client IP is always REMOTE_ADDR, so a forged header cannot
    | move the login limiter to a different bucket.
    |
    | Set TRUSTED_PROXIES to the comma-separated IPs of the proxies you control
    | when the application runs behind one.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
