<?php

namespace App\Microsoft\Setup;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Admin sign-in for the setup via the OAuth device code flow.
 *
 * Uses Microsoft's public client "Microsoft Graph Command Line Tools", the same one
 * Connect-MgGraph uses, because our own app registration does not exist yet. The token is
 * short-lived, used for this one run and never stored (E1, R11a).
 */
class DeviceCodeLogin
{
    public const CLIENT_ID = '14d82eec-204b-4c2f-b7e8-296a70dab67e';

    private const AUTHORITY = 'https://login.microsoftonline.com/organizations/oauth2/v2.0';

    public const SCOPES = [
        'openid',
        'profile',
        'https://graph.microsoft.com/Application.ReadWrite.All',
        'https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All',
    ];

    /**
     * @param  Closure(string $message): void  $showMessage  prints Microsoft's sign-in instruction
     */
    public function signIn(Closure $showMessage): AdminToken
    {
        $code = Http::asForm()->timeout(30)->post(self::AUTHORITY.'/devicecode', [
            'client_id' => self::CLIENT_ID,
            'scope' => implode(' ', self::SCOPES),
        ]);

        if (! $code->successful()) {
            throw new RuntimeException('Microsoft hat keinen Gerätecode ausgestellt ('.$code->json('error', $code->status()).').');
        }

        $showMessage((string) $code->json('message'));

        $interval = max(1, (int) $code->json('interval', 5));
        $deadline = now()->addSeconds((int) $code->json('expires_in', 900));

        while (now()->lessThan($deadline)) {
            Sleep::for($interval)->seconds();

            $token = Http::asForm()->timeout(30)->post(self::AUTHORITY.'/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => self::CLIENT_ID,
                'device_code' => $code->json('device_code'),
            ]);

            if ($token->successful()) {
                return AdminToken::fromTokenResponse($token->json());
            }

            match ($token->json('error')) {
                'authorization_pending' => null,
                'slow_down' => $interval += 5,
                'authorization_declined' => throw new RuntimeException('Die Anmeldung wurde abgelehnt.'),
                'expired_token' => throw new RuntimeException('Der Gerätecode ist abgelaufen.'),
                default => throw new RuntimeException('Anmeldung fehlgeschlagen ('.$token->json('error', $token->status()).').'),
            };
        }

        throw new RuntimeException('Der Gerätecode ist abgelaufen.');
    }
}
