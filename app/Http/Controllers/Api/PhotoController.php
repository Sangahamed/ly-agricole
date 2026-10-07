<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhotoTerrain;
use App\Models\User;
use App\Support\Fichiers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Photos de l'appli terrain, envoyées une par une, À PART des opérations (skill
 * terrain-hors-ligne). Idempotent par l'UUID du téléphone : un renvoi répond
 * « deja_recu » sans réécrire le fichier.
 */
class PhotoController extends Controller
{
    public const TAILLE_MAX_KO = 5120;

    public function recevoir(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'uuid' => ['required', 'uuid'],
            'appareil_id' => ['required', 'string', 'max:100'],
            'fichier' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::TAILLE_MAX_KO],
            'prise_at' => ['nullable', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $uuid = strtolower($donnees['uuid']);

        if (PhotoTerrain::query()->whereKey($uuid)->exists()) {
            return response()->json(['uuid' => $uuid, 'statut' => 'deja_recu']);
        }

        $fichier = $request->file('fichier');
        $chemin = $fichier->storeAs('terrain/photos/'.substr($uuid, 0, 8), $uuid.'.'.$fichier->extension(), Fichiers::disque());

        try {
            PhotoTerrain::query()->create([
                'id' => $uuid,
                'user_id' => $user->id,
                'appareil_id' => $donnees['appareil_id'],
                'chemin' => $chemin,
                'mime' => (string) $fichier->getMimeType(),
                'taille_octets' => (int) $fichier->getSize(),
                'prise_at' => isset($donnees['prise_at']) ? Carbon::parse($donnees['prise_at']) : null,
                'lat' => $donnees['lat'] ?? null,
                'lng' => $donnees['lng'] ?? null,
                'recu_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Même photo reçue deux fois en même temps : l'autre envoi a gagné.
            return response()->json(['uuid' => $uuid, 'statut' => 'deja_recu']);
        }

        return response()->json(['uuid' => $uuid, 'statut' => 'accepte'], 201);
    }
}
