<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\Actualite;
use App\Models\Campagne;
use App\Models\PrixMarche;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ce que la vitrine publique affiche : prix bord-champ et actualités, saisis par la direction.
 *
 * Règles :
 *  - un prix est une INFORMATION datée et sourcée (source obligatoire, affichée avec le prix) ; ce
 *    n'est jamais le prix officiel d'une campagne ni un plancher d'achat ;
 *  - un prix ne se corrige pas (registre immuable) : on en publie un nouveau, l'ancien reste dans
 *    l'historique et sert à montrer la variation ;
 *  - une actualité est un texte simple, jamais du HTML ; brouillon tant qu'elle n'est pas publiée ;
 *  - un lien de source doit être une adresse http(s) : jamais `javascript:` ni autre schéma.
 *
 * La récupération AUTOMATIQUE de prix sur un site externe n'est pas branchée : aucune source n'est
 * choisie, les sources officielles ne proposent pas d'interface stable, et un prix faux affiché
 * publiquement engage LY. Voir la question ouverte n° 39.
 */
class Publications
{
    public const PRIX_MAX = 1_000_000;

    public static function publierPrix(Produit $produit, int $prixKg, Carbon $dateEffet, string $source, ?string $url, ?string $note, User $auteur): PrixMarche
    {
        self::verifierDroit($auteur);

        if (! $produit->actif) {
            throw new OperationRefusee("Le produit « {$produit->nom} » est désactivé.");
        }
        if ($prixKg <= 0 || $prixKg > self::PRIX_MAX) {
            throw new OperationRefusee('Le prix au kilo doit être un nombre entier de FCFA, supérieur à zéro.');
        }
        if ($dateEffet->isFuture()) {
            throw new OperationRefusee('La date d\'effet d\'un prix ne peut pas être dans le futur.');
        }
        $source = trim($source);
        if (mb_strlen($source) < 3) {
            throw new OperationRefusee('La source du prix est obligatoire (ex. « Communiqué du 1er octobre »).');
        }
        $url = filled($url) ? trim((string) $url) : null;
        if ($url !== null && ! preg_match('#^https?://[^\s]+$#i', $url)) {
            throw new OperationRefusee('Le lien de la source doit commencer par http:// ou https://.');
        }

        return PrixMarche::query()->create([
            'produit_id' => $produit->id,
            'prix_kg_fcfa' => $prixKg,
            'date_effet' => $dateEffet->toDateString(),
            'source' => mb_substr($source, 0, 255),
            'source_url' => $url === null ? null : mb_substr($url, 0, 500),
            'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
            'cree_par' => $auteur->id,
        ]);
    }

    /**
     * Le prix en vigueur de chaque produit (le plus récent), avec l'écart au précédent.
     *
     * @return Collection<int, array{produit: Produit, prix: PrixMarche, precedent: PrixMarche|null, ecart: int|null}>
     */
    public static function prixCourants(): Collection
    {
        $tous = PrixMarche::query()->with('produit')->orderByDesc('date_effet')->orderByDesc('id')->get()->groupBy('produit_id');

        return $tous->map(function (Collection $lignes) {
            /** @var PrixMarche $prix */
            $prix = $lignes->first();
            /** @var PrixMarche|null $precedent */
            $precedent = $lignes->get(1);

            return [
                'produit' => $prix->produit,
                'prix' => $prix,
                'precedent' => $precedent,
                'ecart' => $precedent === null ? null : $prix->prix_kg_fcfa - $precedent->prix_kg_fcfa,
            ];
        })->sortBy(fn (array $l) => mb_strtolower($l['produit']->nom))->values();
    }

