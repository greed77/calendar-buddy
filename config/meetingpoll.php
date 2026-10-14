<?php

return [

    /*
    | Only Google accounts on this Workspace domain may sign in. People
    | outside the domain can still answer polls through their invite link.
    */
    'allowed_domain' => env('ALLOWED_GOOGLE_DOMAIN', 'example.com'),

    /*
    | Extra individual Google accounts (e.g. a personal Gmail) allowed to sign
    | in for local testing. Comma-separated. Ignored unless APP_ENV=local.
    */
    'dev_allowed_emails' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('DEV_ALLOWED_GOOGLE_EMAILS', '')),
    ))),

    /*
    | Hours shown on the slot picker grid, in the creator's local time.
    */
    'grid_start_hour' => 7,
    'grid_end_hour' => 19,

    'durations' => [15, 30, 45, 60, 90, 120],

];
