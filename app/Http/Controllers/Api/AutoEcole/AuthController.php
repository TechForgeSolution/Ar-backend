<?php

namespace App\Http\Controllers\Api\AutoEcole;

use App\Http\Controllers\Controller;
use App\Services\AutoEcole\AuthService;
use App\Services\AutoEcole\CareyOtpService;
use App\Services\AutoEcole\DashboardService;
use App\Models\AutoEcoleUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected $authService;
    protected $dashboardService;
    protected CareyOtpService $careyOtpService;

    public function __construct(
        AuthService $authService,
        DashboardService $dashboardService,
        CareyOtpService $careyOtpService
    ) {
        $this->authService = $authService;
        $this->dashboardService = $dashboardService;
        $this->careyOtpService = $careyOtpService;
    }

    // ──────────────────────────────────────────
    // Inscription classique (telephone)
    // ──────────────────────────────────────────
    public function inscription(Request $request): JsonResponse
    {
        try {
            if (is_string($request->input('telephone'))) {
                $telephone = $this->careyOtpService->normalizePhone($request->input('telephone'));
                if ($telephone !== null) {
                    $request->merge(['telephone' => $telephone]);
                }
            }

            $validated = $request->validate([
    'nom'              => 'required|string|max:255',
    'prenom'           => 'required|string|max:255',
    'telephone'        => 'required_without:email|nullable|string|unique:auto_ecole_users,telephone',
    'email'            => 'required_without:telephone|nullable|email|unique:auto_ecole_users,email',
    'password'         => 'required|string|min:6|confirmed',
    'date_naissance'   => 'nullable|date',
    'type_permis'      => 'required|in:permis_a,permis_b,permis_t',
    'centre_examen_id' => 'nullable|exists:centres_examen,id',
    'code_parrainage'  => 'required|string',
    'otp_token'        => 'required_with:telephone|nullable|string',
]);

            if (!empty($validated['telephone']) && !$this->careyOtpService->isVerified(
                $validated['telephone'],
                'inscription',
                $validated['otp_token']
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vérifiez votre numéro WhatsApp avant de créer votre compte.',
                ], 422);
            }

            $result = $this->authService->inscription($validated);
            if ($result['success'] && !empty($validated['telephone'])) {
                $this->careyOtpService->consumeVerification($validated['telephone'], 'inscription');
            }
            return response()->json($result, $result['success'] ? 201 : 422);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erreur inscription', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    public function envoyerOtpTelephone(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'purpose' => 'required|in:inscription,recuperation',
        ]);

        $telephone = $this->careyOtpService->normalizePhone($request->string('telephone')->toString());
        if ($telephone === null) {
            return response()->json([
                'success' => false,
                'message' => 'Entrez un numéro WhatsApp camerounais valide (9 chiffres ou indicatif +237).',
            ], 422);
        }

        $purpose = $request->string('purpose')->toString();
        $user = AutoEcoleUser::where('telephone', $telephone)->first();

        if ($purpose === 'inscription' && $user) {
            return response()->json([
                'success' => false,
                'message' => 'Un compte utilise déjà ce numéro WhatsApp.',
            ], 422);
        }

        if ($purpose === 'recuperation' && (!$user || $user->auth_provider !== 'telephone' || !$user->telephone_verified)) {
            return response()->json([
                'success' => true,
                'message' => 'Si ce numéro correspond à un compte, un code lui sera envoyé sur WhatsApp.',
            ]);
        }

        $result = $this->careyOtpService->send($telephone, $purpose);
        if (!$result['success']) {
            return response()->json($result, $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Un code de vérification a été envoyé sur WhatsApp.',
        ]);
    }

    public function verifierOtpTelephone(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'purpose' => 'required|in:inscription,recuperation',
            'code' => 'required|string|digits:6',
        ]);

        $telephone = $this->careyOtpService->normalizePhone($request->string('telephone')->toString());
        if ($telephone === null) {
            return response()->json([
                'success' => false,
                'message' => 'Entrez un numéro WhatsApp camerounais valide.',
            ], 422);
        }

        $purpose = $request->string('purpose')->toString();
        if ($purpose === 'recuperation') {
            $user = AutoEcoleUser::where('telephone', $telephone)->first();
            if (!$user || $user->auth_provider !== 'telephone' || !$user->telephone_verified) {
                return response()->json(['success' => false, 'message' => 'Code invalide ou expiré.'], 422);
            }
        }

        $result = $this->careyOtpService->verify(
            $telephone,
            $purpose,
            $request->string('code')->toString()
        );

        return response()->json($result, $result['status']);
    }

    public function reinitialiserMotDePasse(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => 'required|string',
            'verification_token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $telephone = $this->careyOtpService->normalizePhone($request->string('telephone')->toString());
        if ($telephone === null || !$this->careyOtpService->isVerified(
            $telephone,
            'recuperation',
            $request->string('verification_token')->toString()
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Vérification expirée. Demandez un nouveau code WhatsApp.',
            ], 422);
        }

        $user = AutoEcoleUser::where('telephone', $telephone)
            ->where('auth_provider', 'telephone')
            ->where('telephone_verified', true)
            ->first();

        if (!$user) {
            $this->careyOtpService->consumeVerification($telephone, 'recuperation');

            return response()->json(['success' => false, 'message' => 'Compte introuvable.'], 404);
        }

        $user->password = Hash::make($request->string('password')->toString());
        $user->save();
        $user->tokens()->delete();
        $this->careyOtpService->consumeVerification($telephone, 'recuperation');

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.',
        ]);
    }

    // ──────────────────────────────────────────
