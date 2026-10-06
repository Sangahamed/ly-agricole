<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\Producteur;
use App\Services\CarteProducteur;
use App\Services\SuppressionFiches;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProducteurController extends Controller
{
    public function fiche(Producteur $producteur): View
    {
        Gate::authorize('voir-producteurs');

        $producteur->load(['village.zone', 'groupe', 'auteurConsentement', 'parcelles' => fn ($q) => $q->with('produit')
            ->withCount('visites')->withMax('visites', 'date_visite')->orderBy('nom')]);

        return view('producteurs.fiche', ['producteur' => $producteur]);
    }

    /** « Supprimer » : seulement une fiche qui n'a encore servi à rien (le service vérifie). */
    public function supprimer(Producteur $producteur): RedirectResponse
    {
        Gate::authorize('annuler-operation', $producteur);

        try {
            SuppressionFiches::producteur($producteur, auth()->user());
        } catch (OperationRefusee $e) {
            return redirect()->route('producteurs.fiche', $producteur)->with('refus', $e->getMessage());
        }

        return redirect()->route('producteurs')->with('statut', "Fiche de {$producteur->nomComplet()} supprimée.");
    }

    public function carte(Producteur $producteur): Response
    {
        Gate::authorize('gerer-producteurs');

        return CarteProducteur::telecharger($producteur);
    }

    /**
     * La photo vit sur le disque privé : elle ne passe que par ici, droit vérifié.
     */
    public function photo(Producteur $producteur): StreamedResponse
    {
        Gate::authorize('voir-producteurs');
        abort_if($producteur->photo === null || ! Storage::disk('local')->exists($producteur->photo), 404);

        return Storage::disk('local')->response($producteur->photo, headers: [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
