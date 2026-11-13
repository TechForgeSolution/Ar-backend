<?php

namespace Database\Seeders;

use App\Models\Chapitre;
use App\Models\Lecon;
use App\Models\Module;
use App\Models\ProgressionLecon;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Reponse;
use App\Models\ResultatQuiz;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Peuple uniquement le programme Permis A.
 *
 * Prérequis VPS : les fichiers doivent être dans public/permisa, comme indiqué
 * dans l'arborescence fournie. Ce seeder peut être relancé sans créer de doublons.
 */
class PermisASeeder extends Seeder
{
    public function run(): void
    {
        $theoryDirectory = public_path('permisa/theorique');

        if (! File::isDirectory($theoryDirectory)) {
            throw new \RuntimeException("Dossier introuvable : {$theoryDirectory}");
        }

        DB::transaction(function () use ($theoryDirectory): void {
            $theoryModule = Module::firstOrCreate(
                ['nom' => 'Permis A — Moto : Théorie', 'type' => 'theorique', 'type_permis' => 'permis_a'],
                ['description' => 'Formation théorique du permis moto.', 'ordre' => 100, 'active' => true]
            );

            $practicalModule = Module::firstOrCreate(
                ['nom' => 'Permis A — Moto : Pratique', 'type' => 'pratique', 'type_permis' => 'permis_a'],
                ['description' => 'Exercices pratiques du permis moto.', 'ordre' => 100, 'active' => true]
            );

            $this->seedTheory($theoryModule, $theoryDirectory);
            $this->seedPractice($practicalModule);
        });
    }

