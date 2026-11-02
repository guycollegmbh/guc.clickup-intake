<?php

namespace App\Providers\Filament;

use App\Enums\ConnectionStatus;
use App\Models\User;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // No ->login(): sign-in is Microsoft only, see routes/web.php. Guests are sent to route('login').
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->brandName('ClickUp-Intake')
            ->brandLogo(asset('images/logo-full.svg'))
            ->darkModeBrandLogo(asset('images/logo-full-dark.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/favicon.svg'))
            ->colors([
                'primary' => Color::hex('#00AEEF'),
                'gray' => Color::Zinc,
            ])
            ->font('Source Sans 3 Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/app/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Broken Microsoft connection of the signed-in user (E1): the next sign-in repairs it.
            ->renderHook(PanelsRenderHook::PAGE_START, function (): string {
                $user = Auth::user();

                return $user instanceof User && $user->ms_connection_status === ConnectionStatus::Broken
                    ? view('filament.microsoft-broken-banner')->render()
                    : '';
            })
            // Every resource needs a policy with every ability Filament asks for (R12).
            ->strictAuthorization();
    }
}
