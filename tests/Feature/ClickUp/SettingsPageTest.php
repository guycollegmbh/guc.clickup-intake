<?php

use App\ClickUp\ClickUpApp;
use App\Filament\Pages\ClickUpSettings;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeClickUp;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

it('is for admins only', function () {
    $this->actingAs(User::factory()->create())->get('/clickup')->assertForbidden();
    $this->actingAs(User::factory()->admin()->inactive()->create())->get('/clickup')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/clickup')->assertOk();
});

it('refuses a Livewire request after the admin lost the role', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test(ClickUpSettings::class);

    $admin->forceFill(['role' => 'member'])->save();

    $page->call('save')->assertForbidden();
});

it('shows the redirect URL from APP_URL and asks to connect for the workspace', function () {
    config(['app.url' => 'https://intake.example.test']);
    configureClickUpApp(withWorkspace: false);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ClickUpSettings::class)
        ->assertSchemaStateSet(['redirect_uri' => 'https://intake.example.test/clickup/callback', 'client_id' => 'clickup-client-id'])
        ->assertSee('Verbinde dein ClickUp-Konto, um den Workspace zu wählen.')
        ->assertSee('Noch nicht eingerichtet.')
        ->assertDontSee('clickup-client-secret');
});

it('stores the credentials encrypted and requires the secret the first time', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ClickUpSettings::class)
        ->fillForm(['client_id' => 'new-id', 'client_secret' => ''])
        ->call('save')
        ->assertHasFormErrors(['client_secret' => 'required']);

    Livewire::test(ClickUpSettings::class)
        ->fillForm(['client_id' => 'new-id', 'client_secret' => 'new-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get(ClickUpApp::SETTING_CLIENT_ID))->toBe('new-id')
        ->and(Setting::get(ClickUpApp::SETTING_CLIENT_SECRET))->toBe('new-secret')
        ->and(DB::table('settings')->where('key', ClickUpApp::SETTING_CLIENT_SECRET)->value('value'))->not->toContain('new-secret');
});

it('keeps the secret when the field stays empty', function () {
    configureClickUpApp(withWorkspace: false);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ClickUpSettings::class)
        ->assertSchemaStateSet(['client_secret' => null])
        ->fillForm(['client_id' => 'changed-id', 'client_secret' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get(ClickUpApp::SETTING_CLIENT_ID))->toBe('changed-id')
        ->and(Setting::get(ClickUpApp::SETTING_CLIENT_SECRET))->toBe('clickup-client-secret');
});

it('offers the workspaces of the connected admin and stores the choice', function () {
    configureClickUpApp(withWorkspace: false);
    FakeClickUp::fake(['GET team' => Http::response(FakeClickUp::teams())]);
    $this->actingAs(User::factory()->admin()->clickUpConnected()->create());

    Livewire::test(ClickUpSettings::class)
        ->assertSet('teams', [FakeClickUp::TEAM_ID => 'GUYCOLLE Test', FakeClickUp::OTHER_TEAM_ID => 'Anderer Workspace'])
        ->fillForm(['team_id' => FakeClickUp::TEAM_ID])
        ->call('save')
        ->assertHasNoFormErrors();

    $app = app(ClickUpApp::class);
    expect($app->teamId())->toBe(FakeClickUp::TEAM_ID)
        ->and($app->teamName())->toBe('GUYCOLLE Test')
        ->and($app->isConfigured())->toBeTrue();
});

it('refuses a workspace the admin does not have', function () {
    configureClickUpApp(withWorkspace: false);
    FakeClickUp::fake(['GET team' => Http::response(FakeClickUp::teams())]);
    $this->actingAs(User::factory()->admin()->clickUpConnected()->create());

    Livewire::test(ClickUpSettings::class)
        ->fillForm(['team_id' => '123456'])
        ->call('save')
        ->assertHasFormErrors(['team_id']);

    expect(app(ClickUpApp::class)->teamId())->toBeNull();
});

it('shows a hint when the workspaces cannot be loaded', function () {
    configureClickUpApp();
    FakeClickUp::fake(['GET team' => Http::response([], 500)]);
    $this->actingAs(User::factory()->admin()->clickUpConnected()->create());

    Livewire::test(ClickUpSettings::class)
        ->assertOk()
        ->assertSee('Die Workspaces konnten nicht von ClickUp geladen werden.')
        ->assertSee('Eingerichtet mit dem Workspace «GUYCOLLE Test».');
});
