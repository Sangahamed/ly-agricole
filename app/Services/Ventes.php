<?php

namespace App\Services;

use App\Enums\CleParametre;
use App\Enums\Role;
use App\Enums\StatutAchat;
use App\Enums\StatutLot;
use App\Enums\StatutVente;
use App\Enums\TypeAcheteur;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\Encaissement;
use App\Models\Lot;
use App\Models\Parametre;
use App\Models\User;
use App\Models\Vente;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reventes bord-champ (cahier §7, stade Revente). L'encaissement est séparé
 * (App\Services\Encaissements) : une vente peut être payée à la livraison ou à terme
 * (question ouverte n° 15) — on ne suppose ni l'un ni l'autre.
 *
 * - Montant = intdiv(poids_net_g × prix + 500, 1000) : arrondi au franc, une seule fois.
 * - Au-dessus du seuil — ou seuil non défini — validation par une autre personne ; la
 *   sortie de stock ne se fait qu'à ce moment.
 * - Un lot qui atteint un stock nul (tous magasins) après une vente passe « vendu ».
 */
class Ventes
{
    public const GRAMMES_MAX = 100_000_000; // 100 t en une vente : borne de bon sens.

    /**
     * @param  array{campagne_id: int, lot_id: int, type_acheteur: TypeAcheteur, acheteur_nom: string, date_vente: Carbon, poids_net_g: int, prix_kg_fcfa: int, qualite_acceptee?: ?string, facture?: ?string, id?: string}  $donnees
     */
    public static function enregistrer(array $donnees, User $auteur): Vente
    {
        if (! $auteur->can('saisir-ventes')) {
            throw new OperationRefusee('Votre rôle ne permet pas de saisir une vente.');
        }

        return DB::transaction(function () use ($donnees, $auteur) {
            $campagne = Campagne::query()->findOrFail($donnees['campagne_id']);
            // Verrouillé tout de suite : deux ventes du même lot ne doivent pas survendre le stock.
            $lot = Lot::query()->with('magasin')->lockForUpdate()->findOrFail($donnees['lot_id']);

            if ($lot->campagne_id !== $campagne->id) {
                throw new OperationRefusee("Le lot {$lot->code} n'appartient pas à cette campagne.");
            }
            if (! in_array($lot->statut, [StatutLot::Ouvert, StatutLot::Ferme], true)) {
                throw new OperationRefusee("Le lot {$lot->code} est déjà vendu.");
            }
            if (trim($donnees['acheteur_nom']) === '') {
                throw new OperationRefusee('Le nom de l\'acheteur est obligatoire.');
            }
            if ($donnees['date_vente']->isAfter(now())) {
                throw new OperationRefusee('La date d\'une vente ne peut pas être dans le futur.');
            }

            $poids = $donnees['poids_net_g'];
            $prix = $donnees['prix_kg_fcfa'];
            if ($poids <= 0 || $poids > self::GRAMMES_MAX) {
                throw new OperationRefusee('Le poids vendu doit être supérieur à zéro.');
            }
            if ($prix <= 0) {
                throw new OperationRefusee('Le prix au kilo doit être supérieur à zéro.');
            }

            $stock = $lot->stock();
            if ($poids > $stock) {
                throw new OperationRefusee("Stock insuffisant du lot {$lot->code} : ".Format::kg($stock)
                    .' disponibles, '.Format::kg($poids).' demandés.');
            }

            $montant = intdiv($poids * $prix + 500, 1000);
            $seuil = Parametre::entier(CleParametre::SeuilValidationVente);

            $vente = Vente::query()->create([
                'id' => $donnees['id'] ?? null,
                'campagne_id' => $campagne->id,
                'lot_id' => $lot->id,
                'type_acheteur' => $donnees['type_acheteur'],
                'acheteur_nom' => trim($donnees['acheteur_nom']),
                'date_vente' => $donnees['date_vente'],
                'poids_net_g' => $poids,
                'prix_kg_fcfa' => $prix,
                'montant_fcfa' => $montant,
                'qualite_acceptee' => filled($donnees['qualite_acceptee'] ?? null) ? trim($donnees['qualite_acceptee']) : null,
                'facture' => $donnees['facture'] ?? null,
                'statut' => StatutVente::AValider,
                'cree_par' => $auteur->id,
            ]);

            if ($auteur->aLeRole(Role::Direction)) {
                // Compte supérieur : validée dès la saisie, à son nom (2026-10-06).
                self::executer($vente, $auteur, validation: true);
            } elseif ($seuil !== null && $montant <= $seuil) {
                self::executer($vente, $auteur);
            }

            return $vente->refresh();
        });
    }

    public static function valider(Vente $vente, User $validateur): Vente
    {
        return DB::transaction(function () use ($vente, $validateur) {
            $vente = self::relireAValider($vente, $validateur);
            self::executer($vente, $validateur, validation: true);

            return $vente->refresh();
        });
    }

    public static function refuser(Vente $vente, User $validateur, string $motif): Vente
    {
        if (mb_strlen(trim($motif)) < 5) {
            throw new OperationRefusee('Le motif du refus est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($vente, $validateur, $motif) {
            $vente = self::relireAValider($vente, $validateur);
            $vente->update(['statut' => StatutVente::Refuse, 'valide_par' => $validateur->id, 'valide_at' => now(), 'motif_refus' => trim($motif)]);

            return $vente;
        });
    }

