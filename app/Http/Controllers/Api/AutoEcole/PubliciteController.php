<?php

namespace App\Http\Controllers\Api\AutoEcole;

use App\Http\Controllers\Controller;
use App\Models\Publicite;
use App\Models\PubliciteVue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PubliciteController extends Controller
{
    public function disponible(Request $request): JsonResponse
    {
        $publicite = Publicite::query()
            ->where('active', true)
            ->latest('published_at')
            ->first();

        if (! $publicite) {
            return response()->json(['success' => true, 'publicite' => null]);
        }

        $vuAt = now();
        $vue = PubliciteVue::firstOrCreate(
            [
                'publicite_id' => $publicite->id,
                'user_id' => $request->user()->id,
            ],
            ['vu_at' => $vuAt]
        );

        if (! $vue->wasRecentlyCreated) {
            return response()->json(['success' => true, 'publicite' => null]);
        }

        return response()->json([
            'success' => true,
            'publicite' => [
                'id' => $publicite->id,
                'image_url' => $publicite->image_url,
                'redirect_url' => $publicite->redirect_url,
                'expires_at' => $vue->vu_at->copy()->addMinutes(20)->timestamp * 1000,
            ],
        ]);
    }

    public function prisNote(Request $request, Publicite $publicite): JsonResponse
    {
        $vue = PubliciteVue::query()
            ->where('publicite_id', $publicite->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (! $vue->pris_note_at) {
            $vue->update(['pris_note_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}
