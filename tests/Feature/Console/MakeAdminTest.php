<?php

use App\Enums\Role;
use App\Models\User;

it('grants the admin role by e-mail, case-insensitive', function () {
    $user = User::factory()->create(['email' => 'anna@guycolle.ch']);

    $this->artisan('intake:make-admin', ['email' => 'Anna@GUYCOLLE.ch'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(Role::Admin);
});

it('fails for a user who never signed in', function () {
    $this->artisan('intake:make-admin', ['email' => 'nobody@guycolle.ch'])->assertFailed();
});
