<?php

namespace App\Microsoft\Setup;

use RuntimeException;
use SensitiveParameter;

/**
 * The setup admin's access token plus identity from the ID token. The ID token comes straight
 * from the token endpoint over TLS, so its signature is not checked (OIDC Core 3.1.3.7).
 */
final readonly class AdminToken
{
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        public string $tenantId,
        public string $objectId,
        public string $name,
        public string $upn,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromTokenResponse(#[SensitiveParameter] array $response): self
    {
        $parts = explode('.', (string) ($response['id_token'] ?? ''));
        $claims = count($parts) === 3
            ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true)
            : null;

        if (! is_array($claims) || empty($response['access_token']) || empty($claims['tid']) || empty($claims['oid'])) {
            throw new RuntimeException('Microsoft hat eine unvollständige Anmeldung geliefert.');
        }

        return new self(
            accessToken: $response['access_token'],
            tenantId: strtolower($claims['tid']),
            objectId: $claims['oid'],
            name: $claims['name'] ?? $claims['preferred_username'] ?? 'Admin',
            upn: $claims['preferred_username'] ?? '',
        );
    }
}
