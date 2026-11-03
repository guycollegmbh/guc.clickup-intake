<?php

namespace App\Http\Controllers;

use App\ClickUp\ClickUpApp;
use App\ClickUp\ClickUpConnectFailed;
use App\ClickUp\UserClickUp;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Mit ClickUp verbinden" per user (E2): OAuth with a state bound to the session, the token is
 * stored by UserClickUp only after the workspace check. Results are shown as panel
 * notifications on the page the user came from.
 */
class ClickUpConnectionController extends Controller
{
    private const STATE_KEY = 'clickup_oauth_state';

    private const RETURN_KEY = 'clickup_oauth_return';

    public function __construct(private ClickUpApp $app, private UserClickUp $clickUp) {}

    public function connect(Request $request): RedirectResponse
    {
        $user = self::user($request);

        if (! $this->app->hasCredentials() || ($this->app->teamId() === null && ! $user->isAdmin())) {
            return $this->fail('/', 'ClickUp ist noch nicht eingerichtet. Bitte einen Admin fragen.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);
        $request->session()->put(self::RETURN_KEY, $this->returnUrl());

        return redirect()->away($this->app->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $return = (string) $request->session()->pull(self::RETURN_KEY, '/');
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->fail($return, 'Die Verbindung mit ClickUp ist abgelaufen. Bitte erneut versuchen.');
        }

        if (! is_string($code) || $code === '') {
            return $this->fail($return, 'ClickUp hat die Verbindung nicht freigegeben.');
        }

        try {
            $this->clickUp->connect(self::user($request), $code);
        } catch (ClickUpConnectFailed $e) {
            return $this->fail($return, $e->getMessage());
        }

        Notification::make()->title('ClickUp ist verbunden.')->success()->send();

        return redirect($return);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->clickUp->disconnect(self::user($request));

        // ClickUp tokens do not expire: the authorization in ClickUp stays until it is removed there.
        Notification::make()
            ->title('ClickUp ist getrennt.')
            ->body(ClickUpApp::REVOKE_HINT)
            ->success()
            ->persistent()
            ->send();

        return redirect($this->returnUrl());
    }

    /**
     * Back to the page the user came from, only within this app.
     */
    private function returnUrl(): string
    {
        $previous = url()->previous();
        $base = rtrim((string) config('app.url'), '/');

        return str_starts_with($previous, $base.'/') && ! str_contains($previous, '/clickup/') ? $previous : '/';
    }

    private function fail(string $return, string $message): RedirectResponse
    {
        Notification::make()->title($message)->danger()->persistent()->send();

        return redirect($return);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->active, 403);

        return $user;
    }
}
