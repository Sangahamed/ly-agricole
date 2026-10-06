<?php

namespace App\Services;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutPret;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Decaissement;
use App\Models\Parametre;
use App\Models\Parcelle;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Models\ValidationPret;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Règles des prêts de campagne (cahier §3, contrat art. 9.2 et 17.3, D6).
 *
 * - Toute demande est validée par une autre personne que son auteur ; au-dessus du
 *   seuil — ou si le seuil n'est pas défini — par DEUX personnes distinctes. Sauf un prêt
 *   saisi par la direction : accordé dès la saisie (2026-10-06).
 * - Plafonds par producteur et par hectare, s'ils sont définis.
 * - Proche de la direction (art. 17.3) : accord écrit joint, sinon refus.
 * - Décaissement par tranches, jamais au-delà du montant ; chaque tranche est un
 *   mouvement de trésorerie (la caisse baisse d'autant).
 * - Aucun intérêt ni marge (question 4) ; les kilos attendus sont une ESTIMATION au
 *   prix de référence (question 3 : la valorisation des livraisons reste à trancher).
 */
class Prets
{
    /**
     * @param  array{producteur_id: string, campagne_id: int, montant_fcfa: int, forme: FormePret, echeance: Carbon, prix_reference_kg_fcfa?: ?int, parcelle_ids?: list<string>, partie_liee?: bool}  $donnees
     */
    public static function demander(array $donnees, User $auteur, ?string $accordEcrit = null): Pret
    {
        return DB::transaction(function () use ($donnees, $auteur, $accordEcrit) {
            $producteur = Producteur::query()->findOrFail($donnees['producteur_id']);
            $campagne = Campagne::query()->findOrFail($donnees['campagne_id']);
            $montant = $donnees['montant_fcfa'];
            $prix = $donnees['prix_reference_kg_fcfa'] ?? null;
            $partieLiee = (bool) ($donnees['partie_liee'] ?? false);

            if (! $producteur->actif) {
                throw new OperationRefusee('Ce producteur est désactivé.');
            }
            if ($campagne->statut === StatutCampagne::Cloturee) {
                throw new OperationRefusee("La campagne {$campagne->code} est clôturée.");
            }
            if ($montant <= 0 || $montant > Tresorerie::MONTANT_MAX) {
                throw new OperationRefusee('Le montant doit être un nombre entier de FCFA supérieur à zéro.');
            }
            if ($donnees['echeance']->isBefore(Carbon::today())) {
                throw new OperationRefusee('L\'échéance ne peut pas être passée.');
            }
            if ($prix !== null && $prix <= 0) {
                throw new OperationRefusee('Le prix de référence doit être supérieur à zéro.');
            }
            if ($partieLiee && blank($accordEcrit)) {
                throw new OperationRefusee('Producteur lié à la direction : l\'accord écrit est obligatoire (contrat, art. 17.3).');
            }

            $parcelles = Parcelle::query()->whereKey($donnees['parcelle_ids'] ?? [])->get();
            if ($parcelles->contains(fn (Parcelle $p) => $p->producteur_id !== $producteur->id)) {
                throw new OperationRefusee('Une parcelle choisie n\'appartient pas à ce producteur.');
            }

            self::verifierPlafonds($producteur, $campagne, $montant, $parcelles->pluck('surface_m2')->all());
            self::verifierCautionSolidaire($producteur);

            $seuil = Parametre::entier(CleParametre::SeuilValidationPret);
            // La direction est le compte supérieur : son prêt est accordé dès la saisie, sans
            // 2e accord (demande du 2026-10-06). Trace : une validation à son nom, = auteur.
            $direct = $auteur->aLeRole(Role::Direction);

            $pret = Pret::query()->create([
                'producteur_id' => $producteur->id,
                'campagne_id' => $campagne->id,
                'montant_fcfa' => $montant,
                'forme' => $donnees['forme'],
                'prix_reference_kg_fcfa' => $prix,
                'grammes_attendus' => $prix === null ? null : self::grammesAttendus($montant, $prix),
                'echeance' => $donnees['echeance']->toDateString(),
                'statut' => $direct ? StatutPret::Valide : StatutPret::Demande,
                'validations_requises' => $direct ? 1 : ($seuil === null || $montant > $seuil ? 2 : 1),
                'partie_liee' => $partieLiee,
                'accord_ecrit' => $partieLiee ? $accordEcrit : null,
                'cree_par' => $auteur->id,
                'valide_at' => $direct ? now() : null,
            ]);
            $pret->parcelles()->attach($parcelles->modelKeys());

            if ($direct) {
                ValidationPret::query()->create(['pret_id' => $pret->id, 'user_id' => $auteur->id]);
            }

            return $pret;
        });
    }

    /**
     * Kilos attendus = montant ÷ prix au kilo, en grammes, arrondi au gramme le plus
     * proche, en entiers (D4).
     */
    public static function grammesAttendus(int $montant, int $prixKg): int
    {
        return intdiv($montant * 1000 * 2 + $prixKg, 2 * $prixKg);
    }

    public static function valider(Pret $pret, User $validateur): Pret
    {
        return DB::transaction(function () use ($pret, $validateur) {
            $pret = self::relireDemande($pret, $validateur);
            // Le retard d'un autre membre peut être apparu depuis la demande.
            self::verifierCautionSolidaire($pret->producteur);

            if ($pret->validations()->where('user_id', $validateur->id)->exists()) {
                throw new OperationRefusee('Vous avez déjà validé ce prêt : la seconde validation doit venir d\'une autre personne.');
            }

            ValidationPret::query()->create(['pret_id' => $pret->id, 'user_id' => $validateur->id]);

            if ($pret->validations()->count() >= $pret->validations_requises) {
                $pret->update(['statut' => StatutPret::Valide, 'valide_at' => now()]);
            }

            return $pret->refresh();
        });
    }

    public static function refuser(Pret $pret, User $validateur, string $motif): Pret
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif du refus est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($pret, $validateur, $motif) {
            $pret = self::relireDemande($pret, $validateur);
            $pret->update(['statut' => StatutPret::Refuse, 'motif_refus' => $motif]);

            return $pret;
        });
    }

    /**
     * « Supprimer » un prêt, par son auteur ou la direction : rien ne s'efface, il passe
     * « annulé » avec qui, quand et pourquoi. Seulement tant que rien n'a été remis au
     * producteur : un versement se contre-passe d'abord (fiche du prêt ou trésorerie), sinon
     * l'argent sorti n'aurait plus de prêt en face.
     */
    public static function annuler(Pret $pret, User $auteur, string $motif): Pret
    {
        if (! $auteur->can('annuler-operation', $pret)) {
            throw new OperationRefusee('Seuls l\'auteur du prêt et la direction peuvent le supprimer (annuler).');
        }
        $motif = trim($motif);
        if (mb_strlen($motif) < 5) {
            throw new OperationRefusee('Le motif de l\'annulation est obligatoire (5 caractères au moins).');
        }

        return DB::transaction(function () use ($pret, $auteur, $motif) {
            $pret = Pret::query()->lockForUpdate()->findOrFail($pret->id);

            if (! in_array($pret->statut, [StatutPret::Demande, StatutPret::Valide], true)) {
                throw new OperationRefusee("Le prêt {$pret->reference} est ".mb_strtolower($pret->statut->libelle()).' : il ne s\'annule plus.');
            }
            if ($pret->montantRemis() > 0) {
                throw new OperationRefusee('De l\'argent ou des intrants ont déjà été remis sur ce prêt : contre-passer d\'abord chaque versement, puis annuler le prêt.');
            }

            $pret->update(['statut' => StatutPret::Annule, 'annule_par' => $auteur->id, 'annule_at' => now(), 'motif_annulation' => $motif]);

            return $pret->refresh();
        });
    }

    /**
     * @param  array{compte_id: int, mode: ModeDecaissement, montant_fcfa: int, date: Carbon, reference?: ?string}  $donnees
     */
    public static function decaisser(Pret $pret, array $donnees, User $auteur, ?string $justificatif = null): Decaissement
    {
        if (! $auteur->can('decaisser-prets')) {
            throw new OperationRefusee('Votre rôle ne permet pas de décaisser un prêt.');
        }

        return DB::transaction(function () use ($pret, $donnees, $auteur, $justificatif) {
            $pret = Pret::query()->lockForUpdate()->findOrFail($pret->id);
            $compte = CompteTresorerie::query()->findOrFail($donnees['compte_id']);
            $mode = $donnees['mode'];
            $montant = $donnees['montant_fcfa'];
            $reference = filled($donnees['reference'] ?? null) ? trim((string) $donnees['reference']) : null;

            if ($pret->statut !== StatutPret::Valide) {
                throw new OperationRefusee('Seul un prêt validé et pas encore entièrement versé peut être décaissé (statut : '.$pret->statut->libelle().').');
            }
            if (! $pret->forme->accepteArgent($mode)) {
                throw new OperationRefusee($pret->forme === FormePret::Intrants
                    ? 'Ce prêt est en intrants : remettre des intrants, pas de l\'argent.'
                    : 'Ce prêt est en '.$pret->forme->libelle().' : le versement doit se faire de la même façon.');
            }
            if (! in_array($compte->type, $mode->typesDeCompte(), true)) {
                throw new OperationRefusee("Un versement en {$mode->libelle()} ne part pas du compte « {$compte->nom} » ({$compte->type->libelle()}).");
            }
            if ($mode === ModeDecaissement::Especes && blank($justificatif)) {
                throw new OperationRefusee('Versement en espèces : le reçu signé par le producteur est obligatoire.');
            }
            if ($mode === ModeDecaissement::MobileMoney && $reference === null) {
                throw new OperationRefusee('Versement Mobile Money : la référence de la transaction est obligatoire.');
            }
            $reste = $pret->resteARemettre();
            if ($montant <= 0 || $montant > $reste) {
                throw new OperationRefusee('Le montant versé doit être compris entre 1 et '.Format::fcfa($reste).' (reste à remettre).');
            }

            $pret->loadMissing('producteur');
            $mouvement = Tresorerie::decaisserPret(
                $pret, $compte, $montant, $donnees['date'],
                "Prêt {$pret->reference} — {$pret->producteur->nomComplet()}", $auteur, $reference,
            );

            $decaissement = Decaissement::query()->create([
                'pret_id' => $pret->id,
                'montant_fcfa' => $montant,
                'mode' => $mode,
                'reference_paiement' => $reference,
                'compte_id' => $compte->id,
                'date_decaissement' => $donnees['date']->toDateString(),
                'justificatif' => $justificatif,
                'mouvement_id' => $mouvement->id,
                'cree_par' => $auteur->id,
            ]);

            self::marquerSiToutRemis($pret);
            ConfirmationsSms::pourDecaissement($decaissement);

            return $decaissement;
        });
    }

    /** « Décaissé » quand l'argent et les intrants remis atteignent le montant du prêt. */
    public static function marquerSiToutRemis(Pret $pret): void
    {
        if ($pret->statut === StatutPret::Valide && $pret->resteARemettre() === 0) {
            $pret->update(['statut' => StatutPret::Decaisse]);
            Remboursements::mettreAJourStatut($pret);
        }
    }

    /** Caution solidaire du groupe (question 37) : refuse seulement si la direction a choisi « bloquer ». */
    private static function verifierCautionSolidaire(Producteur $producteur): void
    {
        $controle = CautionSolidaire::controle($producteur);
        if ($controle['niveau'] === CautionSolidaire::BLOCAGE) {
            throw new OperationRefusee((string) $controle['message']);
        }
    }

    /**
     * @param  list<int|null>  $surfacesM2
     */
    private static function verifierPlafonds(Producteur $producteur, Campagne $campagne, int $montant, array $surfacesM2): void
    {
        $plafondProducteur = Parametre::entier(CleParametre::PlafondPretProducteur);
        if ($plafondProducteur !== null) {
            $dejaPrete = (int) Pret::query()
                ->where('producteur_id', $producteur->id)
                ->where('campagne_id', $campagne->id)
                ->whereNotIn('statut', [StatutPret::Refuse, StatutPret::Annule])
                ->sum('montant_fcfa');

            if ($dejaPrete + $montant > $plafondProducteur) {
                throw new OperationRefusee('Plafond par producteur dépassé : '.Format::fcfa($dejaPrete).' déjà prêtés sur cette campagne, plafond '
                    .Format::fcfa($plafondProducteur).'.');
            }
        }

        $plafondHectare = Parametre::entier(CleParametre::PlafondPretHectare);
        if ($plafondHectare !== null) {
            $surface = array_sum(array_filter($surfacesM2, fn ($s) => $s !== null));
            if ($surface === 0) {
                throw new OperationRefusee('Un plafond par hectare est fixé : rattacher au moins une parcelle dont la surface est relevée.');
            }
            // montant / (surface / 10 000) ≤ plafond, en entiers : montant × 10 000 ≤ plafond × surface.
            if ($montant * 10_000 > $plafondHectare * $surface) {
                throw new OperationRefusee('Plafond par hectare dépassé : '.Format::hectares($surface).' financés, plafond '
                    .Format::fcfa($plafondHectare).' par hectare.');
            }
        }
    }

    private static function relireDemande(Pret $pret, User $validateur): Pret
    {
        $pret = Pret::query()->lockForUpdate()->findOrFail($pret->id);

        if (! $validateur->can('valider-prets')) {
            throw new OperationRefusee('Votre rôle ne permet pas de valider les prêts.');
        }
        // Séparation des tâches (D6, invariant 5).
        if ($pret->cree_par === $validateur->id) {
            throw new OperationRefusee('Vous ne pouvez pas valider ni refuser votre propre demande de prêt.');
        }
        if ($pret->statut !== StatutPret::Demande) {
            throw new OperationRefusee('Ce prêt n\'est plus en demande (statut : '.$pret->statut->libelle().').');
        }

        return $pret;
    }
}
