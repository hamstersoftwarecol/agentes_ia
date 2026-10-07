<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('el seeder de demo se puede ejecutar varias veces sin duplicar datos', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'demo@hamster.test')->count())->toBe(1)
        ->and(Organization::where('slug', 'barberia-demo')->count())->toBe(1);

    $user = User::where('email', 'demo@hamster.test')->first();
    expect($user->organizations()->count())->toBe(1)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->current_organization_id)->not->toBeNull();
});

test('el seeder no pisa la contraseña de una cuenta demo ya existente', function () {
    $this->seed(DatabaseSeeder::class);
    $hash = User::where('email', 'demo@hamster.test')->value('password');

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'demo@hamster.test')->value('password'))->toBe($hash);
});
