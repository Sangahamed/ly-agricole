<?php

namespace App\Services;

use App\Enums\CleParametre;
use App\Enums\Role;
use App\Enums\StatutDepense;
use App\Exceptions\OperationRefusee;
use App\Models\CategorieDepense;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\MouvementTresorerie;
use App\Models\Parametre;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Règles des dépenses (cahier §8, contrat art. 10.3, D6).
 *
 * - Au-dessus du seuil — ou si le seuil n'est pas défini — la dépense attend la
 *   validation d'une AUTRE personne que son auteur ; l'argent ne sort qu'à ce moment.
 * - En dessous : payée tout de suite.
 * - Une catégorie exclue par l'art. 10.3 est refusée sur un compte de campagne et sur
 *   une dépense rattachée à une campagne.
 * - Un agent ne paie que depuis sa propre caisse.
 */
class Depenses
{
    /**
     * @param  array{categorie_id: int, compte_id: int, montant_fcfa: int, date_depense: Carbon, beneficiaire: string, description?: ?string, campagne_id?: ?int, parcelle_id?: ?string, id?: string}  $donnees
     */
    public static function saisir(array $donnees, string $justificatif, User $auteur): Depense
    {
        return DB::transaction(function () use ($donnees, $justificatif, $auteur) {
            $categorie = CategorieDepense::query()->findOrFail($donnees['categorie_id']);
            $compte = CompteTresorerie::query()->findOrFail($donnees['compte_id']);
            $montant = $donnees['montant_fcfa'];

            if (! $categorie->actif) {
                throw new OperationRefusee("La catégorie « {$categorie->nom} » est désactivée.");
            }
            if (! self::peutPayerDepuis($auteur, $compte)) {
                throw new OperationRefusee("Vous ne pouvez pas payer depuis le compte « {$compte->nom} » : un agent paie depuis sa propre caisse.");
            }
            if ($categorie->exclue_fonds_campagne && ($compte->campagne_id !== null || filled($donnees['campagne_id'] ?? null))) {
                throw new OperationRefusee("La catégorie « {$categorie->nom} » est exclue des fonds de campagne (contrat, art. 10.3).");
            }

            $depense = Depense::query()->create([
                'id' => $donnees['id'] ?? null,
                'categorie_id' => $categorie->id,
                'compte_id' => $compte->id,
                'montant_fcfa' => $montant,
                'date_depense' => $donnees['date_depense']->toDateString(),
                'beneficiaire' => trim($donnees['beneficiaire']),
                'description' => filled($donnees['description'] ?? null) ? trim((string) $donnees['description']) : null,
                'justificatif' => $justificatif,
                'campagne_id' => $donnees['campagne_id'] ?? null,
                'parcelle_id' => $donnees['parcelle_id'] ?? null,
                'statut' => StatutDepense::AValider,
                'cree_par' => $auteur->id,
            ]);

            if ($auteur->aLeRole(Role::Direction)) {
                // Compte supérieur : payée dès la saisie, validée à son nom (2026-10-06).
                $mouvement = Tresorerie::payerDepense($depense, $auteur);
                $depense->update(['statut' => StatutDepense::Payee, 'mouvement_id' => $mouvement->id, 'valide_par' => $auteur->id, 'valide_at' => now()]);
            } elseif (! self::exigeValidation($montant)) {
                $mouvement = Tresorerie::payerDepense($depense, $auteur);
                $depense->update(['statut' => StatutDepense::Payee, 'mouvement_id' => $mouvement->id]);
            }

            return $depense->refresh();
        });
    }

    /** Seuil non défini ⇒ toujours valider (un seuil absent n'est pas « pas de contrôle »). */
    public static function exigeValidation(int $montant): bool
    {
        $seuil = Parametre::entier(CleParametre::SeuilValidationDepense);

        return $seuil === null || $montant > $seuil;
    }

    public static function valider(Depense $depense, User $validateur): Depense
    {
        return DB::transaction(function () use ($depense, $validateur) {
            $depense = self::relireAValider($depense, $validateur);

            $mouvement = Tresorerie::payerDepense($depense, $validateur);
            $depense->update([
                'statut' => StatutDepense::Payee,
                'valide_par' => $validateur->id,
                'valide_at' => now(),
                'mouvement_id' => $mouvement->id,
            ]);

            return $depense;
        });
    }

    public static function refuser(Depense $depense, User $validateur, string $motif): Depense
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif du refus est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($depense, $validateur, $motif) {
            $depense = self::relireAValider($depense, $validateur);

            $depense->update([
                'statut' => StatutDepense::Refusee,
                'valide_par' => $validateur->id,
                'valide_at' => now(),
                'motif_refus' => $motif,
            ]);

            return $depense;
        });
    }

    /**
     * « Supprimer » une dépense, par son auteur ou la direction (2026-10-01, puis 2026-10-06) : rien ne
     * s'efface. À valider : elle passe « annulée », rien n'avait été payé. Payée : le paiement
     * est contre-passé (l'argent revient dans la caisse), elle passe « annulée ».
     */
    public static function annuler(Depense $depense, User $auteur, string $motif): Depense
    {
        if (! $auteur->can('annuler-operation', $depense)) {
            throw new OperationRefusee('Seuls l\'auteur de la dépense et la direction peuvent la supprimer (annuler).');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif de l\'annulation est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($depense, $auteur, $motif) {
            $depense = Depense::query()->lockForUpdate()->findOrFail($depense->id);

            if ($depense->statut === StatutDepense::Payee && $depense->mouvement_id !== null) {
                Tresorerie::contrePasser(MouvementTresorerie::query()->findOrFail($depense->mouvement_id), $motif, $auteur);
            } elseif ($depense->statut !== StatutDepense::AValider) {
                throw new OperationRefusee('Cette dépense est déjà '.mb_strtolower($depense->statut->libelle()).'.');
            }

            $depense->update(['statut' => StatutDepense::Annulee, 'motif_refus' => 'Annulée par '.$auteur->nom.' : '.$motif]);

            return $depense->refresh();
        });
    }

    public static function peutPayerDepuis(User $user, CompteTresorerie $compte): bool
    {
        if (! $compte->actif) {
            return false;
        }

        return $user->aLeRole(Role::Direction, Role::Comptable) || $compte->titulaire_id === $user->id;
    }

    /** Relecture sous verrou : deux validateurs ne paient pas deux fois la même dépense. */
    private static function relireAValider(Depense $depense, User $validateur): Depense
    {
        $depense = Depense::query()->lockForUpdate()->findOrFail($depense->id);

        if (! $validateur->can('valider-depenses')) {
            throw new OperationRefusee('Votre rôle ne permet pas de valider les dépenses.');
        }
        // Séparation des tâches (D6, invariant 5) : vérifiée ici, pas seulement à l'écran.
        if ($depense->cree_par === $validateur->id) {
            throw new OperationRefusee('Vous ne pouvez pas valider votre propre dépense : une autre personne doit le faire.');
        }
        if ($depense->statut !== StatutDepense::AValider) {
            throw new OperationRefusee('Cette dépense n\'est plus à valider (statut : '.$depense->statut->libelle().').');
        }

        return $depense;
    }

    public static function libelleSeuil(): string
    {
        $seuil = Parametre::entier(CleParametre::SeuilValidationDepense);

        return $seuil === null
            ? 'Seuil de validation non défini : toute dépense doit être validée par une autre personne.'
            : 'Au-dessus de '.Format::fcfa($seuil).', une dépense doit être validée par une autre personne.';
    }
}
