<?php

use App\Models\AutoEcoleUser;
use App\Models\Publicite;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function createPubliciteTestUser(): AutoEcoleUser
{
    $code = Str::upper(Str::random(10));

    return AutoEcoleUser::create([
        'nom' => 'Test',
        'prenom' => 'Utilisateur',
        'telephone' => '6'.Str::random(8),
        'password' => Hash::make('password'),
        'code_parrainage' => $code,
    ]);
}

it('propose une publicité une seule fois à chaque utilisateur et permet de confirmer sa lecture', function () {
    $this->travelTo(now()->startOfSecond());
    $user = createPubliciteTestUser();
    $publicite = Publicite::create([
        'image_path' => 'publicites/test.jpg',
        'redirect_url' => 'https://example.com/promotion',
        'active' => true,
        'published_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/publicite')
        ->assertOk()
        ->assertJsonPath('publicite.id', $publicite->id)
        ->assertJsonPath('publicite.redirect_url', 'https://example.com/promotion');

    expect($response->json('publicite.expires_at'))->toBe(now()->addMinutes(20)->timestamp * 1000);

    $this->postJson("/api/publicite/{$publicite->id}/pris-note")
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/publicite')
        ->assertOk()
        ->assertJsonPath('publicite', null);

    Sanctum::actingAs(createPubliciteTestUser());

    $this->getJson('/api/publicite')
        ->assertOk()
        ->assertJsonPath('publicite.id', $publicite->id);
});

it('ne propose pas de publicité désactivée', function () {
    Sanctum::actingAs(createPubliciteTestUser());

    Publicite::create([
        'image_path' => 'publicites/test.jpg',
        'redirect_url' => 'https://example.com/promotion',
        'active' => false,
        'published_at' => now(),
    ]);

    $this->getJson('/api/publicite')
        ->assertOk()
        ->assertJsonPath('publicite', null);
});

it('permet à l’administration de publier une campagne et désactive la précédente', function () {
    Storage::fake('public');

    $anciennePublicite = Publicite::create([
        'image_path' => 'publicites/ancienne.jpg',
        'redirect_url' => 'https://example.com/ancienne',
        'active' => true,
        'published_at' => now()->subDay(),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('admin.auto-ecole.config.publicites.store'), [
            'image' => UploadedFile::fake()->create('promotion.jpg', 10, 'image/jpeg'),
            'redirect_url' => 'https://example.com/nouvelle',
        ])
        ->assertRedirect();

    expect($anciennePublicite->fresh()->active)->toBeFalse()
        ->and(Publicite::where('active', true)->count())->toBe(1);

    $nouvellePublicite = Publicite::where('active', true)->firstOrFail();
    expect($nouvellePublicite->redirect_url)->toBe('https://example.com/nouvelle');
    Storage::disk('public')->assertExists($nouvellePublicite->image_path);
});
