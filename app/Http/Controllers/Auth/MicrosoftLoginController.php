<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Microsoft\LoginRejected;
use App\Microsoft\MicrosoftApp;
use App\Microsoft\MicrosoftLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class MicrosoftLoginController extends Controller
{
    public function __construct(private MicrosoftApp $app) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return view('auth.login', ['configured' => $this->app->isConfigured()]);
    }

    public function redirect(): RedirectResponse
    {
        abort_unless($this->app->isConfigured(), 503);

        $this->app->configureSocialite();

        return Socialite::driver('azure')->scopes($this->app->scopes())->redirect();
    }

    public function callback(Request $request, MicrosoftLogin $login): RedirectResponse
    {
        abort_unless($this->app->isConfigured(), 503);

        if ($request->filled('error')) {
            return $this->fail('Microsoft hat die Anmeldung abgelehnt. Bist du der App zugewiesen?');
        }

        $this->app->configureSocialite();

        try {
            $socialUser = Socialite::driver('azure')->user();
        } catch (InvalidStateException) {
            return $this->fail('Die Anmeldung ist abgelaufen. Bitte erneut versuchen.');
        } catch (Throwable $e) {
            Log::warning('Microsoft login failed', ['exception' => $e::class]);

            return $this->fail('Die Anmeldung bei Microsoft ist fehlgeschlagen.');
        }

        try {
            $user = $login->handle(
                claims: $this->idTokenClaims($socialUser->accessTokenResponseBody['id_token'] ?? null),
                name: (string) $socialUser->getName(),
                upn: (string) $socialUser->user['userPrincipalName'],
                mail: $socialUser->user['mail'] ?? null,
                refreshToken: $socialUser->refreshToken,
            );
        } catch (LoginRejected $e) {
            return $this->fail($e->getMessage());
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * The ID token comes straight from the token endpoint over TLS, authenticated with our client
     * secret (OIDC Core 3.1.3.7, item 6), so the signature check is not required here.
     *
     * @return array<string, mixed>
     */
    private function idTokenClaims(?string $idToken): array
    {
        $parts = explode('.', (string) $idToken);

        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        return is_string($payload) ? (json_decode($payload, true) ?: []) : [];
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('login_error', $message);
    }
}
