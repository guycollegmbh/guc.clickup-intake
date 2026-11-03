<?php

namespace App\Microsoft\Setup;

use Carbon\CarbonImmutable;
use SensitiveParameter;

final readonly class Registration
{
    public function __construct(
        public string $tenantId,
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        public string $secretKeyId,
        public CarbonImmutable $secretExpiresAt,
        public string $appObjectId,
        public string $servicePrincipalId,
    ) {}
}
