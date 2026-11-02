<?php

return [

    // Times are stored in UTC and shown in this timezone.
    'display_timezone' => 'Europe/Zurich',

    'support_address' => env('INTAKE_SUPPORT_ADDRESS'),

    'attachment_max_mb' => (int) env('INTAKE_ATTACHMENT_MAX_MB', 50),

    'retention_days' => 30,

    // Finished items whose Outlook category never synced are deleted after this many days
    // anyway (docs/01-decisions.md E7).
    'retention_hard_limit_days' => 60,

    'microsoft' => [
        // Tenant, client id and secret are not configured here: the setup assistant stores them
        // encrypted in `settings` (docs/01-decisions.md E1, R11c).

        // Delegated scopes, granted once by admin consent for all users (docs/01-decisions.md E1).
        'scopes' => [
            'openid',
            'profile',
            'email',
            'offline_access',
            'User.Read',
            'Mail.ReadWrite',
            'Mail.Send',
            'MailboxSettings.ReadWrite',
        ],
    ],

];