    /**
     * Évolution du prix d'un produit sur une période : le prix en vigueur au début (s'il y en a un
     * avant), puis chaque changement jusqu'à la fin. Une seule valeur par date (la plus récemment
     * saisie). Sans borne, de la première publication à aujourd'hui.
     *
     * @return array{
     *     points: list<array{date: Carbon, prix: int, source: string, url: string|null, report: bool}>,
     *     debut: Carbon, fin: Carbon,
     *     resume: array{premier: int, dernier: int, min: int, max: int, variation: int, changements: int}|null
     * }
     */
    public static function serie(Produit $produit, ?Carbon $debut = null, ?Carbon $fin = null, ?Collection $prixDuProduit = null): array
    {
        $fin = ($fin ?? Carbon::today())->copy()->startOfDay();

        // Une valeur par date d'effet : la plus récemment saisie. `$prixDuProduit` (déjà triés par
        // date d'effet puis id) évite de relire la table à chaque appel.
        $parDate = [];
        foreach ($prixDuProduit ?? self::prixDuProduit($produit) as $ligne) {
            $parDate[$ligne->date_effet->toDateString()] = $ligne;
        }
        $lignes = array_values($parDate);

        $debut = ($debut ?? ($lignes === [] ? $fin : $lignes[0]->date_effet))->copy()->startOfDay();
        if ($debut->greaterThan($fin)) {
            [$debut, $fin] = [$fin, $debut];
        }

        $points = [];
        $enVigueur = null;
        foreach ($lignes as $ligne) {
            if ($ligne->date_effet->lessThan($debut)) {
                $enVigueur = $ligne;

                continue;
            }
            if ($ligne->date_effet->greaterThan($fin)) {
                break;
            }
            $points[] = ['date' => $ligne->date_effet->copy(), 'prix' => $ligne->prix_kg_fcfa, 'source' => $ligne->source, 'url' => $ligne->source_url, 'report' => false];
        }
        // Le prix déjà en vigueur au début de la période, s'il n'y a pas de changement ce jour-là.
        if ($enVigueur !== null && ($points === [] || ! $points[0]['date']->isSameDay($debut))) {
            array_unshift($points, ['date' => $debut->copy(), 'prix' => $enVigueur->prix_kg_fcfa, 'source' => $enVigueur->source, 'url' => $enVigueur->source_url, 'report' => true]);
        }

        $prix = array_column($points, 'prix');

        return [
            'points' => $points,
            'debut' => $debut,
            'fin' => $fin,
            'resume' => $points === [] ? null : [
                'premier' => $prix[0], 'dernier' => $prix[count($prix) - 1], 'min' => min($prix), 'max' => max($prix),
                'variation' => $prix[count($prix) - 1] - $prix[0], 'changements' => count($points) - 1,
            ],
        ];
    }

    /**
     * Résumé du prix d'un produit campagne par campagne (les plus récentes d'abord), pour comparer :
     * seules comptent les campagnes de CE produit qui ont commencé et pour lesquelles un prix a été
     * publié au cours de la campagne ou avant.
     *
     * `depuis_precedente` : écart entre le dernier prix de la campagne et le dernier prix de la campagne
     * précédente connue (null pour la plus ancienne). `$limite` : nombre de campagnes rendues.
     *
     * @return list<array{campagne: Campagne, resume: array{premier: int, dernier: int, min: int, max: int, variation: int, changements: int}, depuis_precedente: int|null}>
     */
    public static function parCampagne(Produit $produit, int $limite = 7, ?Collection $campagnesDuProduit = null, ?Collection $prixDuProduit = null): array
    {
        // Lus une fois pour toutes les campagnes (une requête par campagne dépassait les 30 s de
        // Vercel sur /prix, base distante : 2026-10-06).
        $prixDuProduit ??= self::prixDuProduit($produit);
        $campagnesDuProduit ??= Campagne::query()->where('produit_id', $produit->id)->orderByDesc('debut')->get();

        $resultat = [];
        foreach ($campagnesDuProduit as $campagne) {
            if ($campagne->debut->isFuture()) {
                continue;
            }
            $serie = self::serie($produit, $campagne->debut, $campagne->fin->isFuture() ? Carbon::today() : $campagne->fin, $prixDuProduit);
            if ($serie['resume'] !== null) {
                $resultat[] = ['campagne' => $campagne, 'resume' => $serie['resume'], 'depuis_precedente' => null];
            }
        }
        foreach ($resultat as $i => $ligne) {
            if (isset($resultat[$i + 1])) {
                $resultat[$i]['depuis_precedente'] = $ligne['resume']['dernier'] - $resultat[$i + 1]['resume']['dernier'];
            }
        }

        return array_slice($resultat, 0, max($limite, 1));
    }