    private function seedTheory(Module $module, string $directory): void
    {
        for ($number = 1; $number <= 7; $number++) {
            $jsonPath = "{$directory}/quiz_t{$number}.json";
            $htmlPath = "{$directory}/cours{$number}.html";

            if (! File::exists($jsonPath) || ! File::exists($htmlPath)) {
                throw new \RuntimeException("Cours {$number} ou son quiz absent dans {$directory}");
            }

            try {
                $data = json_decode(File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new \RuntimeException("JSON invalide : {$jsonPath}", 0, $exception);
            }

            $title = trim((string) ($data['titre'] ?? ''));
            $questions = $data['questions'] ?? null;

            if ($title === '' || ! is_array($questions) || $questions === []) {
                throw new \RuntimeException("Titre ou questions manquants dans {$jsonPath}");
            }

            if (isset($data['nombre_questions']) && (int) $data['nombre_questions'] !== count($questions)) {
                throw new \RuntimeException("Le nombre de questions déclaré est incorrect dans {$jsonPath}");
            }

            $title = $this->cleanText($title);
            $chapter = $this->chapter($module, $title, $number, 'Cours théorique du permis A.');
            Lecon::updateOrCreate(
                ['chapitre_id' => $chapter->id, 'ordre' => 1],
                [
                    'titre' => $title,
                    'contenu_texte' => null,
                    'url_web' => $this->publicUrl("permisa/theorique/cours{$number}.html"),
                    'url_video' => null,
                    'duree_minutes' => 30,
                    'active' => true,
                ]
            );

            $quiz = Quiz::updateOrCreate(
                ['chapitre_id' => $chapter->id, 'ordre' => 1],
                [
                    'titre' => "Quiz — {$title}",
                    'description' => "Évaluation du chapitre : {$title}.",
                    'note_passage' => 12,
                    'duree_minutes' => 15,
                    'active' => true,
                ]
            );

            foreach ($questions as $index => $item) {
                if (! is_array($item) || ! isset($item['question']) || ! is_string($item['question'])) {
                    throw new \RuntimeException("Énoncé invalide dans {$jsonPath}, question ".($index + 1));
                }

                if (isset($item['options'])) {
                    $options = $item['options'];
                    $correct = $item['reponse_correcte'] ?? null;
                } else {
                    $choices = $item['choix'] ?? [];
                    $options = is_array($choices) ? array_column($choices, 'texte') : [];
                    $correct = is_array($choices)
                        ? array_search($item['reponse'] ?? null, array_column($choices, 'id'), true)
                        : false;
                }

                if (
                    ! is_array($options)
                    || count($options) !== 4
                    || ! is_int($correct)
                    || ! isset($options[$correct])
                    || count(array_filter($options, 'is_string')) !== 4
                ) {
                    throw new \RuntimeException("Réponse invalide dans {$jsonPath}, question ".($index + 1));
                }

                $this->saveQuestion(
                    $quiz,
                    $index + 1,
                    $item['question'],
                    (string) ($item['explication'] ?? ''),
                    $options,
                    (int) $correct
                );
            }
        }
    }

    private function seedPractice(Module $module): void
    {
        $chapterTitles = [
            'A1' => 'Contrôles et déplacement de la moto à l’arrêt',
            'A2' => 'Position de conduite et maîtrise des commandes',
            'A3' => 'Équilibre et manœuvres à allure lente',
            'A4' => 'Manœuvres à allure normale',
            'B1' => 'Démarrage et placement dans la circulation',
            'B2' => 'Intersections et changements de direction',
            'B3' => 'Partage de la route et environnement',
        ];
        $exercisesByChapter = collect($this->practicalExercises())
            ->groupBy(fn (array $exercise): string => substr($exercise['folder'], 0, 2));

        foreach ($exercisesByChapter as $chapterOrder => $exercises) {
            if (! isset($chapterTitles[$chapterOrder])) {
                throw new \RuntimeException("Code de chapitre pratique inconnu : {$chapterOrder}");
            }

            $chapterNumber = array_search($chapterOrder, array_keys($chapterTitles), true) + 1;
            $chapterName = $chapterTitles[$chapterOrder];
            $chapter = $this->chapter(
                $module,
                $chapterName,
                $chapterNumber,
                "Chapitre pratique du permis A : {$chapterName}."
            );

            foreach ($exercises->values() as $lessonOrder => $exercise) {
                Lecon::updateOrCreate(
                    ['chapitre_id' => $chapter->id, 'ordre' => $lessonOrder + 1],
                    [
                        'titre' => $exercise['title'],
                        'contenu_texte' => "Objectif : {$exercise['objective']}",
                        'url_web' => null,
                        'url_video' => $this->findVideo($exercise['folder']),
                        'duree_minutes' => 15,
                        'active' => true,
                    ]
                );
            }

            $quiz = Quiz::updateOrCreate(
                ['chapitre_id' => $chapter->id, 'ordre' => 1],
                [
                    'titre' => "Quiz pratique — {$chapterName}",
                    'description' => "Évaluation des leçons pratiques du chapitre {$chapterName}.",
                    'note_passage' => 12,
                    'duree_minutes' => 10,
                    'active' => true,
                ]
            );

            $questionOrder = 1;
            foreach ($exercises as $exercise) {
                $questions = [
                    [
                        "Quel est l'objectif principal de l'exercice « {$exercise['title']} » ?",
                        $exercise['objective'],
                        ['Augmenter la vitesse sans contrôle', 'Éviter tout contrôle visuel', "S'entraîner uniquement à klaxonner"],
                        'L’objectif est de '.mb_strtolower($exercise['objective']),
                    ],
                    [
                        "Avant ou pendant « {$exercise['title']} », quel point de sécurité est essentiel ?",
                        $exercise['safety'],
                        ['Regarder seulement le sol', 'Accélérer brutalement', 'Ignorer les autres usagers'],
                        $exercise['safety'],
                    ],
                    [
                        "Quelle erreur faut-il éviter lors de « {$exercise['title']} » ?",
                        $exercise['mistake'],
                        ['Garder une allure adaptée et progressive', 'Contrôler son environnement', 'Appliquer les consignes de sécurité'],
                        $exercise['mistake'],
                    ],
                ];

                foreach ($questions as [$wording, $correct, $incorrectAnswers, $explanation]) {
                    $correctPosition = ($chapterNumber + $questionOrder - 1) % 4;
                    array_splice($incorrectAnswers, $correctPosition, 0, [$correct]);
                    $this->saveQuestion($quiz, $questionOrder, $wording, $explanation, $incorrectAnswers, $correctPosition);
                    $questionOrder++;
                }
            }
        }

        $legacyChapterIds = Chapitre::where('module_id', $module->id)
            ->where('ordre', '>', count($chapterTitles))
            ->whereIn('nom', array_column($this->practicalExercises(), 'title'))
            ->pluck('id');

        if ($legacyChapterIds->isNotEmpty()) {
            $chapterIds = Chapitre::where('module_id', $module->id)->pluck('id');
            $lessonIds = Lecon::whereIn('chapitre_id', $chapterIds)->pluck('id');
            $quizIds = Quiz::whereIn('chapitre_id', $chapterIds)->pluck('id');

            if (
                ProgressionLecon::whereIn('lecon_id', $lessonIds)->exists()
                || ResultatQuiz::whereIn('quiz_id', $quizIds)->exists()
            ) {
                throw new \RuntimeException(
                    'Les anciens chapitres pratiques ont des progressions ou des résultats. '
                    .'Migrez ou sauvegardez ces données avant de relancer le seeder.'
                );
            }

            Chapitre::whereIn('id', $legacyChapterIds)->delete();
        }
    }

    private function chapter(Module $module, string $name, int $order, string $description): Chapitre
    {
        return Chapitre::updateOrCreate(
            ['module_id' => $module->id, 'ordre' => $order],
            ['nom' => $name, 'description' => $description, 'active' => true]
        );
    }

    private function saveQuestion(Quiz $quiz, int $order, string $wording, string $explanation, array $answers, int $correct): void
    {
        $question = Question::updateOrCreate(
            ['quiz_id' => $quiz->id, 'ordre' => $order],
            ['enonce' => $this->cleanText($wording), 'image_url' => null, 'type' => 'qcm', 'explication' => $this->cleanText($explanation), 'points' => 1, 'active' => true]
        );

        foreach ($answers as $answerOrder => $answer) {
            Reponse::updateOrCreate(
                ['question_id' => $question->id, 'ordre' => $answerOrder + 1],
                ['texte' => $this->cleanText((string) $answer), 'est_correcte' => $answerOrder === $correct]
            );
        }
    }

    private function findVideo(string $folder): ?string
    {
        $base = public_path("permisa/pratique/{$folder}");
        if (! File::isDirectory($base)) {
            return null;
        }

        $video = collect(File::allFiles($base))->first(fn ($file) => in_array(strtolower($file->getExtension()), ['mp4', 'webm', 'mov', 'm4v'], true));
        if (! $video) {
            return null;
        }

        return $this->publicUrl('permisa/pratique/'.str_replace('\\', '/', $video->getRelativePathname()));
    }

    private function publicUrl(string $path): string
    {
        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function cleanText(string $text): string
    {
        // Les JSON sont UTF-8 ; ce correctif couvre les fichiers anciennement sauvegardés en UTF-8 mal décodé.
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8');
    }

    private function practicalExercises(): array
    {
        return [
            ['folder' => 'A1_1_Controle_pneus_chaine_freins', 'title' => 'Contrôle des pneus, de la chaîne et des freins', 'objective' => 'vérifier les éléments essentiels avant de rouler', 'safety' => 'contrôler l’état et le bon fonctionnement avant le départ', 'mistake' => 'partir sans vérifier pneus, chaîne et freins'],
            ['folder' => 'A1_2_Eclairage_huile', 'title' => 'Éclairage et niveau d’huile', 'objective' => 'vérifier les feux, clignotants et le niveau d’huile', 'safety' => 's’assurer que les éclairages fonctionnent avant de circuler', 'mistake' => 'rouler avec un éclairage défectueux ou sans contrôler l’huile'],
            ['folder' => 'A1_3_Deplacer_moteur_eteint_bequille', 'title' => 'Déplacer la moto, moteur éteint et béquille', 'objective' => 'manœuvrer la moto à l’arrêt en conservant l’équilibre', 'safety' => 'tenir fermement la moto et vérifier que le passage est dégagé', 'mistake' => 'tirer ou pousser la moto sans maîtriser son poids'],
            ['folder' => 'A2_1_Position_conduite_regard', 'title' => 'Position de conduite et regard', 'objective' => 'adopter une posture stable et porter le regard loin', 'safety' => 'garder les bras souples et regarder vers la trajectoire', 'mistake' => 'fixer la roue avant ou regarder uniquement le sol'],
            ['folder' => 'A2_2_Embrayage_accelerateur_boite', 'title' => 'Embrayage, accélérateur et boîte de vitesses', 'objective' => 'coordonner embrayage, gaz et sélection des rapports', 'safety' => 'effectuer des commandes progressives et souples', 'mistake' => 'relâcher brutalement l’embrayage'],
            ['folder' => 'A2_3_Deux_freins', 'title' => 'Utilisation des deux freins', 'objective' => 'utiliser le frein avant et le frein arrière avec progressivité', 'safety' => 'doser les deux freins sans bloquer les roues', 'mistake' => 'freiner brutalement avec un seul frein'],
            ['folder' => 'A3_1_Demarrage_cote_plat', 'title' => 'Démarrage en côte et sur le plat', 'objective' => 'démarrer sans caler ni reculer de façon incontrôlée', 'safety' => 'préparer le point de patinage et garder le frein arrière disponible', 'mistake' => 'relâcher l’embrayage trop vite'],
            ['folder' => 'A3_2_Equilibre_au_pas', 'title' => 'Équilibre à allure lente', 'objective' => 'maintenir l’équilibre de la moto à très faible vitesse', 'safety' => 'regarder loin et utiliser des commandes souples', 'mistake' => 'regarder la roue avant et faire des gestes brusques'],
            ['folder' => 'A3_3_Slalom_lent', 'title' => 'Slalom lent', 'objective' => 'enchaîner les plots avec précision à faible vitesse', 'safety' => 'porter le regard vers le plot suivant', 'mistake' => 'fixer les plots ou accélérer brutalement'],
            ['folder' => 'A3_4_Demi_tour', 'title' => 'Demi-tour', 'objective' => 'réaliser un demi-tour contrôlé dans un espace limité', 'safety' => 'regarder vers la sortie du demi-tour et maîtriser le point de patinage', 'mistake' => 'regarder le sol ou freiner brutalement en tournant'],
            ['folder' => 'A4_1_Slalom_rapide', 'title' => 'Slalom rapide', 'objective' => 'enchaîner les changements de direction avec maîtrise', 'safety' => 'adapter l’allure et anticiper le regard vers la sortie', 'mistake' => 'arriver trop vite sans anticiper la trajectoire'],
            ['folder' => 'A4_2_Evitement_obstacle', 'title' => 'Évitement d’obstacle', 'objective' => 'éviter un obstacle en conservant le contrôle de la moto', 'safety' => 'regarder la zone de dégagement plutôt que l’obstacle', 'mistake' => 'fixer l’obstacle ou freiner brutalement pendant l’évitement'],
            ['folder' => 'A4_3_Retrogradage', 'title' => 'Rétrogradage', 'objective' => 'choisir un rapport inférieur adapté à la vitesse', 'safety' => 'rétrograder progressivement après avoir adapté l’allure', 'mistake' => 'descendre plusieurs rapports brutalement à vitesse élevée'],
            ['folder' => 'A4_4_Freinage_urgence', 'title' => 'Freinage d’urgence', 'objective' => 's’arrêter rapidement sans perdre l’adhérence', 'safety' => 'freiner progressivement et garder la moto droite', 'mistake' => 'saisir brutalement le frein avant'],
            ['folder' => 'B1_1_Demarrage_trafic', 'title' => 'Démarrage dans la circulation', 'objective' => 's’insérer dans la circulation de manière sûre', 'safety' => 'observer autour de soi et signaler avant de démarrer', 'mistake' => 's’engager sans contrôle visuel ni clignotant'],
            ['folder' => 'B1_2_Placement_voie_visibilite', 'title' => 'Placement sur la voie et visibilité', 'objective' => 'se placer pour voir et être vu des autres usagers', 'safety' => 'éviter les angles morts et conserver une marge de sécurité', 'mistake' => 'rouler durablement dans l’angle mort d’un véhicule'],
            ['folder' => 'B1_3_Distances_securite', 'title' => 'Distances de sécurité', 'objective' => 'maintenir une distance permettant de réagir et freiner', 'safety' => 'augmenter la distance lorsque l’adhérence ou la visibilité baisse', 'mistake' => 'suivre le véhicule précédent de trop près'],
            ['folder' => 'B2_1_Approche_carrefours', 'title' => 'Approche des carrefours', 'objective' => 'aborder un carrefour en anticipant les dangers', 'safety' => 'ralentir, observer et se préparer à céder le passage', 'mistake' => 'arriver vite en supposant que la voie est libre'],
            ['folder' => 'B2_2_Angles_morts_retroviseurs', 'title' => 'Angles morts et rétroviseurs', 'objective' => 'contrôler les rétroviseurs et les angles morts avant une manœuvre', 'safety' => 'faire un contrôle visuel par-dessus l’épaule si nécessaire', 'mistake' => 'changer de direction en regardant seulement le rétroviseur'],
            ['folder' => 'B2_3_Clignotants_changements_dir', 'title' => 'Clignotants et changements de direction', 'objective' => 'annoncer clairement une manœuvre aux autres usagers', 'safety' => 'mettre le clignotant assez tôt puis vérifier avant d’agir', 'mistake' => 'actionner le clignotant au dernier moment ou oublier de l’éteindre'],
            ['folder' => 'B2_4_Ronds_points', 'title' => 'Ronds-points', 'objective' => 'circuler dans un rond-point en respectant les priorités', 'safety' => 'observer les usagers engagés et signaler la sortie', 'mistake' => 'entrer sans céder le passage aux véhicules déjà engagés'],
            ['folder' => 'B3_1_Espaces_lateraux_croisements', 'title' => 'Espaces latéraux et croisements', 'objective' => 'garder une marge latérale suffisante lors des croisements', 'safety' => 'ralentir si l’espace disponible est réduit', 'mistake' => 'passer trop près d’un véhicule ou d’un piéton'],
            ['folder' => 'B3_2a_Moto_taxis_pietons', 'title' => 'Moto-taxis et piétons', 'objective' => 'anticiper les comportements imprévisibles des moto-taxis et piétons', 'safety' => 'réduire l’allure dans les zones fréquentées', 'mistake' => 'circuler vite près des piétons ou des arrêts de moto-taxis'],
            ['folder' => 'B3_2b_Poids_lourds_angle_mort', 'title' => 'Poids lourds et angles morts', 'objective' => 'éviter les zones dangereuses autour des poids lourds', 'safety' => 'rester visible du conducteur et ne pas stationner dans ses angles morts', 'mistake' => 'rouler à côté d’un camion dans son angle mort'],
            ['folder' => 'B3_3_Agglomeration_hors_agglo', 'title' => 'Agglomération et hors agglomération', 'objective' => 'adapter sa conduite au milieu urbain et à la route', 'safety' => 'adapter vitesse, distance et observation à l’environnement', 'mistake' => 'conserver la même allure quelles que soient les conditions'],
        ];
    }
}
