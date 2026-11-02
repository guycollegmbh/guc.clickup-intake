<?php

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Users\UserAdministration;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

function connectedEverywhere(): User
{
    $user = User::factory()->connected()->create();
    $user->forceFill([
        'clickup_token' => 'clickup-token',
        'clickup_connection_status' => ConnectionStatus::Connected,
    ])->save();

    return $user;
}

it('is for admins only', function () {
    $this->actingAs(User::factory()->create())->get(UserResource::getUrl('index'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(UserResource::getUrl('index'))->assertOk()->assertSee('Benutzer');
});

it('lists users with role, status and last poll', function () {
    $admin = User::factory()->admin()->create();
    $user = connectedEverywhere();
    $user->forceFill(['ms_last_polled_at' => '2026-11-02 07:00:00'])->save();

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $user])
        ->assertTableColumnFormattedStateSet('role', 'Mitglied', $user)
        ->assertTableColumnFormattedStateSet('ms_connection_status', 'Verbunden', $user)
        ->assertTableColumnFormattedStateSet('clickup_connection_status', 'Verbunden', $user)
        ->assertSee('02.11.2026 08:00')
        ->assertActionDoesNotExist('create');

    expect(UserResource::hasPage('create'))->toBeFalse()
        ->and(UserResource::hasPage('edit'))->toBeFalse();
});

it('deactivates a user and deletes all tokens', function () {
    $admin = User::factory()->admin()->create();
    $user = connectedEverywhere();

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate')->table($user))
        ->assertNotified('Benutzer deaktiviert.');

    $user->refresh();
    expect($user->active)->toBeFalse()
        ->and($user->ms_refresh_token)->toBeNull()
        ->and($user->ms_access_token)->toBeNull()
        ->and($user->ms_token_expires_at)->toBeNull()
        ->and($user->clickup_token)->toBeNull()
        ->and($user->ms_connection_status)->toBe(ConnectionStatus::Disconnected)
        ->and($user->clickup_connection_status)->toBe(ConnectionStatus::Disconnected);

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('deactivate')->table($user))
        ->callAction(TestAction::make('activate')->table($user));

    expect($user->fresh()->active)->toBeTrue()
        ->and($user->fresh()->ms_connection_status)->toBe(ConnectionStatus::Disconnected);
});

it('locks a deactivated user out of the panel', function () {
    $user = User::factory()->create();
    app(UserAdministration::class)->deactivate($user, User::factory()->admin()->create());

    $this->actingAs($user->fresh())->get('/')->assertForbidden();
});

it('changes the role of another user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('changeRole')->table($user), ['role' => Role::Admin->value]);

    expect($user->fresh()->role)->toBe(Role::Admin);
});

it('does not let an admin deactivate themselves or change their own role', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);
    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('deactivate')->table($admin))
        ->assertActionHidden(TestAction::make('changeRole')->table($admin));

    expect(fn () => app(UserAdministration::class)->deactivate($admin, $admin))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UserAdministration::class)->changeRole($admin, Role::Member, $admin))->toThrow(AuthorizationException::class)
        ->and($admin->fresh()->active)->toBeTrue()
        ->and($admin->fresh()->role)->toBe(Role::Admin);
});

it('lets members and inactive admins change nobody', function () {
    $user = User::factory()->create();

    expect(fn () => app(UserAdministration::class)->deactivate($user, User::factory()->create()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UserAdministration::class)->changeRole($user, Role::Admin, User::factory()->admin()->inactive()->create()))->toThrow(AuthorizationException::class)
        ->and($user->fresh()->active)->toBeTrue();
});

it('defines every ability Filament asks for on users', function () {
    foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore', 'restoreAny',
        'forceDelete', 'forceDeleteAny', 'replicate', 'reorder'] as $ability) {
        expect(method_exists(UserPolicy::class, $ability))->toBeTrue();
    }
});

it('shows a banner when the Microsoft connection is broken', function () {
    $broken = User::factory()->create(['ms_connection_status' => ConnectionStatus::Broken]);
    $text = 'Die Verbindung zu Microsoft 365 ist unterbrochen. Bitte ab- und wieder anmelden.';

    $this->actingAs($broken)->get('/')->assertOk()->assertSee($text);
    $this->actingAs(User::factory()->connected()->create())->get('/')->assertOk()->assertDontSee($text);
});
