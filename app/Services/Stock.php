<?php

namespace App\Services;

use App\Enums\TypeMouvementStock;
use App\Exceptions\OperationRefusee;
use App\Models\Achat;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Models\User;
use App\Models\Vente;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seule porte d'entrée des mouvements de stock (registre immuable 🔒).
 *
 * Stock d'un lot dans un magasin = Σ grammes ≥ 0 (invariant 2) : le lot est verrouillé
 * pendant l'écriture et une sortie qui rendrait le stock négatif est refusée. Un poids
 * d'achat et un poids compté à l'inventaire peuvent différer : l'écart est une donnée
 * (ajustement motivé), pas une erreur à masquer.
 */
class Stock
{
    public const GRAMMES_MAX = 1_000_000_000_000;

    /** Entrée d'un achat, dans le magasin du lot : appelé par App\Services\Achats. */
    public static function entreeAchat(Achat $achat, User $auteur): MouvementStock
    {
        return DB::transaction(function () use ($achat, $auteur) {
            $lot = self::verrouiller($achat->lot);

            return self::ecrire($lot, $lot->magasin, TypeMouvementStock::EntreeAchat, $achat->poids_net_g,
                Carbon::parse($achat->date_achat), $auteur, achat: $achat);
        });
    }

    /**
     * Annule l'entrée en stock d'un achat par son inverse : appelé par App\Services\Achats::annuler,
     * dans sa transaction. Refusé si le lot n'a plus ces kilos (déjà vendus ou transférés).
     */
    public static function annulerEntreeAchat(Achat $achat, string $motif, User $auteur): MouvementStock
    {
        self::exigerMotif($motif);
        $lot = self::verrouiller($achat->lot);
        $entree = MouvementStock::query()->where('achat_id', $achat->id)->where('type', TypeMouvementStock::EntreeAchat)->first();
        if ($entree === null) {
            throw new OperationRefusee("L'achat {$achat->reference} n'a pas d'entrée en stock à annuler.");
        }
        if (MouvementStock::query()->where('annule_id', $entree->id)->exists()) {
            throw new OperationRefusee('Cette entrée en stock a déjà été annulée.');
        }

        return self::ecrire($lot, $entree->magasin, TypeMouvementStock::ContrePassation, -$entree->grammes, Carbon::today(), $auteur,
            achat: $achat, motif: trim($motif), annule: $entree);
    }

    /** Annulation d'une vente : les kilos sortis reviennent dans le magasin d'où ils étaient partis. */
    public static function annulerSortieVente(Vente $vente, string $motif, User $auteur): MouvementStock
    {
        self::exigerMotif($motif);
        $lot = self::verrouiller($vente->lot);
        $sortie = MouvementStock::query()->where('vente_id', $vente->id)->where('type', TypeMouvementStock::SortieVente)->first();
        if ($sortie === null) {
            throw new OperationRefusee("La vente {$vente->reference} n'a pas de sortie de stock à annuler.");
        }
        if (MouvementStock::query()->where('annule_id', $sortie->id)->exists()) {
            throw new OperationRefusee('Cette sortie de stock a déjà été annulée.');
        }

        return self::ecrire($lot, $sortie->magasin, TypeMouvementStock::ContrePassation, -$sortie->grammes, Carbon::today(), $auteur,
            vente: $vente, motif: trim($motif), annule: $sortie);
    }

    /** Sortie d'une vente, dans le magasin du lot : appelé par App\Services\Ventes. */
    public static function sortieVente(Vente $vente, User $auteur): MouvementStock
    {
        return DB::transaction(function () use ($vente, $auteur) {
            $lot = self::verrouiller($vente->lot);

            return self::ecrire($lot, $lot->magasin, TypeMouvementStock::SortieVente, -$vente->poids_net_g,
                Carbon::parse($vente->date_vente), $auteur, vente: $vente);
        });
    }

    /**
     * Transfert d'un magasin à un autre : deux mouvements liés, tout ou rien.
     *
     * @return array{sortie: MouvementStock, entree: MouvementStock}
     */
    public static function transferer(Lot $lot, Magasin $depuis, Magasin $vers, int $grammes, Carbon $date, User $auteur, ?string $motif = null): array
    {
        self::verifierDroit($auteur);
        if ($depuis->is($vers)) {
            throw new OperationRefusee('Un transfert se fait entre deux magasins différents.');
        }

        return DB::transaction(function () use ($lot, $depuis, $vers, $grammes, $date, $auteur, $motif) {
            $lot = self::verrouiller($lot);
            $lien = (string) Str::uuid7();

            return [
                'sortie' => self::ecrire($lot, $depuis, TypeMouvementStock::TransfertSortie, -$grammes, $date, $auteur, motif: $motif, lien: $lien),
                'entree' => self::ecrire($lot, $vers, TypeMouvementStock::TransfertEntree, $grammes, $date, $auteur, motif: $motif, lien: $lien),
            ];
        });
    }

    public static function perte(Lot $lot, Magasin $magasin, int $grammes, Carbon $date, string $motif, User $auteur): MouvementStock
    {
        self::verifierDroit($auteur);
        self::exigerMotif($motif);

        return DB::transaction(fn () => self::ecrire(self::verrouiller($lot), $magasin, TypeMouvementStock::Perte, -abs($grammes), $date, $auteur, motif: trim($motif)));
    }

