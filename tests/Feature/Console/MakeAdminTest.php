<?php

use App\Enums\Role;
use App\Models\User;

it('grants the admin role by e-mail, case-insensitive', function () {
    $user = User::factory()->create(['email' => 'anna@guycolle.ch']);

    $this->artisan('intake:make-admin', ['email' => 'Anna@GUYCOLLE.ch'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(Role::Admin);
});

it('finds the user by UPN', function () {
    $user = User::factory()->create(['email' => 'anna@guycolle.ch', 'mailbox_upn' => 'a.muster@guycolle.ch']);

    $this->artisan('intake:make-admin', ['email' => 'a.muster@guycolle.ch'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(Role::Admin);
});

it('refuses an ambiguous e-mail and accepts --oid', function () {
    $old = User::factory()->create(['email' => 'anna@guycolle.ch', 'entra_object_id' => 'oid-old']);
    $new = User::factory()->create(['email' => 'anna@guycolle.ch', 'entra_object_id' => 'oid-new']);

    $this->artisan('intake:make-admin', ['email' => 'anna@guycolle.ch'])->assertFailed();
    expect($old->fresh()->role)->toBe(Role::Member)->and($new->fresh()->role)->toBe(Role::Member);

    $this->artisan('intake:make-admin', ['--oid' => 'oid-new'])->assertSuccessful();
    expect($old->fresh()->role)->toBe(Role::Member)->and($new->fresh()->role)->toBe(Role::Admin);
});

it('reactivates a deactivated user', function () {
    $user = User::factory()->inactive()->create(['email' => 'anna@guycolle.ch']);

    $this->artisan('intake:make-admin', ['email' => 'anna@guycolle.ch'])->assertSuccessful();

    expect($user->fresh()->active)->toBeTrue();
});

it('fails for a user who never signed in', function () {
    $this->artisan('intake:make-admin', ['email' => 'nobody@guycolle.ch'])->assertFailed();
});
