<?php

return [

    // Times are stored in UTC and shown in this timezone.
    'display_timezone' => 'Europe/Zurich',

    'support_address' => env('INTAKE_SUPPORT_ADDRESS'),

    'attachment_max_mb' => (int) env('INTAKE_ATTACHMENT_MAX_MB', 50),

    // Sum of all attachments of one mail. Attachments beyond it are not downloaded (E8).
    'item_max_mb' => (int) env('INTAKE_ITEM_MAX_MB', 100),

    // Below this free space on the attachment disk, the poll imports nothing (E8).
    'min_free_disk_mb' => (int) env('INTAKE_MIN_FREE_DISK_MB', 1024),

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
