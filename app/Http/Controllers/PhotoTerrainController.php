<?php

namespace App\Http\Controllers;

use App\Models\PhotoTerrain;
use App\Support\Fichiers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo prise sur le terrain, sur le disque privé : pour qui valide les achats ou tient
 * la trésorerie, et pour l'agent qui l'a prise. Une photo de visite de parcelle est
 * aussi visible de qui voit les visites (l'agronome) — pas les autres photos.
 */
class PhotoTerrainController extends Controller
{
    public function afficher(Request $request, PhotoTerrain $photo): StreamedResponse
    {
        $moi = $request->user();
        abort_unless($moi !== null && (
            $moi->can('valider-achats') || $moi->can('gerer-tresorerie') || $photo->user_id === $moi->id
            || ($moi->can('voir-visites') && $photo->visites()->exists())
        ), 403);
        abort_unless(Storage::disk(Fichiers::disque())->exists($photo->chemin), 404);

        return Storage::disk(Fichiers::disque())->response($photo->chemin, headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
