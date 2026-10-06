<?php

namespace App\Livewire\Depenses;

use App\Enums\StatutDepense;
use App\Exceptions\OperationRefusee;
use App\Models\Depense;
use App\Models\User;
use App\Services\Depenses;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Dépenses : un agent voit les siennes ; direction et comptable voient tout et
 * valident celles des autres. Le service revérifie tout (séparation des tâches).
 */
#[Title('Dépenses')]
class ListeDepenses extends Component
{
    use WithPagination;

    public const PAR_PAGE = 30;

    #[Url(as: 'statut', except: '')]
    public string $filtreStatut = '';

    /** Dépense dont le refus est en préparation. */
    public ?string $aRefuser = null;

    public string $motifRefus = '';

    public ?string $aAnnuler = null;

    public string $motifAnnulation = '';

    public string $statut = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['saisir-depenses', 'valider-depenses']), 403);
        $this->statut = (string) session('statut', '');
    }

    public function updatedFiltreStatut(): void
    {
        $this->resetPage();
    }

    public function valider(string $id): void
    {
        $this->authorize('valider-depenses');
        $this->resetErrorBag();

        try {
            Depenses::valider(Depense::query()->findOrFail($id), $this->utilisateur());
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        $this->statut = 'Dépense validée et payée.';
    }

    public function preparerRefus(string $id): void
    {
        $this->authorize('valider-depenses');
        $this->resetErrorBag();
        $this->statut = '';
        $this->motifRefus = '';
        $this->aRefuser = Depense::query()->findOrFail($id)->id;
    }

    public function annulerRefus(): void
    {
        $this->aRefuser = null;
    }

    public function refuser(): void
    {
        $this->authorize('valider-depenses');
        // Sans cela, le message d'un essai refusé reste affiché après un essai réussi.
        $this->resetErrorBag();

        try {
            Depenses::refuser(Depense::query()->findOrFail((string) $this->aRefuser), $this->utilisateur(), $this->motifRefus);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifRefus' => $e->getMessage()]);
        }

        $this->statut = 'Dépense refusée.';
        $this->aRefuser = null;
    }

    public function preparerAnnulation(string $id): void
    {
        $depense = Depense::query()->findOrFail($id);
        $this->authorize('annuler-operation', $depense);
        $this->resetErrorBag();
        $this->statut = '';
        $this->motifAnnulation = '';
        $this->aAnnuler = $depense->id;
    }

    /** « Supprimer » : annulation (contre-passation du paiement s'il a eu lieu). */
    public function annulerDepense(): void
    {
        $this->resetErrorBag();

        try {
            // Le service vérifie le droit (auteur ou direction) sur la ligne elle-même.
            Depenses::annuler(Depense::query()->findOrFail((string) $this->aAnnuler), $this->utilisateur(), $this->motifAnnulation);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifAnnulation' => $e->getMessage()]);
        }

        $this->aAnnuler = null;
        $this->statut = 'Dépense supprimée (annulée) : la caisse est remise comme avant.';
    }

    private function utilisateur(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $user = $this->utilisateur();
        $toutVoir = $user->can('valider-depenses');

        $depenses = Depense::query()
            ->with('categorie', 'compte', 'auteur', 'validateur')
            ->when(! $toutVoir, fn ($q) => $q->where('cree_par', $user->id))
            ->when(StatutDepense::tryFrom($this->filtreStatut), fn ($q, $s) => $q->where('statut', $s))
            ->orderByRaw('CASE WHEN statut = ? THEN 0 ELSE 1 END', [StatutDepense::AValider->value])
            ->orderByDesc('date_depense')->orderByDesc('created_at')
            ->paginate(self::PAR_PAGE);

        return view('livewire.depenses.liste-depenses', [
            'depenses' => $depenses,
            'statuts' => StatutDepense::cases(),
            'peutValider' => $toutVoir,
            'estDirection' => $user->can('annuler-operations'),
            'moi' => $user->id,
        ]);
    }
}
