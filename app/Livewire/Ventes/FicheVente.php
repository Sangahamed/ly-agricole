<?php

namespace App\Livewire\Ventes;

use App\Enums\StatutVente;
use App\Exceptions\OperationRefusee;
use App\Models\CompteTresorerie;
use App\Models\Encaissement;
use App\Models\User;
use App\Models\Vente;
use App\Services\Encaissements;
use App\Services\Ventes;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fiche d'une vente : encaissements et reste à encaisser (App\Services\Encaissements),
 * marge du lot pour situer cette vente dans l'ensemble.
 */
class FicheVente extends Component
{
    #[Locked]
    public string $venteId;

    public bool $formulaireEncaissement = false;

    public string $compteId = '';

    public string $montantEncaisse = '';

    public string $dateEncaissement = '';

    public string $referencePaiement = '';

    public ?int $aContrePasser = null;

    public string $motifContrePassation = '';

    public bool $annulationOuverte = false;

    public string $motifAnnulation = '';

    public function mount(Vente $vente): void
    {
        $this->authorize('voir-ventes');
        $this->venteId = $vente->id;
        $this->dateEncaissement = now()->format('Y-m-d');
    }

    public function ouvrirEncaissement(): void
    {
        $this->authorize('encaisser-ventes');
        $this->resetErrorBag();
        $this->montantEncaisse = '';
        $this->referencePaiement = '';
        $this->formulaireEncaissement = true;
    }

    public function encaisser(): void
    {
        $this->authorize('encaisser-ventes');
        $this->resetErrorBag();

        $this->validate([
            'compteId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')],
            'montantEncaisse' => ['required', Montant::regle()],
            'dateEncaissement' => ['required', 'date', 'before_or_equal:today'],
            'referencePaiement' => ['nullable', 'string', 'max:100'],
        ], attributes: ['compteId' => 'compte', 'montantEncaisse' => 'montant encaissé', 'dateEncaissement' => 'date']);

        try {
            Encaissements::encaisser(
                $this->vente(), CompteTresorerie::query()->findOrFail((int) $this->compteId),
                (int) Montant::depuisSaisie($this->montantEncaisse), Carbon::parse($this->dateEncaissement),
                $this->moi(), $this->referencePaiement ?: null,
            );
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['montantEncaisse' => $e->getMessage()]);
        }

        $this->formulaireEncaissement = false;
        session()->flash('statut', 'Encaissement enregistré.');
    }

    public function preparerContrePassation(int $id): void
    {
        $this->authorize('encaisser-ventes');
        $this->resetErrorBag();
        $this->motifContrePassation = '';
        $this->aContrePasser = $id;
    }

    public function contrePasser(): void
    {
        $this->authorize('encaisser-ventes');
        $this->resetErrorBag();

        try {
            Encaissements::contrePasser(Encaissement::query()->findOrFail($this->aContrePasser), $this->motifContrePassation, $this->moi());
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifContrePassation' => $e->getMessage()]);
        }

        $this->aContrePasser = null;
        session()->flash('statut', 'Encaissement contre-passé.');
    }

    public function ouvrirAnnulation(): void
    {
        $this->authorize('annuler-operation', $this->vente());
        $this->resetErrorBag();
        $this->annulationOuverte = true;
        $this->motifAnnulation = '';
    }

    /** « Supprimer » : encaissements contre-passés, kilos rendus au lot (le service revérifie tout). */
    public function annulerVente(): void
    {
        $this->resetErrorBag();

        try {
            Ventes::annuler($this->vente(), $this->moi(), $this->motifAnnulation);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifAnnulation' => $e->getMessage()]);
        }

        $this->annulationOuverte = false;
        session()->flash('statut', 'Vente supprimée (annulée) : stock et comptes remis comme avant, la trace reste.');
    }

    private function vente(): Vente
    {
        return Vente::query()->findOrFail($this->venteId);
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $vente = $this->vente()->load('lot.magasin', 'campagne.produit', 'auteur', 'validateur', 'annuleur',
            'encaissements.compte', 'encaissements.auteur', 'encaissements.contrePassation');

        return view('livewire.ventes.fiche-vente', [
            'vente' => $vente,
            'marge' => Ventes::margeLot($vente->lot),
            'comptes' => CompteTresorerie::query()->where('actif', true)->orderBy('nom')->get(),
            'peutAnnuler' => in_array($vente->statut, [StatutVente::AValider, StatutVente::Valide], true)
                && $this->moi()->can('annuler-operation', $vente),
        ]);
    }
}