    /**
     * Inventaire : on saisit ce qui est COMPTÉ ; l'écart avec le stock théorique devient
     * un mouvement d'ajustement motivé. Rien si l'écart est nul.
     */
    public static function inventaire(Lot $lot, Magasin $magasin, int $grammesComptes, Carbon $date, string $motif, User $auteur): ?MouvementStock
    {
        self::verifierDroit($auteur);
        self::exigerMotif($motif);
        if ($grammesComptes < 0) {
            throw new OperationRefusee('Un poids compté ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($lot, $magasin, $grammesComptes, $date, $motif, $auteur) {
            $lot = self::verrouiller($lot);
            $ecart = $grammesComptes - $lot->stock($magasin->id);

            return $ecart === 0
                ? null
                : self::ecrire($lot, $magasin, TypeMouvementStock::AjustementInventaire, $ecart, $date, $auteur,
                    motif: trim($motif).' (compté '.Format::kg($grammesComptes).')');
        });
    }

    /**
     * Annule un mouvement par son inverse ; un transfert sur ses deux jambes. L'entrée
     * d'un achat ne se contre-passe pas ici : elle suit l'achat.
     *
     * @return list<MouvementStock>
     */
    public static function contrePasser(MouvementStock $mouvement, string $motif, User $auteur): array
    {
        self::verifierDroit($auteur);
        self::exigerMotif($motif);

        return DB::transaction(function () use ($mouvement, $motif, $auteur) {
            $lot = self::verrouiller($mouvement->lot);
            $originaux = $mouvement->lien === null
                ? MouvementStock::query()->whereKey($mouvement->id)->get()
                : MouvementStock::query()->where('lien', $mouvement->lien)->orderBy('id')->get();

            foreach ($originaux as $original) {
                if ($original->type === TypeMouvementStock::ContrePassation) {
                    throw new OperationRefusee('Une contre-passation ne se contre-passe pas.');
                }
                if ($original->type === TypeMouvementStock::EntreeAchat) {
                    throw new OperationRefusee('L\'entrée d\'un achat suit l\'achat : elle ne se contre-passe pas depuis le stock.');
                }
                if ($original->type === TypeMouvementStock::SortieVente) {
                    throw new OperationRefusee('La sortie d\'une vente suit la vente : elle ne se contre-passe pas depuis le stock.');
                }
                if (MouvementStock::query()->where('annule_id', $original->id)->exists()) {
                    throw new OperationRefusee('Ce mouvement a déjà été contre-passé.');
                }
            }

            $crees = [];
            // Les entrées d'abord : annuler un transfert retire du magasin d'arrivée avant de rendre au départ.
            foreach ($originaux->sortBy(fn (MouvementStock $m) => $m->grammes > 0 ? 0 : 1) as $original) {
                $crees[] = self::ecrire($lot, $original->magasin, TypeMouvementStock::ContrePassation, -$original->grammes, Carbon::today(), $auteur,
                    motif: trim($motif), lien: $original->lien, annule: $original);
            }

            return $crees;
        });
    }

    private static function verrouiller(Lot $lot): Lot
    {
        return Lot::query()->with('magasin')->lockForUpdate()->findOrFail($lot->id);
    }

    private static function ecrire(
        Lot $lot,
        Magasin $magasin,
        TypeMouvementStock $type,
        int $grammes,
        Carbon $date,
        User $auteur,
        ?Achat $achat = null,
        ?Vente $vente = null,
        ?string $motif = null,
        ?string $lien = null,
        ?MouvementStock $annule = null,
    ): MouvementStock {
        if ($grammes === 0 || abs($grammes) > self::GRAMMES_MAX) {
            throw new OperationRefusee('Le poids doit être différent de zéro.');
        }
        if ($date->copy()->startOfDay()->isAfter(Carbon::today())) {
            throw new OperationRefusee('La date ne peut pas être dans le futur.');
        }
        if ($grammes < 0) {
            $stock = $lot->stock($magasin->id);
            if ($stock + $grammes < 0) {
                throw new OperationRefusee("Stock insuffisant du lot {$lot->code} au magasin « {$magasin->nom} » : "
                    .Format::kg($stock).' disponibles, '.Format::kg(-$grammes).' demandés.');
            }
        }

        return MouvementStock::query()->create([
            'lot_id' => $lot->id,
            'magasin_id' => $magasin->id,
            'type' => $type,
            'grammes' => $grammes,
            'date_mouvement' => $date->toDateString(),
            'motif' => $motif,
            'achat_id' => $achat?->id,
            'vente_id' => $vente?->id,
            'lien' => $lien,
            'annule_id' => $annule?->id,
            'cree_par' => $auteur->id,
        ]);
    }

    private static function exigerMotif(string $motif): void
    {
        if (mb_strlen(trim($motif)) < 5) {
            throw new OperationRefusee('Le motif est obligatoire (5 caractères au moins).');
        }
    }

    private static function verifierDroit(User $auteur): void
    {
        if (! $auteur->can('gerer-stock')) {
            throw new OperationRefusee('Votre rôle ne permet pas cette opération de stock.');
        }
    }
}