    /**
     * « Supprimer » une vente, par son auteur ou la direction (2026-10-06) : rien ne s'efface.
     * À valider : elle passe « annulée », rien n'avait bougé. Validée : les encaissements sont
     * contre-passés (l'argent ressort du compte), les kilos reviennent dans le lot, qui
     * redevient « ouvert » s'il était passé « vendu ». Tout ou rien, motif obligatoire.
     */
    public static function annuler(Vente $vente, User $auteur, string $motif): Vente
    {
        if (! $auteur->can('annuler-operation', $vente)) {
            throw new OperationRefusee('Seuls l\'auteur de la vente et la direction peuvent la supprimer (annuler).');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif de l\'annulation est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($vente, $auteur, $motif) {
            $vente = Vente::query()->lockForUpdate()->findOrFail($vente->id);

            if (in_array($vente->statut, [StatutVente::Refuse, StatutVente::Annule], true)) {
                throw new OperationRefusee("La vente {$vente->reference} est déjà ".mb_strtolower($vente->statut->libelle()).'.');
            }

            if ($vente->statut === StatutVente::Valide) {
                $contrePasses = Encaissement::query()->where('vente_id', $vente->id)->whereNotNull('annule_id')->pluck('annule_id');
                $encaissements = Encaissement::query()->where('vente_id', $vente->id)->whereNull('annule_id')->whereNotIn('id', $contrePasses)
                    ->orderBy('id')->get();
                // L'argent encaissé ne ressort que par ceux qui encaissent (bureau), pas par l'agent auteur.
                if ($encaissements->isNotEmpty() && ! $auteur->can('encaisser-ventes')) {
                    throw new OperationRefusee('De l\'argent a déjà été encaissé sur cette vente : seuls la direction et la comptabilité peuvent l\'annuler.');
                }
                $encaissements->each(fn (Encaissement $e) => Encaissements::contrePasser($e, $motif, $auteur));

                Stock::annulerSortieVente($vente, $motif, $auteur);

                $lot = Lot::query()->lockForUpdate()->findOrFail($vente->lot_id);
                if ($lot->statut === StatutLot::Vendu && $lot->stock() > 0) {
                    $lot->update(['statut' => StatutLot::Ouvert]);
                }
            }

            $vente->update(['statut' => StatutVente::Annule, 'annule_par' => $auteur->id, 'annule_at' => now(), 'motif_annulation' => $motif]);

            return $vente->refresh();
        });
    }

    /** Sortie de stock, puis le lot passe « vendu » si son stock (tous magasins) tombe à zéro. */
    private static function executer(Vente $vente, User $auteur, bool $validation = false): void
    {
        Stock::sortieVente($vente, $auteur);

        $lot = Lot::query()->lockForUpdate()->findOrFail($vente->lot_id);
        if ($lot->statut === StatutLot::Ouvert && $lot->stock() === 0) {
            $lot->update(['statut' => StatutLot::Vendu]);
        }

        $vente->update(array_filter([
            'statut' => StatutVente::Valide,
            'valide_par' => $validation ? $auteur->id : null,
            'valide_at' => $validation ? now() : null,
        ], fn ($v) => $v !== null));
    }

    private static function relireAValider(Vente $vente, User $validateur): Vente
    {
        $vente = Vente::query()->lockForUpdate()->findOrFail($vente->id);

        if (! $validateur->can('valider-ventes')) {
            throw new OperationRefusee('Votre rôle ne permet pas de valider les ventes.');
        }
        // Séparation des tâches (D6, invariant 5).
        if ($vente->cree_par === $validateur->id) {
            throw new OperationRefusee('Vous ne pouvez pas valider ni refuser votre propre vente.');
        }
        if ($vente->statut !== StatutVente::AValider) {
            throw new OperationRefusee('Cette vente n\'est plus à valider (statut : '.$vente->statut->libelle().').');
        }

        return $vente;
    }

    /**
     * Marge d'un lot : revenu des ventes validées − coût des achats validés. Les frais de
     * transport, taxes et commissions à la revente ne sont pas encore rattachés au lot
     * (pas de lot_id sur les dépenses) : la marge affichée est donc une borne haute.
     *
     * @return array{cout: int, revenu: int, marge: int, kg_achetes: int, kg_vendus: int}
     */
    public static function margeLot(Lot $lot): array
    {
        $cout = (int) $lot->achats()->where('statut', StatutAchat::Valide)->sum('montant_fcfa');
        $kgAchetes = (int) $lot->achats()->where('statut', StatutAchat::Valide)->sum('poids_net_g');
        $revenu = (int) $lot->ventes()->where('statut', StatutVente::Valide)->sum('montant_fcfa');
        $kgVendus = (int) $lot->ventes()->where('statut', StatutVente::Valide)->sum('poids_net_g');

        return [
            'cout' => $cout,
            'revenu' => $revenu,
            'marge' => $revenu - $cout,
            'kg_achetes' => $kgAchetes,
            'kg_vendus' => $kgVendus,
        ];
    }
}
