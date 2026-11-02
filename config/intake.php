<?php

return [

    // Times are stored in UTC and shown in this timezone.
    'display_timezone' => 'Europe/Zurich',

    'support_address' => env('INTAKE_SUPPORT_ADDRESS'),

    'attachment_max_mb' => (int) env('INTAKE_ATTACHMENT_MAX_MB', 50),

    'retention_days' => 30,

    'microsoft' => [
        // Fallback for a manual app registration. The setup assistant stores these in `settings`.
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),

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
