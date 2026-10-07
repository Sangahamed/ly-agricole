<?php

namespace App\Services;

use App\Enums\CleParametre;
use App\Enums\StatutAchat;
use App\Enums\StatutDepense;
use App\Enums\StatutLot;
use App\Enums\StatutPret;
use App\Enums\TypeMouvementStock;
use App\Models\Achat;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Parametre;
use App\Models\PhotoTerrain;
use App\Models\Pret;
use App\Support\Tableau;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rapports de la direction (semaine 10) : « combien reste dû, combien en stock, combien
 * en caisse » sans aide. Chaque chiffre est une SOMME de registres (skill
 * argent-et-kilos) — aucune colonne de solde, aucun cache.
 */
class Rapports
{
    private const STATUTS_ACCORDES = [StatutPret::Valide, StatutPret::Decaisse, StatutPret::Solde];

    /**
     * @return array{restant_du: int, prets_en_cours: int, prets_echus: int, tresorerie: int, stock: list<array{produit: string, grammes: int}>, alertes: int}
     */
    public function synthese(): array
    {
        $portefeuille = $this->lignesPortefeuille(null);

        return [
            'restant_du' => Tableau::somme($portefeuille, 8),
            'prets_en_cours' => count(array_filter($portefeuille, fn ($l) => $l[8] > 0)),
            'prets_echus' => count(array_filter($portefeuille, fn ($l) => $l[9] !== '')),
            'tresorerie' => Tableau::somme($this->lignesCaisses(), 3),
            'stock' => DB::table('mouvements_stock')
                ->join('lots', 'lots.id', '=', 'mouvements_stock.lot_id')
                ->join('produits', 'produits.id', '=', 'lots.produit_id')
                ->groupBy('produits.id', 'produits.nom')
                ->orderBy('produits.nom')
                ->selectRaw('produits.nom AS produit, SUM(mouvements_stock.grammes) AS grammes')
                ->get()
                ->map(fn ($l) => ['produit' => (string) $l->produit, 'grammes' => (int) $l->grammes])
                ->filter(fn ($l) => $l['grammes'] !== 0)->values()->all(),
            'alertes' => count($this->alertes()->lignes),
        ];
    }

    /** Portefeuille de prêts accordés : remis, remboursé, restant dû, échus. */
    public function portefeuille(?int $campagneId = null): Tableau
    {
        $lignes = $this->lignesPortefeuille($campagneId);

        return new Tableau(
            'Portefeuille de prêts',
            [['Référence', Tableau::TEXTE], ['Producteur', Tableau::TEXTE], ['Campagne', Tableau::TEXTE], ['Statut', Tableau::TEXTE],
                ['Échéance', Tableau::DATE], ['Accordé', Tableau::FCFA], ['Remis', Tableau::FCFA], ['Remboursé', Tableau::FCFA],
                ['Restant dû', Tableau::FCFA], ['Retard', Tableau::TEXTE]],
            $lignes,
            ['Total', count($lignes).' prêt(s)', null, null, null, Tableau::somme($lignes, 5), Tableau::somme($lignes, 6),
                Tableau::somme($lignes, 7), Tableau::somme($lignes, 8), null],
            'Remis = argent versé + intrants remis à crédit ; remboursé = espèces + valeur des kilos livrés. Sans intérêt (question 4).',
        );
    }

    /** @return list<list<int|string|Carbon|null>> */
    private function lignesPortefeuille(?int $campagneId): array
    {
        $aujourdhui = Carbon::today();

        return Pret::query()
            ->with('producteur', 'campagne')
            ->whereIn('statut', self::STATUTS_ACCORDES)
            ->when($campagneId, fn ($q) => $q->where('campagne_id', $campagneId))
            ->orderBy('reference')
            ->get()
            ->map(function (Pret $p) use ($aujourdhui) {
                $restant = $p->restantDu();
                $echu = $restant > 0 && $p->echeance->lt($aujourdhui);

                return [
                    $p->reference,
                    $p->producteur->nomComplet().' ('.$p->producteur->code.')',
                    $p->campagne->code,
                    $p->statut->libelle(),
                    $p->echeance,
                    $p->montant_fcfa,
                    $p->montantRemis(),
                    $p->montantRembourse(),
                    $restant,
                    $echu ? 'échu depuis '.(int) $p->echeance->diffInDays($aujourdhui).' j' : '',
                ];
            })->values()->all();
    }

