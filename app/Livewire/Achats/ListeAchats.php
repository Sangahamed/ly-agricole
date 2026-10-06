<?php

namespace App\Livewire\Achats;

use App\Enums\StatutAchat;
use App\Exceptions\OperationRefusee;
use App\Models\Achat;
use App\Models\User;
use App\Services\Achats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Achats : un agent voit les siens ; direction et comptable voient tout et valident
 * ceux des autres (le service revérifie la séparation des tâches).
 */
#[Title('Achats')]
class ListeAchats extends Component
{
    use WithPagination;

    public const PAR_PAGE = 30;

    #[Url(as: 'statut', except: '')]
    public string $filtreStatut = '';

    public ?string $aRefuser = null;

    public string $motifRefus = '';

    public ?string $aAnnuler = null;

    public string $motifAnnulation = '';

    public string $statut = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['saisir-achats', 'valider-achats']), 403);
        $this->statut = (string) session('statut', '');
    }

    public function updatedFiltreStatut(): void
    {
        $this->resetPage();
    }

    public function valider(string $id): void
    {
        $this->authorize('valider-achats');
        $this->resetErrorBag();

        try {
            Achats::valider(Achat::query()->findOrFail($id), $this->moi());
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        $this->statut = 'Achat validé : stock, paiement et prêt à jour.';
    }

    public function preparerRefus(string $id): void
    {
        $this->authorize('valider-achats');
        $this->resetErrorBag();
        $this->motifRefus = '';
        $this->aRefuser = Achat::query()->findOrFail($id)->id;
    }

    public function refuser(): void
    {
        $this->authorize('valider-achats');
        $this->resetErrorBag();

        try {
            Achats::refuser(Achat::query()->findOrFail((string) $this->aRefuser), $this->moi(), $this->motifRefus);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifRefus' => $e->getMessage()]);
        }

        $this->aRefuser = null;
        $this->statut = 'Achat refusé.';
    }

    public function preparerAnnulation(string $id): void
    {
        $achat = Achat::query()->findOrFail($id);
        $this->authorize('annuler-operation', $achat);
        $this->resetErrorBag();
        $this->motifAnnulation = '';
        $this->aAnnuler = $achat->id;
    }

    /** « Supprimer » : annulation par contre-passation (le service revérifie droit, motif et stock). */
    public function annulerAchat(): void
    {
        $this->resetErrorBag();

        try {
            // Le service vérifie le droit (auteur ou direction) sur la ligne elle-même.
            $achat = Achats::annuler(Achat::query()->findOrFail((string) $this->aAnnuler), $this->moi(), $this->motifAnnulation);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifAnnulation' => $e->getMessage()]);
        }

        $this->aAnnuler = null;
        $this->statut = "Achat {$achat->reference} supprimé (annulé) : stock, caisse et prêt remis comme avant.";
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $moi = $this->moi();
        $toutVoir = $moi->can('valider-achats');

        return view('livewire.achats.liste-achats', [
            'achats' => Achat::query()
                ->with('producteur', 'pisteur', 'lot', 'pret', 'auteur', 'validateur', 'photoPesee')
                ->when(! $toutVoir, fn ($q) => $q->where('cree_par', $moi->id))
                ->when(StatutAchat::tryFrom($this->filtreStatut), fn ($q, $s) => $q->where('statut', $s))
                ->orderByRaw('CASE WHEN statut = ? THEN 0 ELSE 1 END', [StatutAchat::AValider->value])
                ->orderByDesc('date_achat')->paginate(self::PAR_PAGE),
            'statuts' => StatutAchat::cases(),
            'peutValider' => $toutVoir,
            'estDirection' => $moi->can('annuler-operations'),
            'achatAAnnuler' => $this->aAnnuler === null ? null : Achat::query()->find($this->aAnnuler),
            'moi' => $moi->id,
        ]);
    }
}