// OTP Email — Envoi (renvoi manuel)
// ──────────────────────────────────────────
public function envoyerOtpEmail(Request $request): JsonResponse
{
    $request->validate(['email' => 'required|email']);

    $user = \App\Models\AutoEcoleUser::where('email', $request->email)->first();
    if (!$user) {
        return response()->json(['success' => false, 'message' => 'Email introuvable'], 404);
    }
    if ($user->email_verified) {
        return response()->json(['success' => false, 'message' => 'Email déjà vérifié'], 422);
    }

    $result = $this->authService->envoyerOtpEmail($request->email);
    return response()->json($result, $result['success'] ? 200 : 500);
}

// ──────────────────────────────────────────
// OTP Email — Vérification
// ──────────────────────────────────────────
public function verifierOtpEmail(Request $request): JsonResponse
{
    $request->validate([
        'email' => 'required|email',
        'code'  => 'required|string|size:6',
    ]);

    $result = $this->authService->verifierOtpEmail(
        $request->email,
        $request->code
    );

    return response()->json($result, $result['success'] ? 200 : 422);
}
    // ──────────────────────────────────────────
    // Connexion Social (Google / Apple via Firebase)
    // ──────────────────────────────────────────
    public function socialLogin(Request $request): JsonResponse
    {
        $request->validate([
            'firebase_token' => 'required|string',
            'provider'       => 'required|in:google,apple',
        ]);

        $result = $this->authService->socialLogin(
            $request->firebase_token,
            $request->provider
        );

        $status = $result['success'] ? 200 : 422;
        return response()->json($result, $status);
    }

    // ──────────────────────────────────────────
    // Complétion du profil social
    // ──────────────────────────────────────────
    public function completerProfilSocial(Request $request): JsonResponse
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'nom'              => 'required|string|max:255',
            'prenom'           => 'required|string|max:255',
            'telephone'        => 'required|string|unique:auto_ecole_users,telephone,' . $user->id,
            'type_permis'      => 'required|in:permis_a,permis_b,permis_t',
            'centre_examen_id' => 'nullable|exists:centres_examen,id',
            'code_parrainage'  => 'required|string',
            'date_naissance'   => 'nullable|date',
        ]);

        $result = $this->authService->completerProfilSocial($user, $validated);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // ──────────────────────────────────────────
    // Connexion classique
    // ──────────────────────────────────────────
    public function connexion(Request $request): JsonResponse
    {
        $validated = $request->validate([
    'telephone' => 'required_without:email|nullable|string',
    'email'     => 'required_without:telephone|nullable|email',
    'password'  => 'required|string',
]);

        try {
            // AuthController.php — méthode connexion()
$result = $this->authService->connexion(
    $validated['telephone'] ?? null,
    $validated['email']     ?? null,
    $validated['password']
);
            return response()->json($result, $result['success'] ? 200 : 401);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur serveur'], 500);
        }
    }

    // ──────────────────────────────────────────
    // Méthodes existantes inchangées
    // ──────────────────────────────────────────
    public function deconnexion(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $result = $this->authService->deconnexion($user);
        return response()->json($result);
    }

    public function profil(Request $request): JsonResponse
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }
        return response()->json($this->authService->profil($user));
    }

    public function mettreAJourProfil(Request $request): JsonResponse
    {
        $user = $request->user('api');
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'nom'           => 'sometimes|string|max:255',
            'prenom'        => 'sometimes|string|max:255',
            'telephone'     => 'sometimes|string|unique:auto_ecole_users,telephone,' . $user->id,
            'quartier'      => 'nullable|string',
            'type_cours'    => 'sometimes|in:en_ligne,presentiel,les_deux',
            'password'      => 'nullable|string|min:6',
            'lieux_pratique'   => 'nullable|array',
            'lieux_pratique.*' => 'exists:lieux_pratique,id',
        ]);

        return response()->json($this->authService->mettreAJourProfil($user, $validated));
    }

    public function codeParrainageDefaut(): JsonResponse
    {
        return response()->json($this->authService->getCodeParrainageDefaut());
    }

    public function configuration(): JsonResponse
    {
        return response()->json($this->dashboardService->getConfiguration());
    }

    // ──────────────────────────────────────────
// Suppression de compte (soft delete via password)
// ──────────────────────────────────────────
public function supprimerCompte(Request $request): JsonResponse
{
    $user = $request->user('api');
    if (!$user) {
        return response()->json(['success' => false, 'message' => 'Non authentifié'], 401);
    }

    $user->password = \Illuminate\Support\Facades\Hash::make('supprimer');
    $user->save();

    // Révocation du token Sanctum
    $user->currentAccessToken()->delete();

    return response()->json(['success' => true, 'message' => 'Compte supprimé avec succès']);
}


}