    /** Stock par lot et par magasin (un lot transféré est dans plusieurs magasins). */
    public function stock(): Tableau
    {
        $lignes = DB::table('mouvements_stock')
            ->join('lots', 'lots.id', '=', 'mouvements_stock.lot_id')
            ->join('produits', 'produits.id', '=', 'lots.produit_id')
            ->join('campagnes', 'campagnes.id', '=', 'lots.campagne_id')
            ->join('magasins', 'magasins.id', '=', 'mouvements_stock.magasin_id')
            ->groupBy('lots.id', 'lots.code', 'produits.nom', 'campagnes.code', 'lots.statut', 'magasins.id', 'magasins.nom')
            ->havingRaw('SUM(mouvements_stock.grammes) <> 0')
            ->orderBy('produits.nom')->orderBy('lots.code')->orderBy('magasins.nom')
            ->selectRaw('lots.code AS lot, produits.nom AS produit, campagnes.code AS campagne, lots.statut AS statut, magasins.nom AS magasin, SUM(mouvements_stock.grammes) AS grammes')
            ->get()
            ->map(fn ($l) => [(string) $l->lot, (string) $l->produit, (string) $l->campagne, StatutLot::from((string) $l->statut)->libelle(), (string) $l->magasin, (int) $l->grammes])
            ->values()->all();

        $produits = array_unique(array_column($lignes, 1));

        return new Tableau(
            'Stock par lot et par magasin',
            [['Lot', Tableau::TEXTE], ['Produit', Tableau::TEXTE], ['Campagne', Tableau::TEXTE], ['Statut', Tableau::TEXTE],
                ['Magasin', Tableau::TEXTE], ['Stock', Tableau::KG]],
            $lignes,
            // Pas de total qui additionnerait des kilos de produits différents.
            count($produits) === 1 ? ['Total', null, null, null, null, Tableau::somme($lignes, 5)] : null,
            count($produits) > 1 ? 'Plusieurs produits : les totaux par produit sont sur la synthèse.' : null,
        );
    }

    /** Caisses, banques et Mobile Money : solde = somme des mouvements. */
    public function caisses(): Tableau
    {
        $lignes = $this->lignesCaisses();

        return new Tableau(
            'Caisses et comptes',
            [['Compte', Tableau::TEXTE], ['Type', Tableau::TEXTE], ['Titulaire', Tableau::TEXTE], ['Solde', Tableau::FCFA],
                ['Dernier mouvement', Tableau::DATE]],
            $lignes,
            ['Total', null, null, Tableau::somme($lignes, 3), null],
            'Le solde d\'une caisse d\'agent est l\'argent qu\'il lui reste à justifier.',
        );
    }

    /** @return list<list<int|string|Carbon|null>> */
    private function lignesCaisses(): array
    {
        $derniers = DB::table('mouvements_tresorerie')->groupBy('compte_id')
            ->selectRaw('compte_id, MAX(date_operation) AS derniere')->pluck('derniere', 'compte_id');

        return CompteTresorerie::query()->with('titulaire')->where('actif', true)->orderBy('nom')->get()
            ->map(fn (CompteTresorerie $c) => [
                $c->nom,
                $c->type->libelle(),
                $c->titulaire->nom ?? '',
                $c->solde(),
                isset($derniers[$c->id]) ? Carbon::parse((string) $derniers[$c->id]) : null,
            ])->values()->all();
    }

