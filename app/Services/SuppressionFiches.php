<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Producteur;
use App\Models\User;
use App\Support\Fichiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vraie suppression d'une FICHE qui n'a encore servi à rien (décision du 2026-10-01 :
 * « Supprimer » pour la direction). Dès qu'un prêt, un achat, une visite ou une décision
 * s'y rattache, la fiche fait partie de l'historique : on la désactive, on ne l'efface pas.
 * La suppression laisse sa trace au journal (modèles journalisés).
 */
class SuppressionFiches
{
    public static function producteur(Producteur $producteur, User $auteur): void
    {
        if (! $auteur->can('annuler-operation', $producteur)) {
            throw new OperationRefusee('Seuls l\'auteur de la fiche et la direction peuvent la supprimer.');
        }

        DB::transaction(function () use ($producteur) {
            $producteur = Producteur::query()->lockForUpdate()->findOrFail($producteur->id);
            $parcelles = $producteur->parcelles()->pluck('id');

            $liens = array_filter([
                'prêt' => DB::table('prets')->where('producteur_id', $producteur->id)->count(),
                'achat' => DB::table('achats')->where('producteur_id', $producteur->id)->count(),
                'visite' => DB::table('visites')->whereIn('parcelle_id', $parcelles)->count(),
                'SMS envoyé' => DB::table('confirmations_sms')->where('producteur_id', $producteur->id)->count(),
                'décision de plafond' => DB::table('decisions_plafond')->where('producteur_id', $producteur->id)->count(),
                'dépense' => DB::table('depenses')->whereIn('parcelle_id', $parcelles)->count(),
                'groupe dont il est responsable' => DB::table('groupes_producteurs')->where('responsable_id', $producteur->id)->count(),
            ]);
            if ($liens !== []) {
                $texte = implode(', ', array_map(fn (string $quoi, int $n) => $n.' '.$quoi.($n > 1 && ! str_ends_with($quoi, 's') ? 's' : ''), array_keys($liens), $liens));
                throw new OperationRefusee("{$producteur->nomComplet()} a déjà servi ({$texte}) : sa fiche fait partie de l'historique et ne peut pas être supprimée. Désactivez-la à la place.");
            }

            // Une par une : chaque suppression laisse sa ligne au journal.
            $producteur->parcelles()->get()->each->delete();
            $photo = $producteur->photo;
            $producteur->delete();

            // Données personnelles : la photo part avec la fiche, une fois la suppression validée.
            if ($photo !== null) {
                DB::afterCommit(fn () => Storage::disk(Fichiers::disque())->delete($photo));
            }
        });
    }
}
