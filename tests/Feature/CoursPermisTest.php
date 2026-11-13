<?php

use App\Models\AutoEcoleUser;
use App\Models\Chapitre;
use App\Models\Lecon;
use App\Models\Module;
use App\Models\Quiz;
use App\Services\AutoEcole\CoursService;
use Illuminate\Support\Str;

function creerUtilisateurCours(string $typePermis = 'permis_a'): AutoEcoleUser
{
    return AutoEcoleUser::create([
        'nom' => 'Test',
        'prenom' => 'Motard',
        'telephone' => null,
        'password' => 'mot-de-passe',
        'type_permis' => $typePermis,
        'code_parrainage' => Str::upper(Str::random(10)),
        'premier_depot_at' => now(),
    ]);
}

function creerModuleCours(string $typePermis): Module
{
    return Module::create([
        'nom' => 'Cours ' . $typePermis,
        'type' => 'theorique',
        'type_permis' => $typePermis,
        'ordre' => 1,
        'active' => true,
    ]);
}

it('retourne à un apprenant A uniquement ses modules et les modules communs', function () {
    $user = creerUtilisateurCours();
    $moduleA = creerModuleCours('permis_a');
    $moduleB = creerModuleCours('permis_b');
    $moduleCommun = creerModuleCours('tous');

    $resultat = app(CoursService::class)->getStructureCours($user);
    $moduleIds = collect($resultat['modules'])->pluck('id')->all();

    expect($moduleIds)->toContain($moduleA->id)
        ->and($moduleIds)->toContain($moduleCommun->id)
        ->and($moduleIds)->not->toContain($moduleB->id);
});

it('interdit à un apprenant A d’ouvrir ou terminer une leçon B', function () {
    $user = creerUtilisateurCours();
    $moduleB = creerModuleCours('permis_b');
    $chapitre = Chapitre::create([
        'module_id' => $moduleB->id,
        'nom' => 'Chapitre voiture',
        'ordre' => 1,
        'active' => true,
    ]);
    $lecon = Lecon::create([
        'chapitre_id' => $chapitre->id,
        'titre' => 'Leçon voiture',
        'contenu_texte' => 'Contenu B',
        'ordre' => 1,
        'active' => true,
    ]);

    $service = app(CoursService::class);

    expect($service->getLecon($user, $lecon->id)['success'])->toBeFalse()
        ->and($service->marquerLeconTerminee($user, $lecon->id)['success'])->toBeFalse();
});

it('interdit à un apprenant A d’ouvrir ou soumettre un quiz B', function () {
    $user = creerUtilisateurCours();
    $moduleB = creerModuleCours('permis_b');
    $chapitre = Chapitre::create([
        'module_id' => $moduleB->id,
        'nom' => 'Chapitre voiture',
        'ordre' => 1,
        'active' => true,
    ]);
    $quiz = Quiz::create([
        'chapitre_id' => $chapitre->id,
        'titre' => 'Quiz voiture',
        'note_passage' => 12,
        'duree_minutes' => 30,
        'ordre' => 1,
        'active' => true,
    ]);

    $service = app(CoursService::class);

    expect($service->getQuiz($user, $quiz->id)['success'])->toBeFalse()
        ->and($service->soumettreQuiz($user, $quiz->id, [])['success'])->toBeFalse();
});