    /**
     * Écarts de poids par lot : ce qui a été acheté (poids net des pesées) comparé au
     * stock. L'écart (séchage, pertes, inventaire) est une donnée, pas une erreur à
     * masquer (skill argent-et-kilos §6).
     */
    public function ecarts(): Tableau
    {
        $lignes = $this->lignesEcarts();

        return new Tableau(
            'Écarts de poids par lot',
            [['Lot', Tableau::TEXTE], ['Produit', Tableau::TEXTE], ['Achats pesés', Tableau::KG], ['Pertes', Tableau::KG],
                ['Inventaires', Tableau::KG], ['Corrections', Tableau::KG], ['Ventes et autres sorties', Tableau::KG],
                ['Stock', Tableau::KG], ['Écart', Tableau::KG], ['Écart / achats', Tableau::POUR_MILLE]],
            $lignes,
            null,
            'Écart = pertes + ajustements d\'inventaire + corrections : les kilos disparus sans être vendus. Les transferts entre magasins s\'annulent.',
        );
    }

    /** @return list<list<int|string|null>> */
    private function lignesEcarts(): array
    {
        $somme = fn (TypeMouvementStock $t) => "SUM(CASE WHEN mouvements_stock.type = '{$t->value}' THEN mouvements_stock.grammes ELSE 0 END)";

        return DB::table('mouvements_stock')
            ->join('lots', 'lots.id', '=', 'mouvements_stock.lot_id')
            ->join('produits', 'produits.id', '=', 'lots.produit_id')
            ->groupBy('lots.id', 'lots.code', 'produits.nom')
            ->orderBy('lots.code')
            ->selectRaw('lots.code AS lot, produits.nom AS produit, '
                .$somme(TypeMouvementStock::EntreeAchat).' AS achats, '
                .$somme(TypeMouvementStock::Perte).' AS pertes, '
                .$somme(TypeMouvementStock::AjustementInventaire).' AS inventaires, '
                .$somme(TypeMouvementStock::ContrePassation).' AS corrections, '
                .'SUM(mouvements_stock.grammes) AS stock')
            ->get()
            ->map(function ($l) {
                $achats = (int) $l->achats;
                // L'écart = ce qui a disparu sans être vendu : pertes, inventaires, corrections.
                // Une vente (phase 2) ou toute autre sortie n'est pas un écart de poids.
                $ecart = (int) $l->pertes + (int) $l->inventaires + (int) $l->corrections;
                $autres = (int) $l->stock - $achats - $ecart;

                return [(string) $l->lot, (string) $l->produit, $achats, (int) $l->pertes, (int) $l->inventaires,
                    (int) $l->corrections, $autres, (int) $l->stock, $ecart,
                    // Pour mille arrondi vers zéro, en entiers.
                    $achats === 0 ? null : intdiv($ecart * 1000, $achats)];
            })->values()->all();
    }

