<?php

namespace App\Microsoft;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Turns a successful Entra sign-in into a local user.
 *
 * Who may sign in at all is enforced by Entra: the enterprise app requires assignment and only
 * the ClickUp-Intake group is assigned (docs/01-decisions.md E3). Here we additionally pin the
 * tenant and key users by their immutable object id, never by e-mail.
 */
class MicrosoftLogin
{
    public function __construct(private MicrosoftApp $app) {}

    /**
     * @param  array<string, mixed>  $claims  ID token claims
     */
    public function handle(
        array $claims,
        string $name,
        string $upn,
        ?string $mail,
        #[SensitiveParameter] ?string $refreshToken,
    ): User {
        $tenantId = $this->app->tenantId();

        if (blank($tenantId) || Str::lower((string) ($claims['tid'] ?? '')) !== $tenantId) {
            throw new LoginRejected('Diese Anmeldung gehört nicht zum GUYCOLLE-Tenant.');
        }

        $objectId = $claims['oid'] ?? null;

        if (blank($objectId)) {
            throw new LoginRejected('Microsoft hat keine Benutzerkennung geliefert.');
        }

        try {
            return $this->upsert($objectId, $name, $upn, $mail, $refreshToken);
        } catch (UniqueConstraintViolationException) {
            // Two parallel first sign-ins (double click, two tabs): the other request created the
            // row in the meantime. A second pass finds and updates it.
            return $this->upsert($objectId, $name, $upn, $mail, $refreshToken);
        }
    }

    private function upsert(
        string $objectId,
        string $name,
        string $upn,
        ?string $mail,
        #[SensitiveParameter] ?string $refreshToken,
    ): User {
        $user = User::query()->firstOrNew(['entra_object_id' => $objectId]);

        if ($user->exists && ! $user->active) {
            throw new LoginRejected('Dein Zugang ist deaktiviert. Bitte wende dich an einen Admin.');
        }

        if (! $user->exists) {
            $user->role = Role::Member;
            $user->active = true;
        }

        $user->fill([
            'name' => $name,
            'email' => $mail ?: $upn,
            'mailbox_upn' => $upn,
        ]);

        // Without a refresh token the background poll cannot run for this user.
        if (filled($refreshToken)) {
            $user->ms_refresh_token = $refreshToken;
            $user->ms_access_token = null;
            $user->ms_token_expires_at = null;
            $user->ms_connection_status = ConnectionStatus::Connected;
            $user->ms_connected_at = now();
        }

        $user->last_login_at = now();
        $user->save();

        return $user;
    }
}
