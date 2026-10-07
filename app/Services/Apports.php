<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\OperationRefusee;
use App\Models\Apport;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\MouvementTresorerie;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Apports de campagne (registre immuable 🔒), contrat art. 5 et 9 (apport de LY facultatif).
 * Qui apporte : un investisseur à compte, un investisseur SANS compte (son nom, depuis le
 * 2026-10-07), ou LY. Compte : tout compte actif depuis le 2026-10-07 (choix du développeur) —
 * l'art. 5 (compte dédié à la campagne) n'est plus imposé par le logiciel, voir DECISIONS.
 *
 * Ne calcule PAS le résultat net ni les quotes-parts : il ne fait que tracer qui a apporté
 * combien, à quelle campagne. Le partage (contrat art. 10 à 14) est dans PartageResultat
 * et ResultatCampagne, qui lisent ces apports.
 */
class Apports
{
    /**
     * @param  int|null  $investisseurId  investisseur à compte ; null avec `$apporteurNom` null = LY elle-même.
     * @param  string|null  $apporteurNom  investisseur sans compte (exclusif de `$investisseurId`).
     */
    public static function enregistrer(?int $investisseurId, int $campagneId, int $compteId, int $montant, Carbon $date, User $auteur, ?string $motif = null, ?string $apporteurNom = null): Apport
    {
        if (! $auteur->can('gerer-apports')) {
            throw new OperationRefusee('Votre rôle ne permet pas d\'enregistrer un apport.');
        }
        $apporteurNom = self::nomPropre($apporteurNom);
        if ($investisseurId !== null && $apporteurNom !== null) {
            throw new OperationRefusee('Choisir un investisseur à compte OU saisir un nom, pas les deux.');
        }

        return DB::transaction(function () use ($investisseurId, $campagneId, $compteId, $montant, $date, $auteur, $motif, $apporteurNom) {
            $campagne = Campagne::query()->findOrFail($campagneId);
            $compte = CompteTresorerie::query()->findOrFail($compteId);

            // Fin de campagne passée : plus d'argent qui entre (constaté en production le 2026-10-07).
            $campagne->exigerEnCours('apport', $date);
            if (! $compte->actif) {
                throw new OperationRefusee("Le compte « {$compte->nom} » est désactivé.");
            }
            if ($montant <= 0) {
                throw new OperationRefusee('Le montant apporté doit être supérieur à zéro.');
            }

            $investisseur = null;
            if ($investisseurId !== null) {
                $investisseur = User::query()->findOrFail($investisseurId);
                if (! $investisseur->aLeRole(Role::Investisseur)) {
                    throw new OperationRefusee("{$investisseur->nom} n'a pas le rôle investisseur.");
                }
            }

            // La ligne immuable se crée en un seul appel, mouvement déjà en main : le
            // mouvement ne peut pas référencer l'apport (qui n'existe pas encore), il
            // référence la campagne, seule chose déjà stable à ce stade.
            $mouvement = Tresorerie::enregistrerApport($campagne, $investisseur, $compte, $montant, $date, $auteur, $apporteurNom);

            return Apport::query()->create([
                'investisseur_id' => $investisseur?->id,
                'apporteur_nom' => $apporteurNom,
                'campagne_id' => $campagne->id,
                'montant_fcfa' => $montant,
                'date_apport' => $date->toDateString(),
                'motif' => filled($motif) ? trim($motif) : null,
                'mouvement_id' => $mouvement->id,
                'cree_par' => $auteur->id,
            ]);
        });
    }

