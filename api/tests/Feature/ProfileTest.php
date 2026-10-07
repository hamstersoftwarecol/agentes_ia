<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('el usuario puede editar su perfil', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user/profile', [
        'name' => 'Alejandro', 'email' => $user->email, 'phone' => '3001234567',
    ])->assertOk()->assertJsonPath('name', 'Alejandro')->assertJsonPath('phone', '3001234567');
});

test('cambiar el correo exige verificarlo de nuevo', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user/profile', [
        'name' => $user->name, 'email' => 'nuevo@ejemplo.com',
    ])->assertOk()->assertJsonPath('email_verified', false);

    expect($user->fresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('no se puede usar el correo de otra cuenta', function () {
    $other = User::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/user/profile', ['name' => 'X', 'email' => $other->email])
        ->assertJsonValidationErrors('email');
});

test('cambia la contraseña con la actual correcta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/user/password', [
        'current_password' => 'password',
        'password' => 'una-clave-nueva-123',
        'password_confirmation' => 'una-clave-nueva-123',
    ])->assertNoContent();

    expect(Hash::check('una-clave-nueva-123', $user->fresh()->password))->toBeTrue();
});

test('rechaza la contraseña actual incorrecta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/user/password', [
        'current_password' => 'mal',
        'password' => 'una-clave-nueva-123',
        'password_confirmation' => 'una-clave-nueva-123',
    ])->assertJsonValidationErrors('current_password');
});
