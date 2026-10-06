<?php

namespace App\Http\Controllers;

use App\Models\Campagne;
use App\Models\PrixMarche;
use App\Models\Produit;
use App\Services\Publications;
use App\Services\Referencement;
use App\Support\CourbeSvg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Évolution publique des prix bord-champ affichés, par produit, sur une période ou une campagne.
 * Ne lit que les prix PUBLIÉS (registre `prix_marche`) : aucune donnée de gestion.
 */
class PrixController extends Controller
{
    public const MAX_PRODUITS = 6;

    public function evolution(Request $request): View
    {
        $produits = Produit::query()->whereIn('id', PrixMarche::query()->select('produit_id'))->orderBy('nom')->get();
        $choisi = $produits->firstWhere('id', (int) $request->query('produit'));
        $affiches = $choisi === null ? $produits->take(self::MAX_PRODUITS) : collect([$choisi]);

        [$periode, $debut, $fin] = $this->periode($request);

        $graphiques = $affiches->map(function (Produit $produit) use ($debut, $fin) {
            $serie = Publications::serie($produit, $debut, $fin);

            return [
                'produit' => $produit,
                'serie' => $serie,
                'courbe' => CourbeSvg::construire(
                    array_map(fn (array $p) => ['date' => $p['date'], 'prix' => $p['prix']], $serie['points']),
                    $serie['debut'], $serie['fin'],
                ),
                'campagnes' => Publications::parCampagne($produit),
            ];
        })->all();

        return view('prix.evolution', [
            'tableau' => Publications::tableauCampagnes(7),
            'produits' => $produits,
            'produitChoisi' => $choisi,
            'periode' => $periode,
            'du' => $request->query('du'),
            'au' => $request->query('au'),
            'campagnes' => Campagne::query()->with('produit')->whereIn('produit_id', $produits->pluck('id'))->where('debut', '<=', Carbon::today()->toDateString())->orderByDesc('debut')->get(),
            'graphiques' => $graphiques,
            // Une page par produit pour les moteurs : son titre, ses vrais derniers prix, son adresse.
            'referencement' => [
                'titre' => Referencement::titrePrix($choisi),
                'description' => Referencement::descriptionPrix($choisi),
                'canonique' => $choisi === null ? route('prix.evolution') : route('prix.evolution', ['produit' => $choisi->id]),
            ],
        ]);
    }

    /**
     * Période demandée : 'tout' (par défaut), '6m', '12m', 'campagne-{id}' ou 'perso' (du / au).
     * Une valeur invalide revient à « tout » : jamais d'erreur pour un visiteur.
     *
     * @return array{0: string, 1: Carbon|null, 2: Carbon|null}
     */
    private function periode(Request $request): array
    {
        $periode = (string) $request->query('periode', 'tout');
        $aujourdhui = Carbon::today();

        if ($periode === '6m' || $periode === '12m') {
            return [$periode, $aujourdhui->copy()->subMonths($periode === '6m' ? 6 : 12), $aujourdhui];
        }
        if (preg_match('/^campagne-(\d+)$/', $periode, $m) === 1) {
            $campagne = Campagne::query()->find((int) $m[1]);
            if ($campagne !== null && ! $campagne->debut->isFuture()) {
                return [$periode, $campagne->debut->copy(), $campagne->fin->isFuture() ? $aujourdhui : $campagne->fin->copy()];
            }
        }
        if ($periode === 'perso') {
            try {
                $du = $this->date($request->query('du'));
                $au = $this->date($request->query('au'));
                if ($du !== null || $au !== null) {
                    return [$periode, $du, $au !== null && $au->isFuture() ? $aujourdhui : $au];
                }
            } catch (Throwable) {
                // date illisible : on retombe sur « tout »
            }
        }

        return ['tout', null, null];
    }

    private function date(mixed $valeur): ?Carbon
    {
        return is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1 ? Carbon::createFromFormat('Y-m-d', $valeur)->startOfDay() : null;
    }
}