    /** Annule un apport par une ligne négative ; l'argent ressort aussi de la trésorerie. */
    public static function contrePasser(Apport $apport, string $motif, User $auteur): Apport
    {
        if (! $auteur->can('gerer-apports')) {
            throw new OperationRefusee('Votre rôle ne permet pas de corriger un apport.');
        }
        if (mb_strlen(trim($motif)) < 5) {
            throw new OperationRefusee('Le motif est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($apport, $motif, $auteur) {
            $original = Apport::query()->lockForUpdate()->findOrFail($apport->id);
            if (Apport::query()->where('annule_id', $original->id)->exists()) {
                throw new OperationRefusee('Cet apport a déjà été contre-passé.');
            }

            $mouvementInverse = null;
            if ($original->mouvement_id !== null) {
                $mouvementInverse = Tresorerie::contrePasser(
                    MouvementTresorerie::query()->findOrFail($original->mouvement_id), trim($motif), $auteur, depuisOrigine: true,
                )[0];
            }

            return Apport::query()->create([
                'investisseur_id' => $original->investisseur_id,
                'apporteur_nom' => $original->apporteur_nom,
                'campagne_id' => $original->campagne_id,
                'montant_fcfa' => -$original->montant_fcfa,
                'date_apport' => Carbon::today()->toDateString(),
                'motif' => trim($motif),
                'mouvement_id' => $mouvementInverse?->id,
                'annule_id' => $original->id,
                'cree_par' => $auteur->id,
            ]);
        });
    }

    /**
     * Répartition des apports d'une campagne : chaque investisseur, son apport net (les
     * contre-passations le compensent), et sa part de l'ensemble des apports
     * d'investisseurs (PAS une quote-part du résultat — voir l'avertissement en tête de
     * fichier). Triée par apport décroissant.
     *
     * `cle` identifie l'investisseur : son id (entier) s'il a un compte, « nom:<nom en
     * minuscules> » sinon — deux saisies du même nom, à la casse et aux espaces près, s'additionnent.
     *
     * @return array{parLy: int, parInvestisseurs: int, lignes: Collection<int, array{cle: int|string, nom: string, investisseur: User|null, montant: int, part_pour_mille: int}>}
     */
    public static function repartition(Campagne $campagne): array
    {
        $apports = Apport::query()->where('campagne_id', $campagne->id)->get(['investisseur_id', 'apporteur_nom', 'montant_fcfa']);
        $parLy = (int) $apports->filter(fn (Apport $a) => $a->estDeLy())->sum('montant_fcfa');

        /** @var array<int|string, array{nom: string|null, id: int|null, montant: int}> $groupes */
        $groupes = [];
        foreach ($apports->reject(fn (Apport $a) => $a->estDeLy()) as $a) {
            $cle = $a->investisseur_id ?? 'nom:'.mb_strtolower((string) $a->apporteur_nom);
            $groupes[$cle] ??= ['nom' => $a->apporteur_nom, 'id' => $a->investisseur_id, 'montant' => 0];
            $groupes[$cle]['montant'] += $a->montant_fcfa;
        }
        $groupes = array_filter($groupes, fn (array $g) => $g['montant'] > 0);

        $total = array_sum(array_column($groupes, 'montant'));
        $utilisateurs = User::query()->whereKey(array_filter(array_column($groupes, 'id')))->get()->keyBy('id');

        $lignes = collect($groupes)->map(function (array $g, int|string $cle) use ($utilisateurs, $total) {
            $user = $g['id'] === null ? null : ($utilisateurs->get($g['id']) ?? throw new OperationRefusee("Investisseur #{$g['id']} introuvable."));

            return [
                'cle' => $cle,
                'nom' => $user->nom ?? (string) $g['nom'],
                'investisseur' => $user,
                'montant' => $g['montant'],
                // Millièmes plutôt que pourcentage entier : plus précis pour de petits apports.
                'part_pour_mille' => $total > 0 ? intdiv($g['montant'] * 1000, $total) : 0,
            ];
        })->sortByDesc('montant')->values();

        return ['parLy' => $parLy, 'parInvestisseurs' => $total, 'lignes' => $lignes];
    }

    /** Nom saisi : espaces resserrés, 150 caractères au plus ; vide = pas de nom. */
    private static function nomPropre(?string $nom): ?string
    {
        $nom = trim((string) preg_replace('/\s+/u', ' ', (string) $nom));

        return $nom === '' ? null : mb_substr($nom, 0, 150);
    }
}
