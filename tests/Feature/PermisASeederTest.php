<?php

use App\Models\Chapitre;
use App\Models\Lecon;
use App\Models\Module;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Reponse;
use Database\Seeders\PermisASeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('seeds the Permis A courses and quizzes idempotently', function () {
    $originalPublicPath = app()->publicPath();
    $temporaryPublicPath = storage_path('framework/testing/permisa-'.Str::uuid());
    $theoryDirectory = $temporaryPublicPath.'/permisa/theorique';

    try {
        app()->usePublicPath($temporaryPublicPath);
        File::makeDirectory($theoryDirectory, 0755, true);

        foreach (range(1, 7) as $number) {
            File::put($theoryDirectory."/cours{$number}.html", '<h1>Cours A '.$number.'</h1>');

            $question = [
                'question' => "Question {$number} ?",
                'explication' => 'Explication',
            ];

            if ($number % 2 === 1) {
                $question['options'] = ['Mauvaise réponse', 'Autre réponse', 'Bonne réponse', 'Dernière réponse'];
                $question['reponse_correcte'] = 2;
            } else {
                $question['choix'] = [
                    ['id' => 'A', 'texte' => 'Mauvaise réponse'],
                    ['id' => 'B', 'texte' => 'Bonne réponse'],
                    ['id' => 'C', 'texte' => 'Autre réponse'],
                    ['id' => 'D', 'texte' => 'Dernière réponse'],
                ];
                $question['reponse'] = 'B';
            }

            File::put($theoryDirectory."/quiz_t{$number}.json", json_encode([
                'titre' => "Cours A {$number}",
                'nombre_questions' => 1,
                'questions' => [$question],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }

        (new PermisASeeder)->run();

        $modules = Module::where('type_permis', 'permis_a')->get();
        $moduleIds = $modules->pluck('id');
        $theoryModule = $modules->firstWhere('type', 'theorique');
        $practicalModule = $modules->firstWhere('type', 'pratique');

        expect($modules)->toHaveCount(2)
            ->and(Chapitre::where('module_id', $theoryModule->id)->count())->toBe(7)
            ->and(Chapitre::where('module_id', $practicalModule->id)->count())->toBe(7)
            ->and(Chapitre::where('module_id', $practicalModule->id)->orderBy('ordre')->pluck('nom')->all())->toBe([
                'Contrôles et déplacement de la moto à l’arrêt',
                'Position de conduite et maîtrise des commandes',
                'Équilibre et manœuvres à allure lente',
                'Manœuvres à allure normale',
                'Démarrage et placement dans la circulation',
                'Intersections et changements de direction',
                'Partage de la route et environnement',
            ])
            ->and(Chapitre::whereIn('module_id', $moduleIds)->count())->toBe(14)
            ->and(Lecon::whereIn('chapitre_id', Chapitre::whereIn('module_id', $moduleIds)->select('id'))->count())->toBe(32)
            ->and(Quiz::whereIn('chapitre_id', Chapitre::whereIn('module_id', $moduleIds)->select('id'))->count())->toBe(14);

        $theoryQuiz = Quiz::where('titre', 'Quiz — Cours A 1')->firstOrFail();
        $theoryQuestion = Question::where('quiz_id', $theoryQuiz->id)->firstOrFail();
        expect(Reponse::where('question_id', $theoryQuestion->id)->where('est_correcte', true)->value('ordre'))->toBe(3);

        $practicalChapter = Chapitre::where('module_id', $practicalModule->id)->where('ordre', 1)->firstOrFail();
        $practicalQuestion = Question::where('quiz_id', $practicalChapter->quiz()->value('id'))->where('ordre', 1)->firstOrFail();
        expect(Lecon::where('chapitre_id', $practicalChapter->id)->count())->toBe(3)
            ->and(Question::where('quiz_id', $practicalChapter->quiz()->value('id'))->count())->toBe(9)
            ->and(Reponse::where('question_id', $practicalQuestion->id)->where('est_correcte', true)->value('ordre'))->toBe(2);

        Chapitre::create([
            'module_id' => $practicalModule->id,
            'nom' => 'Slalom rapide',
            'ordre' => 8,
            'active' => true,
        ]);

        (new PermisASeeder)->run();

        expect(Module::where('type_permis', 'permis_a')->count())->toBe(2)
            ->and(Chapitre::whereIn('module_id', $moduleIds)->count())->toBe(14)
            ->and(Lecon::whereIn('chapitre_id', Chapitre::whereIn('module_id', $moduleIds)->select('id'))->count())->toBe(32)
            ->and(Quiz::whereIn('chapitre_id', Chapitre::whereIn('module_id', $moduleIds)->select('id'))->count())->toBe(14);
    } finally {
        app()->usePublicPath($originalPublicPath);
        File::deleteDirectory($temporaryPublicPath);
    }
});
