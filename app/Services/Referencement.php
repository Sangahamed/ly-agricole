<?php

namespace App\Services;

use App\Models\Actualite;
use App\Models\PrixMarche;
use App\Models\Produit;
use App\Support\Format;

/**
 * Ce que les moteurs de recherche et les assistants IA lisent de la vitrine : descriptions des
 * pages de prix (avec les VRAIS derniers prix publiés, jamais un chiffre inventé), plan du site,
 * robots.txt et llms.txt. Uniquement des données publiques de la vitrine.
 */
class Referencement
{
    /** Produits cités dans la description générale de /prix, s'ils ont un prix publié. */
    private const PRODUITS_CITES = ['anacarde', 'cacao', 'cafe', 'karite', 'hevea', 'coton'];

    /** Description de /prix (tous produits) ou de la page d'un produit : environ 160 caractères utiles. */
    public static function descriptionPrix(?Produit $produit = null): string
    {
        $courants = Publications::prixCourants()->keyBy(fn (array $l) => $l['produit']->code);

        if ($produit !== null) {
            $ligne = $courants->get($produit->code);
            if ($ligne === null) {
                return "Prix bord-champ {$produit->nom} en Côte d'Ivoire : historique par campagne publié par LY AGRICOLE, chaque prix daté et sourcé.";
            }

            return "Prix bord-champ {$produit->nom} en Côte d'Ivoire : ".self::prixCourt($ligne['prix'])
                .' depuis le '.$ligne['prix']->date_effet->translatedFormat('j F Y')
                .'. Historique campagne par campagne, chaque prix daté et sourcé.';
        }

        $cites = collect(self::PRODUITS_CITES)
            ->map(fn (string $code) => $courants->get($code))
            ->filter()
            ->take(4)
            ->map(fn (array $l) => mb_strtolower($l['produit']->nom).' '.self::prixCourt($l['prix']))
            ->implode(', ');

        return $cites === ''
            ? "Prix bord-champ en Côte d'Ivoire, par culture et par campagne, publiés par LY AGRICOLE : chaque prix daté et sourcé."
            : "Prix bord-champ en Côte d'Ivoire : {$cites}… Évolution par culture et par campagne, chaque prix daté et sourcé.";
    }

    /** Titre de la page des prix, avec le produit quand il y en a un. */
    public static function titrePrix(?Produit $produit = null): string
    {
        return $produit === null
            ? "Prix bord-champ en Côte d'Ivoire, par culture et par campagne"
            : "Prix bord-champ {$produit->nom} en Côte d'Ivoire";
    }

    /** @return list<array{loc: string, lastmod: string|null}> */
    public static function pagesDuPlan(): array
    {
        // `prix_marche` est un registre (jamais modifié) : sa date utile est created_at, il n'a pas
        // d'updated_at. Attention : sqlite lit un nom de colonne inconnu comme du texte, sans erreur.
        $derniers = PrixMarche::query()->groupBy('produit_id')->selectRaw('produit_id, max(created_at) as dernier')->pluck('dernier', 'produit_id');
        $dernierPrix = $derniers->max();
        $pages = [
            ['loc' => route('accueil'), 'lastmod' => $dernierPrix],
            ['loc' => route('prix.evolution'), 'lastmod' => $dernierPrix],
        ];

        foreach (Produit::query()->whereIn('id', $derniers->keys())->orderBy('nom')->get() as $produit) {
            $pages[] = ['loc' => route('prix.evolution', ['produit' => $produit->id]), 'lastmod' => $derniers->get($produit->id)];
        }

        $actualites = Actualite::query()->visibles()->orderByDesc('publie_le')->get();
        $pages[] = ['loc' => route('actualites'), 'lastmod' => $actualites->max('updated_at')?->toAtomString()];
        foreach ($actualites as $actualite) {
            $pages[] = ['loc' => route('actualites.voir', $actualite), 'lastmod' => $actualite->updated_at?->toAtomString()];
        }

        return array_map(fn (array $p) => [
            'loc' => $p['loc'],
            'lastmod' => $p['lastmod'] === null ? null : date(DATE_ATOM, strtotime((string) $p['lastmod'])),
        ], $pages);
    }

    public static function robots(): string
    {
        return implode("\n", [
            'User-agent: *',
            // Rien d'utile pour un moteur : tâches planifiées, technique Livewire, zone de gestion.
            'Disallow: /cron/',
            'Disallow: /livewire/',
            'Disallow: /connexion',
            'Disallow: /api/',
            '',
            'Sitemap: '.route('referencement.plan'),
            '',
        ]);
    }

    /** llms.txt (llmstxt.org) : résumé en Markdown pour les assistants IA. */
    public static function llms(): string
    {
        $contact = config('vitrine.contact');
        $lignes = [
            '# LY AGRICOLE',
            '',
            "> Entreprise agricole ivoirienne : achat bord-champ, stockage et commercialisation d'anacarde (noix de cajou), de karité, de tomate et d'autres cultures. Le site publie les prix bord-champ en Côte d'Ivoire, campagne par campagne, chaque prix daté et sourcé. Ces prix sont indicatifs : ils ne remplacent pas le prix officiel fixé pour la campagne.",
            '',
            '## Pages',
            '',
            '- [Prix bord-champ, toutes cultures]('.route('prix.evolution').') : tableau des dernières campagnes et courbe par produit',
            '- [Accueil]('.route('accueil').') : prix du moment et présentation',
            '- [Actualités]('.route('actualites').')',
            '',
            '## Derniers prix bord-champ publiés (FCFA par kg)',
            '',
        ];

        foreach (Publications::prixCourants() as $l) {
            $prix = $l['prix'];
            $lignes[] = '- ['.$l['produit']->nom.']('.route('prix.evolution', ['produit' => $l['produit']->id]).') : '
                .Format::entier($prix->prix_kg_fcfa).' FCFA/kg, depuis le '.$prix->date_effet->toDateString()
                .' (source : '.$prix->source.')';
        }

        $lignes[] = '';
        $lignes[] = '## Contact';
        $lignes[] = '';
        $lignes[] = '- E-mail : '.$contact['email'];
        $lignes[] = '- Téléphone : '.$contact['telephone'];
        $lignes[] = '';

        return implode("\n", $lignes);
    }

    /** @return array<string, mixed> Données structurées schema.org de l'accueil. */
    public static function organisation(): array
    {
        $contact = config('vitrine.contact');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'LY AGRICOLE',
            'url' => route('accueil'),
            'logo' => asset('images/logo-yl-agro.png'),
            'email' => $contact['email'],
            'telephone' => $contact['telephone'],
            'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'CI'],
            'description' => "Entreprise agricole ivoirienne : achat bord-champ, stockage et commercialisation d'anacarde, de karité, de tomate et d'autres cultures.",
        ];
    }

    private static function prixCourt(PrixMarche $prix): string
    {
        return Format::entier($prix->prix_kg_fcfa).' FCFA/kg';
    }
}
