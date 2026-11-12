<?php

namespace App\Services\AutoEcole;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CareyOtpService
{
    private const OTP_TTL_MINUTES = 5;

    public function normalizePhone(string $telephone): ?string
    {
        $telephone = trim($telephone);
        if (! preg_match('/^\+?[0-9\s().-]+$/', $telephone)) {
            return null;
        }

        $digits = preg_replace('/[\s().+-]+/', '', $telephone);

        if (str_starts_with($digits, '00237')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '237') && strlen($digits) === 12) {
            $digits = substr($digits, 3);
        }

        return preg_match('/^\d{9}$/', $digits) ? $digits : null;
    }

    public function send(string $telephone, string $purpose): array
    {
        $apiKey = config('services.carey.key');
        if (! $apiKey) {
            Log::error('La clé API Carey n’est pas configurée.');

            return ['success' => false, 'status' => 503, 'message' => 'Le service de vérification est indisponible.'];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout(15)
                ->post(config('services.carey.url').'/otp/send', [
                    'to' => '237'.$telephone,
                    'length' => 6,
                    'ttl_minutes' => self::OTP_TTL_MINUTES,
                    'lang' => 'fr',
                    'app_name' => config('app.name', 'Elite'),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Impossible de joindre Carey pour envoyer un OTP.', ['purpose' => $purpose]);

            return ['success' => false, 'status' => 503, 'message' => 'Impossible de joindre le service WhatsApp. Réessayez plus tard.'];
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        if (! $response->successful() || ! is_string($data['id'] ?? null) || $data['id'] === '') {
            return $this->providerError($data);
        }

        Cache::put($this->pendingKey($telephone, $purpose), $data['id'], now()->addMinutes(self::OTP_TTL_MINUTES));
        Cache::forget($this->verificationKey($telephone, $purpose));

        return ['success' => true, 'status' => 200];
    }

    public function verify(string $telephone, string $purpose, string $code): array
    {
        $otpId = Cache::get($this->pendingKey($telephone, $purpose));
        if (! $otpId) {
            return ['success' => false, 'status' => 422, 'message' => 'Code invalide ou expiré. Demandez un nouveau code.'];
        }

        $apiKey = config('services.carey.key');
        if (! $apiKey) {
            Log::error('La clé API Carey n’est pas configurée.');

            return ['success' => false, 'status' => 503, 'message' => 'Le service de vérification est indisponible.'];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout(15)
                ->post(config('services.carey.url').'/otp/verify', [
                    'id' => $otpId,
                    'code' => $code,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Impossible de joindre Carey pour vérifier un OTP.', ['purpose' => $purpose]);

            return ['success' => false, 'status' => 503, 'message' => 'Impossible de joindre le service WhatsApp. Réessayez plus tard.'];
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        if (! $response->successful() || ($data['verified'] ?? false) !== true) {
            return $this->providerError($data);
        }

        Cache::forget($this->pendingKey($telephone, $purpose));
        $verificationToken = Str::random(64);
        Cache::put(
            $this->verificationKey($telephone, $purpose),
            $verificationToken,
            now()->addMinutes(self::OTP_TTL_MINUTES)
        );

        return ['success' => true, 'status' => 200, 'verification_token' => $verificationToken];
    }

    public function isVerified(string $telephone, string $purpose, string $verificationToken): bool
    {
        $storedToken = Cache::get($this->verificationKey($telephone, $purpose));

        return is_string($storedToken) && hash_equals($storedToken, $verificationToken);
    }

    public function consumeVerification(string $telephone, string $purpose): void
    {
        Cache::forget($this->verificationKey($telephone, $purpose));
    }

    private function pendingKey(string $telephone, string $purpose): string
    {
        return "carey_otp_pending:{$purpose}:".hash('sha256', $telephone);
    }

    private function verificationKey(string $telephone, string $purpose): string
    {
        return "carey_otp_verified:{$purpose}:".hash('sha256', $telephone);
    }

    private function providerError(array $data): array
    {
        $error = $data['error'] ?? null;
        $message = match ($error) {
            'not_on_whatsapp' => 'Ce numéro n’est pas associé à WhatsApp.',
            'rate_limited', 'throttled' => 'Trop de demandes. Patientez avant de réessayer.',
            'invalid_code' => 'Le code saisi est incorrect.',
            'expired' => 'Ce code a expiré. Demandez-en un nouveau.',
            'blocked', 'already_verified' => 'Ce code n’est plus valide. Demandez-en un nouveau.',
            'payment_required', 'number_not_connected', 'no_instance', 'gateway_unreachable' => 'Le service WhatsApp est temporairement indisponible.',
            default => 'La vérification WhatsApp a échoué. Réessayez plus tard.',
        };

        $responseStatus = match ($error) {
            'not_on_whatsapp', 'invalid_code', 'expired', 'blocked', 'already_verified' => 422,
            'rate_limited', 'throttled' => 429,
            default => 503,
        };

        return ['success' => false, 'status' => $responseStatus, 'message' => $message];
    }
}
