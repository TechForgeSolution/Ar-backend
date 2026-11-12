<?php

use App\Models\AutoEcoleUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function createTelephoneOtpTestUser(array $attributes = []): AutoEcoleUser
{
    return AutoEcoleUser::create(array_merge([
        'nom' => 'Test',
        'prenom' => 'Utilisateur',
        'telephone' => '612345678',
        'password' => Hash::make('ancien-mot-de-passe'),
        'code_parrainage' => Str::upper(Str::random(10)),
        'telephone_verified' => true,
        'telephone_verified_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    config([
        'services.carey.key' => 'rk_test_example',
        'services.carey.url' => 'https://backend-carey.tfs237.com/v1',
    ]);
});

it('vérifie le numéro WhatsApp avant de créer un compte téléphone', function () {
    $parrain = createTelephoneOtpTestUser([
        'telephone' => '612345670',
        'code_parrainage' => 'PARRAIN123',
    ]);

    Http::fake([
        'backend-carey.tfs237.com/v1/otp/send' => Http::response(['id' => 'otp-signup-id', 'status' => 'sent']),
        'backend-carey.tfs237.com/v1/otp/verify' => Http::response(['id' => 'otp-signup-id', 'verified' => true]),
    ]);

    $this->postJson('/api/otp/telephone/envoyer', [
        'telephone' => '+237 612 345 679',
        'purpose' => 'inscription',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    Http::assertSent(fn ($request) => $request->url() === 'https://backend-carey.tfs237.com/v1/otp/send'
        && $request['to'] === '237612345679');

    $this->postJson('/api/inscription', [
        'nom' => 'Nouveau',
        'prenom' => 'Compte',
        'telephone' => '612345679',
        'password' => 'mot-de-passe',
        'password_confirmation' => 'mot-de-passe',
        'type_permis' => 'permis_b',
        'code_parrainage' => $parrain->code_parrainage,
    ])->assertUnprocessable();

    $verification = $this->postJson('/api/otp/telephone/verifier', [
        'telephone' => '612345679',
        'purpose' => 'inscription',
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->postJson('/api/inscription', [
        'nom' => 'Nouveau',
        'prenom' => 'Compte',
        'telephone' => '612345679',
        'password' => 'mot-de-passe',
        'password_confirmation' => 'mot-de-passe',
        'type_permis' => 'permis_b',
        'code_parrainage' => $parrain->code_parrainage,
        'otp_token' => $verification->json('verification_token'),
    ])
        ->assertCreated()
        ->assertJsonPath('user.telephone_verified', true);
});

it('permet de réinitialiser le mot de passe après vérification WhatsApp et révoque les sessions', function () {
    $user = createTelephoneOtpTestUser();
    $user->createToken('test-session');

    Http::fake([
        'backend-carey.tfs237.com/v1/otp/send' => Http::response(['id' => 'otp-recovery-id', 'status' => 'sent']),
        'backend-carey.tfs237.com/v1/otp/verify' => Http::response(['id' => 'otp-recovery-id', 'verified' => true]),
    ]);

    $this->postJson('/api/otp/telephone/envoyer', [
        'telephone' => $user->telephone,
        'purpose' => 'recuperation',
    ])->assertOk();

    $verification = $this->postJson('/api/otp/telephone/verifier', [
        'telephone' => $user->telephone,
        'purpose' => 'recuperation',
        'code' => '123456',
    ])->assertOk();

    $this->postJson('/api/mot-de-passe/reinitialiser', [
        'telephone' => $user->telephone,
        'verification_token' => $verification->json('verification_token'),
        'password' => 'nouveau-mot-de-passe',
        'password_confirmation' => 'nouveau-mot-de-passe',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Hash::check('nouveau-mot-de-passe', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});
