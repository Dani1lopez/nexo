<?php

/**
 * Hashing policy, explicit instead of inherited from the framework defaults:
 * bcrypt at cost 12, and an outdated hash is upgraded on the next successful login.
 * argon2id is intentionally not the default here: the demo must run on any PHP build,
 * and bcrypt needs no optional extension. Passwords are never stored in clear: the
 * User model hashes them through this configuration on every write.
 */
return [

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    // Rehash an outdated hash with the current cost as soon as the password is known.
    'rehash_on_login' => true,

];