    /**
     * Alertes de base : ce qui demande qu'on regarde. Seuil d'écart non défini : TOUS les
     * lots avec un écart sont listés (un seuil absent n'est jamais « pas de contrôle »).
     */
    public function alertes(): Tableau
    {
        $aujourdhui = Carbon::today();
        $lignes = [];

        foreach ($this->lignesPortefeuille(null) as $l) {
            if ($l[9] !== '') {
                $lignes[] = ['Prêt échu non soldé', (string) $l[0], $l[1].' — reste '.Tableau::afficher($l[8], Tableau::FCFA), $l[4]];
            }
        }

        foreach (Pret::query()->with('producteur')->where('statut', StatutPret::Demande)->orderBy('created_at')->get() as $p) {
            $lignes[] = ['Prêt à valider', $p->reference, $p->producteur->nomComplet().' — '.Tableau::afficher($p->montant_fcfa, Tableau::FCFA), $p->created_at];
        }

        foreach (Achat::query()->with('producteur', 'pisteur')->where('statut', StatutAchat::AValider)->orderBy('date_achat')->get() as $a) {
            $lignes[] = ['Achat à valider', $a->reference, $a->nomFournisseur().' — '.Tableau::afficher($a->montant_especes_fcfa, Tableau::FCFA).' à payer', $a->date_achat];
        }

        foreach (Depense::query()->where('statut', StatutDepense::AValider)->orderBy('date_depense')->get() as $d) {
            $lignes[] = ['Dépense à valider', $d->beneficiaire, Tableau::afficher($d->montant_fcfa, Tableau::FCFA), $d->date_depense];
        }

        // Pas de whereDoesntHave : `achats.photo_pesee` est du texte, `photos_terrain.id` un uuid,
        // et PostgreSQL (production) refuse « uuid = character varying » (500 sur /rapports,
        // 2026-10-07). On compare en PHP, sur les seuls identifiants bien formés.
        $avecPhoto = Achat::query()->whereNotNull('photo_pesee')->orderBy('date_achat')->get();
        $recues = PhotoTerrain::query()
            ->whereIn('id', $avecPhoto->pluck('photo_pesee')->filter(fn ($id) => Str::isUuid((string) $id))->values())
            ->pluck('id')->map(fn ($id) => strtolower((string) $id))->flip();
        $attendues = $avecPhoto->reject(fn (Achat $a) => $recues->has(strtolower((string) $a->photo_pesee)));
        foreach ($attendues as $a) {
            $lignes[] = ['Photo de pesée attendue', $a->reference, 'La photo n\'est pas encore arrivée du téléphone.', $a->date_achat];
        }

        // Une sauvegarde jamais restaurée n'est pas une sauvegarde (vérification hebdomadaire).
        $sauvegardes = app(Sauvegardes::class);
        $copie = $sauvegardes->derniereCopieHorsSite();
        if (! $sauvegardes->horsSiteConfiguree()) {
            $lignes[] = ['Copie hors site absente', '—', 'Les sauvegardes restent sur le serveur : un vol, un incendie ou un piratage les emporterait avec la base.', null];
        } elseif ($copie !== null && ! $copie['ok']) {
            $lignes[] = ['Copie hors site en échec', $copie['archive'], (string) $copie['erreur'], Carbon::parse($copie['copie_at'])];
        } elseif ($copie === null || Carbon::parse($copie['copie_at'])->lt($aujourdhui->copy()->subDays(2))) {
            $lignes[] = ['Copie hors site ancienne', $copie['archive'] ?? '—', 'Aucune copie hors site réussie depuis plus de 2 jours.', $copie === null ? null : Carbon::parse($copie['copie_at'])];
        }
        if (config('sauvegardes.mot_de_passe') === null) {
            $lignes[] = ['Sauvegardes non chiffrées', '—', 'Données personnelles en clair dans les archives : « php artisan ly:mot-de-passe-sauvegardes ».', null];
        }

        $verification = $sauvegardes->derniereVerification();
        if ($verification === null) {
            $lignes[] = ['Sauvegarde non vérifiée', '—', 'Aucune restauration de sauvegarde vérifiée : lancer « php artisan ly:sauvegarder --verifier ».', null];
        } elseif (! $verification['ok']) {
            $lignes[] = ['Sauvegarde en échec', $verification['archive'], implode(' ', array_slice($verification['erreurs'], 0, 2)), Carbon::parse($verification['verifie_at'])];
        } elseif (Carbon::parse($verification['verifie_at'])->lt($aujourdhui->copy()->subDays(8))) {
            $lignes[] = ['Sauvegarde non vérifiée', $verification['archive'], 'Dernière restauration vérifiée il y a plus de 8 jours.', Carbon::parse($verification['verifie_at'])];
        }

        $seuil = Parametre::entier(CleParametre::SeuilAlerteEcartPoids);
        foreach ($this->lignesEcarts() as $l) {
            // [.., 8 => écart (g), 9 => écart ‰ des achats]
            if ($l[9] !== null && $l[8] !== 0 && ($seuil === null || abs((int) $l[9]) >= $seuil)) {
                $lignes[] = ['Écart de poids', (string) $l[0], Tableau::afficher($l[8], Tableau::KG).' ('.Tableau::afficher($l[9], Tableau::POUR_MILLE).' des achats)', null];
            }
        }

        return new Tableau(
            'Alertes',
            [['Alerte', Tableau::TEXTE], ['Objet', Tableau::TEXTE], ['Détail', Tableau::TEXTE], ['Depuis', Tableau::DATE]],
            $lignes,
            null,
            $seuil === null
                ? 'Seuil d\'alerte d\'écart de poids non défini (Paramètres) : tous les lots avec un écart sont listés.'
                : 'Écarts listés à partir de '.Tableau::afficher($seuil, Tableau::POUR_MILLE).' des achats.',
        );
    }
}
