<?php

namespace App\Services;

use App\Enums\StatutVente;
use App\Exceptions\OperationRefusee;
use App\Models\CompteTresorerie;
use App\Models\Encaissement;
use App\Models\MouvementTresorerie;
use App\Models\User;
use App\Models\Vente;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encaissements de ventes (registre immuable 🔒), stade séparé de la vente elle-même
 * (cahier §7) : l'acheteur peut payer en plusieurs fois, à la livraison ou à terme.
 *
 * Reste à encaisser = montant de la vente − Σ encaissements, jamais négatif (un
 * encaissement qui le dépasserait est refusé).
 */
class Encaissements
{
    public static function encaisser(Vente $vente, CompteTresorerie $compte, int $montant, Carbon $date, User $auteur, ?string $reference = null): Encaissement
    {
        if (! $auteur->can('encaisser-ventes')) {
            throw new OperationRefusee('Votre rôle ne permet pas d\'encaisser une vente.');
        }

        return DB::transaction(function () use ($vente, $compte, $montant, $date, $auteur, $reference) {
            $vente = Vente::query()->lockForUpdate()->findOrFail($vente->id);
            if (in_array($vente->statut, [StatutVente::Refuse, StatutVente::Annule], true)) {
                throw new OperationRefusee("La vente {$vente->reference} est ".mb_strtolower($vente->statut->libelle()).' : rien à encaisser.');
            }
            $reste = $vente->resteAEncaisser();
            if ($montant <= 0 || $montant > $reste) {
                throw new OperationRefusee('Le montant encaissé doit être compris entre 1 et '.Format::fcfa($reste).' (reste à encaisser).');
            }

            $mouvement = Tresorerie::encaisserVente($vente, $compte, $montant, $date, $auteur, $reference);

            return Encaissement::query()->create([
                'vente_id' => $vente->id,
                'montant_fcfa' => $montant,
                'compte_id' => $compte->id,
                'date_encaissement' => $date->toDateString(),
                'reference_paiement' => $reference,
                'mouvement_id' => $mouvement->id,
                'cree_par' => $auteur->id,
            ]);
        });
    }

    /** Annule un encaissement par une ligne négative ; l'argent ressort aussi de la trésorerie. */
    public static function contrePasser(Encaissement $encaissement, string $motif, User $auteur): Encaissement
    {
        if (! $auteur->can('encaisser-ventes')) {
            throw new OperationRefusee('Votre rôle ne permet pas de corriger un encaissement.');
        }
        if (mb_strlen(trim($motif)) < 5) {
            throw new OperationRefusee('Le motif est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($encaissement, $motif, $auteur) {
            $original = Encaissement::query()->lockForUpdate()->findOrFail($encaissement->id);
            if (Encaissement::query()->where('annule_id', $original->id)->exists()) {
                throw new OperationRefusee('Cet encaissement a déjà été contre-passé.');
            }

            $mouvementInverse = null;
            if ($original->mouvement_id !== null) {
                $mouvementInverse = Tresorerie::contrePasser(
                    MouvementTresorerie::query()->findOrFail($original->mouvement_id), trim($motif), $auteur, depuisOrigine: true,
                )[0];
            }

            return Encaissement::query()->create([
                'vente_id' => $original->vente_id,
                'montant_fcfa' => -$original->montant_fcfa,
                'compte_id' => $original->compte_id,
                'date_encaissement' => Carbon::today()->toDateString(),
                'mouvement_id' => $mouvementInverse?->id,
                'motif' => trim($motif),
                'annule_id' => $original->id,
                'cree_par' => $auteur->id,
            ]);
        });
    }
}
