<?php

namespace App\Http\Controllers;

use App\Models\Decaissement;
use App\Models\MouvementIntrant;
use App\Models\Pret;
use App\Services\RecuRemise;
use App\Support\Fichiers;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pièces des prêts, sur le disque privé : seulement pour qui voit les prêts. */
class PretController extends Controller
{
    public function accord(Pret $pret): StreamedResponse
    {
        return $this->servir($pret->accord_ecrit);
    }

    public function recu(Decaissement $decaissement): StreamedResponse
    {
        return $this->servir($decaissement->justificatif);
    }

    public function recuPdf(Pret $pret, string $type, int $id): Response
    {
        Gate::authorize('voir-prets');

        return match ($type) {
            'argent' => RecuRemise::argent($pret, Decaissement::query()->findOrFail($id)),
            'intrants' => RecuRemise::intrants($pret, MouvementIntrant::query()->findOrFail($id)),
            default => abort(404),
        };
    }

    private function servir(?string $chemin): StreamedResponse
    {
        Gate::authorize('voir-prets');
        abort_if($chemin === null || ! Storage::disk(Fichiers::disque())->exists($chemin), 404);

        return Storage::disk(Fichiers::disque())->response($chemin, headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