    /**
     * Tableau d'ensemble : toutes les cultures actives (lignes) sur les `$nb` dernières campagnes
     * commencées (colonnes, de la plus ancienne à la plus récente, repérées par leur code). Chaque
     * case donne le dernier prix publié de la campagne et son écart avec la campagne précédente de
     * cette culture ; case vide (null) quand aucun prix n'est connu : jamais un prix inventé.
     *
     * @return array{
     *     campagnes: list<string>,
     *     lignes: list<array{produit: Produit, cases: array<string, array{prix: int, ecart: int|null}|null>, connu: bool}>
     * }
     */
    public static function tableauCampagnes(int $nb = 7): array
    {
        $produits = Produit::query()->where('actif', true)->orderBy('nom')->get();
        $ids = $produits->pluck('id');

        // Deux requêtes pour tout le tableau, réparties ensuite par produit.
        $campagnes = Campagne::query()->whereIn('produit_id', $ids)->orderByDesc('debut')->get();
        $prix = PrixMarche::query()->whereIn('produit_id', $ids)->orderBy('date_effet')->orderBy('id')->get()->groupBy('produit_id');

        // Les colonnes sont des années de campagne (octobre à septembre) : la campagne du cacao
        // 2023-2024 (octobre 2023) et celle de l'anacarde 2024 (février 2024) tombent dans la même.
        $annees = $campagnes->filter(fn (Campagne $c) => ! $c->debut->isFuture())
            ->map(fn (Campagne $c) => self::anneeDeCampagne($c->debut))->unique()->sortDesc()->values();
        $codes = array_reverse(array_slice($annees->all(), 0, max($nb, 1)));
        $campagnes = $campagnes->groupBy('produit_id');

        $lignes = [];
        foreach ($produits as $produit) {
            $cases = array_fill_keys($codes, null);
            foreach (self::parCampagne($produit, 100, $campagnes->get($produit->id, collect()), $prix->get($produit->id, collect())) as $l) {
                $code = self::anneeDeCampagne($l['campagne']->debut);
                if (array_key_exists($code, $cases)) {
                    $cases[$code] = ['prix' => $l['resume']['dernier'], 'ecart' => $l['depuis_precedente']];
                }
            }
            $lignes[] = ['produit' => $produit, 'cases' => $cases, 'connu' => count(array_filter($cases)) > 0];
        }

        return ['campagnes' => $codes, 'lignes' => $lignes];
    }

    /** @return Collection<int, PrixMarche> Par date d'effet puis ordre de saisie. */
    private static function prixDuProduit(Produit $produit): Collection
    {
        return PrixMarche::query()->where('produit_id', $produit->id)->orderBy('date_effet')->orderBy('id')->get();
    }

    /** Année de campagne d'une date de début : d'octobre à septembre (« 2023-2024 »). */
    public static function anneeDeCampagne(Carbon $debut): string
    {
        $an = $debut->month >= 10 ? $debut->year : $debut->year - 1;

        return $an.'-'.($an + 1);
    }

    /** @return Collection<int, PrixMarche> Du plus récent au plus ancien. */
    public static function historiquePrix(Produit $produit): Collection
    {
        return PrixMarche::query()->with('auteur')->where('produit_id', $produit->id)->orderByDesc('date_effet')->orderByDesc('id')->get();
    }

    /**
     * Crée ou modifie une actualité. `publie` faux = brouillon.
     *
     * @param  array{titre: string, contenu: string, publie: bool, publie_le?: ?Carbon}  $d
     */
    public static function enregistrerActualite(array $d, User $auteur, ?Actualite $existante = null): Actualite
    {
        self::verifierDroit($auteur);

        $titre = trim($d['titre']);
        $contenu = trim($d['contenu']);
        if ($titre === '' || mb_strlen($titre) > 200) {
            throw new OperationRefusee('Le titre est obligatoire (200 caractères au plus).');
        }
        if (mb_strlen($contenu) < 10 || mb_strlen($contenu) > 10_000) {
            throw new OperationRefusee('Le texte doit faire entre 10 et 10 000 caractères.');
        }

        $publie = (bool) $d['publie'];
        // Publiée sans date : la date du jour ; une actualité déjà publiée garde sa date.
        $date = $publie ? ($d['publie_le'] ?? $existante->publie_le ?? Carbon::today()) : null;

        $attributs = ['titre' => $titre, 'contenu' => $contenu, 'publie' => $publie, 'publie_le' => $date?->toDateString()];

        if ($existante !== null) {
            $existante->update($attributs);

            return $existante->refresh();
        }

        return Actualite::query()->create($attributs + ['cree_par' => $auteur->id]);
    }

    /** @return Collection<int, Actualite> Les plus récentes d'abord, seulement ce que le public peut voir. */
    public static function actualitesVisibles(?int $limite = null): Collection
    {
        $requete = Actualite::query()->visibles()->orderByDesc('publie_le')->orderByDesc('id');

        return ($limite === null ? $requete : $requete->limit($limite))->get();
    }

    private static function verifierDroit(User $auteur): void
    {
        if (! $auteur->can('gerer-publications')) {
            throw new OperationRefusee('Seule la direction publie sur la vitrine.');
        }
    }
}
