<?php

namespace App\Providers;

use App\Intake\RuleMatcher;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rules are loaded once per request or job (triage table).
        $this->app->scoped(RuleMatcher::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        Event::listen(SocialiteWasCalled::class, [AzureExtendSocialite::class, 'handle']);

        FilamentTimezone::set(config('intake.display_timezone'));
    }
}
