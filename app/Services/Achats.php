<?php

namespace App\Services;

use App\Enums\CleParametre;
use App\Enums\Role;
use App\Enums\SourcePoids;
use App\Enums\StatutAchat;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutPret;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Models\Achat;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\MouvementTresorerie;
use App\Models\Parametre;
use App\Models\Pisteur;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Achats bord-champ (cahier §7, skill argent-et-kilos §3).
 *
 * - Montant = intdiv(poids_net_g × prix + 500, 1000) : arrondi au franc, une seule fois.
 * - Prix officiel bord-champ de la campagne (s'il est fixé) = prix minimum : en dessous,
 *   refus.
 * - Producteur sous prêt : les kilos retenus remboursent le prêt (règle de la question 3,
 *   choisie par la direction) ; le reste est payé en espèces.
 * - Au-dessus du seuil — ou seuil non défini — validation par une autre personne ; stock,
 *   remboursement et paiement ne se font qu'à ce moment, en une transaction.
 * - Un agent paie depuis sa propre caisse (la caisse de l'agent baisse).
 */
class Achats
{
    public const GRAMMES_MAX = 100_000_000; // 100 t en une pesée : borne de bon sens.

    /**
     * @param  array{campagne_id: int, lot_id: int, fournisseur_type: TypeFournisseur, producteur_id?: ?string, pisteur_id?: ?int, fournisseur_nom?: ?string, point_collecte_id?: ?int, date_achat: Carbon, poids_brut_g: int, tare_g: int, humidite_pour_mille?: ?int, kor_centieme_lbs?: ?int, grainage_noix_kg?: ?int, prix_kg_fcfa: int, poids_source?: SourcePoids|string|null, pret_id?: ?string, grammes_rembourses?: int, compte_id: int, id?: string, photo_pesee?: ?string}  $donnees
     */
    public static function enregistrer(array $donnees, User $auteur): Achat
    {
        if (! $auteur->can('saisir-achats')) {
            throw new OperationRefusee('Votre rôle ne permet pas de saisir un achat.');
        }

        return DB::transaction(function () use ($donnees, $auteur) {
            $campagne = Campagne::query()->findOrFail($donnees['campagne_id']);
            $lot = Lot::query()->findOrFail($donnees['lot_id']);
            $compte = CompteTresorerie::query()->findOrFail($donnees['compte_id']);
            $type = $donnees['fournisseur_type'];
            $date = $donnees['date_achat'];
            $prix = $donnees['prix_kg_fcfa'];

            if ($campagne->statut !== StatutCampagne::Ouverte) {
                throw new OperationRefusee("La campagne {$campagne->code} n'est pas ouverte : pas d'achat.");
            }
            if ($lot->campagne_id !== $campagne->id || $lot->statut !== StatutLot::Ouvert) {
                throw new OperationRefusee("Le lot {$lot->code} n'est pas un lot ouvert de cette campagne.");
            }
            if ($date->isAfter(now())) {
                throw new OperationRefusee('La date d\'un achat ne peut pas être dans le futur.');
            }

            // Pesée, en grammes (D4).
            $brut = $donnees['poids_brut_g'];
            $tare = $donnees['tare_g'];
            // Trace, pas un contrôle : balance Bluetooth ou saisie à la main (null = non transmis).
            $source = $donnees['poids_source'] ?? null;
            if ($source !== null && ! $source instanceof SourcePoids) {
                $source = SourcePoids::tryFrom((string) $source)
                    ?? throw new OperationRefusee('Source du poids inconnue : « balance » ou « manuel ».');
            }
            $net = $brut - $tare;
            if ($brut <= 0 || $tare < 0 || $net <= 0 || $brut > self::GRAMMES_MAX) {
                throw new OperationRefusee('Pesée incohérente : le poids net (brut − tare) doit être supérieur à zéro.');
            }

            if ($prix <= 0) {
                throw new OperationRefusee('Le prix au kilo doit être supérieur à zéro.');
            }
            if ($campagne->prix_officiel_kg_fcfa !== null && $prix < $campagne->prix_officiel_kg_fcfa) {
                throw new OperationRefusee('Prix inférieur au prix officiel bord-champ de la campagne ('
                    .Format::fcfa($campagne->prix_officiel_kg_fcfa).'/kg) : achat refusé.');
            }
            self::verifierQualite($donnees);
            self::verifierFournisseur($type, $donnees);

            if (! Depenses::peutPayerDepuis($auteur, $compte)) {
                throw new OperationRefusee("Vous ne pouvez pas payer depuis « {$compte->nom} » : un agent paie depuis sa propre caisse.");
            }

            $montant = intdiv($net * $prix + 500, 1000);
            // Commission due au pisteur : vide tant que la direction n'a pas activé le calcul (question 6).
            $commission = $type === TypeFournisseur::Pisteur
                ? CommissionsPisteur::calculer(Pisteur::query()->find((int) ($donnees['pisteur_id'] ?? 0)), $net, $montant)
                : null;

            // Prêt : kilos retenus pour le remboursement.
            $pret = null;
            $grammesRembourses = 0;
            if (filled($donnees['pret_id'] ?? null)) {
                $pret = Pret::query()->findOrFail($donnees['pret_id']);
                if ($type !== TypeFournisseur::Producteur || $pret->producteur_id !== ($donnees['producteur_id'] ?? null)) {
                    throw new OperationRefusee('Ce prêt n\'est pas celui de ce producteur.');
                }
                if (! in_array($pret->statut, [StatutPret::Valide, StatutPret::Decaisse], true) || $pret->restantDu() <= 0) {
                    throw new OperationRefusee("Le prêt {$pret->reference} n'a rien à rembourser.");
                }
                // Refus tout de suite si la règle de la question 3 n'est pas choisie.
                Remboursements::prixNature($pret, $prix);

                $grammesRembourses = $donnees['grammes_rembourses'] ?? 0;
                if ($grammesRembourses <= 0 || $grammesRembourses > $net) {
                    throw new OperationRefusee('Les kilos retenus pour le prêt doivent être entre 1 g et le poids net.');
                }
            }

            $especes = intdiv(($net - $grammesRembourses) * $prix + 500, 1000);
            $seuil = Parametre::entier(CleParametre::SeuilValidationAchat);

            $achat = Achat::query()->create([
                'id' => $donnees['id'] ?? null,
                'campagne_id' => $campagne->id,
                'lot_id' => $lot->id,
                'fournisseur_type' => $type,
                'producteur_id' => $type === TypeFournisseur::Producteur ? $donnees['producteur_id'] : null,
                'pisteur_id' => $type === TypeFournisseur::Pisteur ? $donnees['pisteur_id'] : null,
                'fournisseur_nom' => $type === TypeFournisseur::Cooperative ? trim((string) $donnees['fournisseur_nom']) : null,
                'point_collecte_id' => $donnees['point_collecte_id'] ?? null,
                'date_achat' => $date,
                'poids_brut_g' => $brut,
                'tare_g' => $tare,
                'poids_net_g' => $net,
                'poids_source' => $source,
                'humidite_pour_mille' => $donnees['humidite_pour_mille'] ?? null,
                'kor_centieme_lbs' => $donnees['kor_centieme_lbs'] ?? null,
                'grainage_noix_kg' => $donnees['grainage_noix_kg'] ?? null,
                'prix_kg_fcfa' => $prix,
                'montant_fcfa' => $montant,
                'commission_pisteur_fcfa' => $commission,
                'pret_id' => $pret?->id,
                'grammes_rembourses' => $grammesRembourses,
                'montant_especes_fcfa' => $especes,
                'compte_id' => $compte->id,
                // UUID d'une photo du terrain (photos_terrain), qui peut arriver après l'achat.
                'photo_pesee' => $donnees['photo_pesee'] ?? null,
                'statut' => StatutAchat::AValider,
                'cree_par' => $auteur->id,
            ]);

            if ($auteur->aLeRole(Role::Direction)) {
                // Compte supérieur : validé dès la saisie, à son nom (2026-10-06).
                self::executer($achat, $auteur, validation: true);
            } elseif ($seuil !== null && $montant <= $seuil) {
                self::executer($achat, $auteur);
            }

            return $achat->refresh();
        });
    }

    public static function valider(Achat $achat, User $validateur): Achat
    {
        return DB::transaction(function () use ($achat, $validateur) {
            $achat = self::relireAValider($achat, $validateur);
            self::executer($achat, $validateur, validation: true);

            return $achat->refresh();
        });
    }

    public static function refuser(Achat $achat, User $validateur, string $motif): Achat
    {
        if (mb_strlen(trim($motif)) < 5) {
            throw new OperationRefusee('Le motif du refus est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($achat, $validateur, $motif) {
            $achat = self::relireAValider($achat, $validateur);
            $achat->update(['statut' => StatutAchat::Refuse, 'valide_par' => $validateur->id, 'valide_at' => now(), 'motif_refus' => trim($motif)]);

            return $achat;
        });
    }

    /**
     * « Supprimer » un achat, par son auteur ou la direction (2026-10-01, puis 2026-10-06) : rien ne s'efface.
     * À valider : il passe « annulé », aucun effet n'avait eu lieu. Validé : ses effets sont
     * contre-passés ensemble — les kilos ressortent du lot (refusé s'ils n'y sont plus), le
     * remboursement en kilos est repris, l'argent payé revient dans la caisse. Motif obligatoire.
     */
    public static function annuler(Achat $achat, User $auteur, string $motif): Achat
    {
        if (! $auteur->can('annuler-operation', $achat)) {
            throw new OperationRefusee('Seuls l\'auteur de l\'achat et la direction peuvent le supprimer (annuler).');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif de l\'annulation est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($achat, $auteur, $motif) {
            $achat = Achat::query()->lockForUpdate()->findOrFail($achat->id);

            if (in_array($achat->statut, [StatutAchat::Refuse, StatutAchat::Annule], true)) {
                throw new OperationRefusee("L'achat {$achat->reference} est déjà ".mb_strtolower($achat->statut->libelle()).'.');
            }

            if ($achat->statut === StatutAchat::Valide) {
                Stock::annulerEntreeAchat($achat, $motif, $auteur);
                Remboursements::annulerNature($achat, $motif, $auteur);
                if ($achat->mouvement_id !== null) {
                    Tresorerie::contrePasser(MouvementTresorerie::query()->findOrFail($achat->mouvement_id), $motif, $auteur, depuisOrigine: true);
                }
            }

            $achat->update(['statut' => StatutAchat::Annule, 'annule_par' => $auteur->id, 'annule_at' => now(), 'motif_annulation' => $motif]);

            return $achat->refresh();
        });
    }

    /** Stock + remboursement en kilos + paiement en espèces : tout ou rien. */
    private static function executer(Achat $achat, User $auteur, bool $validation = false): void
    {
        Stock::entreeAchat($achat, $auteur);

        if ($achat->pret_id !== null && $achat->grammes_rembourses > 0) {
            Remboursements::nature(Pret::query()->findOrFail($achat->pret_id), $achat, $achat->grammes_rembourses, $auteur);
        }

        $mouvementId = null;
        if ($achat->montant_especes_fcfa > 0) {
            // Le paiement part au nom de l'agent qui a acheté : c'est sa caisse qui baisse.
            $mouvementId = Tresorerie::payerAchat($achat, $achat->montant_especes_fcfa, $auteur)->id;
        }

        $achat->update(array_filter([
            'statut' => StatutAchat::Valide,
            'mouvement_id' => $mouvementId,
            'valide_par' => $validation ? $auteur->id : null,
            'valide_at' => $validation ? now() : null,
        ], fn ($v) => $v !== null));

        ConfirmationsSms::pourAchat($achat->refresh());
    }

    private static function relireAValider(Achat $achat, User $validateur): Achat
    {
        $achat = Achat::query()->lockForUpdate()->findOrFail($achat->id);

        if (! $validateur->can('valider-achats')) {
            throw new OperationRefusee('Votre rôle ne permet pas de valider les achats.');
        }
        // Séparation des tâches (D6, invariant 5).
        if ($achat->cree_par === $validateur->id) {
            throw new OperationRefusee('Vous ne pouvez pas valider ni refuser votre propre achat.');
        }
        if ($achat->statut !== StatutAchat::AValider) {
            throw new OperationRefusee('Cet achat n\'est plus à valider (statut : '.$achat->statut->libelle().').');
        }

        return $achat;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private static function verifierQualite(array $donnees): void
    {
        $humidite = $donnees['humidite_pour_mille'] ?? null;
        $kor = $donnees['kor_centieme_lbs'] ?? null;
        $grainage = $donnees['grainage_noix_kg'] ?? null;

        if ($humidite !== null && ($humidite < 0 || $humidite > 1000)) {
            throw new OperationRefusee('L\'humidité est un pourcentage entre 0 et 100.');
        }
        // KOR en livres d'amande par sac de 80 kg (176 lbs) : ne peut pas dépasser le sac.
        if ($kor !== null && ($kor <= 0 || $kor > 17_600)) {
            throw new OperationRefusee('Le KOR (livres d\'amande par sac de 80 kg) est invalide.');
        }
        if ($grainage !== null && ($grainage <= 0 || $grainage > 2_000)) {
            throw new OperationRefusee('Le grainage (nombre de noix par kilo) est invalide.');
        }
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private static function verifierFournisseur(TypeFournisseur $type, array $donnees): void
    {
        match ($type) {
            TypeFournisseur::Producteur => Producteur::query()->where('actif', true)->findOr((string) ($donnees['producteur_id'] ?? ''),
                fn () => throw new OperationRefusee('Producteur introuvable ou désactivé.')),
            TypeFournisseur::Pisteur => Pisteur::query()->where('actif', true)->findOr((int) ($donnees['pisteur_id'] ?? 0),
                fn () => throw new OperationRefusee('Pisteur introuvable ou désactivé.')),
            TypeFournisseur::Cooperative => blank($donnees['fournisseur_nom'] ?? null)
                ? throw new OperationRefusee('Indiquer le nom de la coopérative.') : null,
        };
    }
}
