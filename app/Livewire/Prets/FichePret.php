<?php

namespace App\Livewire\Prets;

use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\StatutPret;
use App\Exceptions\OperationRefusee;
use App\Models\CompteTresorerie;
use App\Models\Intrant;
use App\Models\Magasin;
use App\Models\Pret;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Prets;
use App\Services\Remboursements;
use App\Services\StockIntrants;
use App\Support\Fichiers;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Fiche d'un prêt : validations (par d'autres que l'auteur), refus, décaissements par
 * tranches. Chaque action passe par App\Services\Prets, qui revérifie tout.
 */
class FichePret extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $pretId = '';

    public string $statut = '';

    public bool $refusOuvert = false;

    public string $motifRefus = '';

    public bool $annulationOuverte = false;

    public string $motifAnnulation = '';

    public bool $decaissementOuvert = false;

    public string $compteId = '';

    public string $montant = '';

    public string $dateDecaissement = '';

    public string $reference = '';

    /** @var TemporaryUploadedFile|null */
    public $recu = null;

    /** Mode du versement d'argent ; au choix pour un prêt mixte. */
    public string $modeVersement = '';

    public bool $remiseOuverte = false;

    public string $intrantId = '';

    public string $magasinId = '';

    public string $quantiteIntrant = '';

    public string $dateRemise = '';

    public bool $remboursementOuvert = false;

    public string $compteRemboursementId = '';

    public string $montantRemboursement = '';

    public string $dateRemboursement = '';

    public string $referenceRemboursement = '';

    public ?int $remboursementAContrePasser = null;

    public string $motifContrePassation = '';

    public function mount(Pret $pret): void
    {
        $this->authorize('voir-prets');
        $this->pretId = $pret->id;
        $this->statut = (string) session('statut', '');
    }

    private function pret(): Pret
    {
        return Pret::query()->findOrFail($this->pretId);
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function valider(): void
    {
        $this->authorize('valider-prets');
        $this->resetErrorBag();

        try {
            $pret = Prets::valider($this->pret(), $this->moi());
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        $this->statut = $pret->statut === StatutPret::Valide
            ? 'Prêt validé : il peut être décaissé.'
            : 'Validation enregistrée : une autre personne de la direction doit encore valider.';
    }

    public function ouvrirRefus(): void
    {
        $this->authorize('valider-prets');
        $this->refusOuvert = true;
        $this->motifRefus = '';
    }

    public function refuser(): void
    {
        $this->authorize('valider-prets');
        $this->resetErrorBag();

        try {
            Prets::refuser($this->pret(), $this->moi(), $this->motifRefus);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifRefus' => $e->getMessage()]);
        }

        $this->refusOuvert = false;
        $this->statut = 'Demande refusée.';
    }

    public function ouvrirAnnulation(): void
    {
        $this->authorize('annuler-operation', $this->pret());
        $this->resetErrorBag();
        $this->annulationOuverte = true;
        $this->motifAnnulation = '';
    }

    /** « Supprimer » : le prêt passe « annulé » (le service revérifie droit, motif et remises). */
    public function annulerPret(): void
    {
        $this->resetErrorBag();

        try {
            Prets::annuler($this->pret(), $this->moi(), $this->motifAnnulation);
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifAnnulation' => $e->getMessage()]);
        }

        $this->annulationOuverte = false;
        $this->statut = 'Prêt supprimé (annulé) : il ne compte plus nulle part, la trace reste.';
    }

    public function ouvrirDecaissement(): void
    {
        $this->authorize('decaisser-prets');
        $this->resetErrorBag();
        $this->reset('compteId', 'reference', 'recu');
        $pret = $this->pret();
        $this->montant = (string) $pret->resteARemettre();
        $this->modeVersement = ($pret->forme === FormePret::MobileMoney ? ModeDecaissement::MobileMoney : ModeDecaissement::Especes)->value;
        $this->dateDecaissement = Carbon::today()->toDateString();
        $this->remiseOuverte = false;
        $this->decaissementOuvert = true;
    }

    /** Changer de mode change la liste des comptes possibles. */
    public function updatedModeVersement(): void
    {
        $this->compteId = '';
    }

    public function ouvrirRemise(): void
    {
        $this->authorize('decaisser-prets');
        $this->resetErrorBag();
        $this->reset('intrantId', 'magasinId', 'quantiteIntrant');
        $this->dateRemise = Carbon::today()->toDateString();
        $this->decaissementOuvert = false;
        $this->remiseOuverte = true;
    }

    public function remettreIntrants(): void
    {
        $this->authorize('decaisser-prets');
        $this->resetErrorBag();

        $this->validate([
            'intrantId' => ['required', 'integer', Rule::exists('intrants', 'id')],
            'magasinId' => ['required', 'integer', Rule::exists('magasins', 'id')],
            'quantiteIntrant' => ['required', 'integer', 'min:1'],
            'dateRemise' => ['required', 'date', 'before_or_equal:today'],
        ], ['dateRemise.before_or_equal' => 'La date ne peut pas être dans le futur.'],
            ['intrantId' => 'intrant', 'magasinId' => 'magasin', 'quantiteIntrant' => 'quantité', 'dateRemise' => 'date']);

        try {
            StockIntrants::distribuer(
                $this->pret(), Intrant::query()->findOrFail((int) $this->intrantId), Magasin::query()->findOrFail((int) $this->magasinId),
                (int) $this->quantiteIntrant, Carbon::parse($this->dateRemise), $this->moi(),
            );
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['quantiteIntrant' => $e->getMessage()]);
        }

        $this->remiseOuverte = false;
        $this->statut = 'Intrants remis ; le stock et le restant dû sont à jour.';
    }

    public function ouvrirRemboursement(): void
    {
        $this->authorize('encaisser-remboursements');
        $this->resetErrorBag();
        $this->reset('compteRemboursementId', 'referenceRemboursement');
        $this->montantRemboursement = (string) $this->pret()->restantDu();
        $this->dateRemboursement = Carbon::today()->toDateString();
        $this->decaissementOuvert = false;
        $this->remiseOuverte = false;
        $this->remboursementOuvert = true;
    }

    public function encaisserRemboursement(): void
    {
        $this->authorize('encaisser-remboursements');
        $this->resetErrorBag();

        $this->validate([
            'compteRemboursementId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')->where('actif', true)],
            'montantRemboursement' => ['required', Montant::regle()],
            'dateRemboursement' => ['required', 'date', 'before_or_equal:today'],
            'referenceRemboursement' => ['nullable', 'string', 'max:100'],
        ], ['dateRemboursement.before_or_equal' => 'La date ne peut pas être dans le futur.'],
            ['compteRemboursementId' => 'compte', 'montantRemboursement' => 'montant', 'dateRemboursement' => 'date']);

        try {
            Remboursements::especes(
                $this->pret(), CompteTresorerie::query()->findOrFail((int) $this->compteRemboursementId),
                (int) Montant::depuisSaisie($this->montantRemboursement), Carbon::parse($this->dateRemboursement),
                $this->moi(), $this->referenceRemboursement ?: null,
            );
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['montantRemboursement' => $e->getMessage()]);
        }

        $this->remboursementOuvert = false;
        $this->statut = 'Remboursement encaissé ; la trésorerie et le restant dû sont à jour.';
    }

    public function preparerContrePassationRemboursement(int $id): void
    {
        $this->authorize('encaisser-remboursements');
        $this->resetErrorBag();
        $this->motifContrePassation = '';
        $this->remboursementAContrePasser = Remboursement::query()->where('pret_id', $this->pretId)->findOrFail($id)->id;
    }

    public function contrePasserRemboursement(): void
    {
        $this->authorize('encaisser-remboursements');
        $this->resetErrorBag();

        try {
            Remboursements::contrePasser(
                Remboursement::query()->where('pret_id', $this->pretId)->findOrFail((int) $this->remboursementAContrePasser),
                $this->motifContrePassation, $this->moi(),
            );
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['motifContrePassation' => $e->getMessage()]);
        }

        $this->remboursementAContrePasser = null;
        $this->statut = 'Remboursement contre-passé.';
    }

    public function decaisser(): void
    {
        $this->authorize('decaisser-prets');
        $this->resetErrorBag();
        $pret = $this->pret();
        $this->validate(['modeVersement' => ['required', Rule::enum(ModeDecaissement::class)]]);
        $mode = ModeDecaissement::from($this->modeVersement);

        $this->validate([
            'compteId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')],
            'montant' => ['required', Montant::regle()],
            'dateDecaissement' => ['required', 'date', 'before_or_equal:today'],
            'reference' => [$mode === ModeDecaissement::MobileMoney ? 'required' : 'nullable', 'string', 'max:100'],
            'recu' => [$mode === ModeDecaissement::Especes ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ], [
            'recu.required' => 'Versement en espèces : joindre le reçu signé par le producteur.',
            'reference.required' => 'Versement Mobile Money : la référence de la transaction est obligatoire.',
            'dateDecaissement.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ], ['compteId' => 'compte', 'dateDecaissement' => 'date', 'reference' => 'référence', 'recu' => 'reçu']);

        $chemin = $this->recu?->store('prets/recus', Fichiers::disque());

        try {
            Prets::decaisser($pret, [
                'compte_id' => (int) $this->compteId,
                'mode' => $mode,
                'montant_fcfa' => (int) Montant::depuisSaisie($this->montant),
                'date' => Carbon::parse($this->dateDecaissement),
                'reference' => $this->reference ?: null,
            ], $this->moi(), $chemin ?: null);
        } catch (OperationRefusee $e) {
            if ($chemin) {
                Storage::disk(Fichiers::disque())->delete($chemin);
            }
            throw ValidationException::withMessages(['montant' => $e->getMessage()]);
        }

        $this->decaissementOuvert = false;
        $this->statut = 'Versement enregistré ; la trésorerie est à jour.';
    }

    public function render(): View
    {
        $pret = Pret::query()
            ->with(['producteur.village.zone', 'campagne.produit', 'auteur', 'validations.user', 'parcelles',
                'decaissements' => fn ($q) => $q->with('compte', 'auteur', 'mouvement.contrePassation')->orderBy('id'),
                'mouvementsIntrants' => fn ($q) => $q->with('intrant', 'magasin', 'auteur', 'contrePassation')->orderBy('id'),
                'remboursements' => fn ($q) => $q->with('achat', 'auteur', 'contrePassation')->orderBy('id')])
            ->findOrFail($this->pretId);
        $mode = ModeDecaissement::tryFrom($this->modeVersement)
            ?? ($pret->forme === FormePret::MobileMoney ? ModeDecaissement::MobileMoney : ModeDecaissement::Especes);
        $moi = $this->moi();
        $intrant = ctype_digit($this->intrantId) ? Intrant::query()->find((int) $this->intrantId) : null;

        return view('livewire.prets.fiche-pret', [
            'pret' => $pret,
            'decaisse' => $pret->montantDecaisse(),
            'intrantsRemis' => $pret->valeurIntrantsRemis(),
            'surface' => $pret->surfaceFinanceeM2(),
            'mode' => $mode,
            'modes' => array_values(array_filter(ModeDecaissement::cases(), fn (ModeDecaissement $m) => $pret->forme->accepteArgent($m))),
            'comptes' => CompteTresorerie::query()->where('actif', true)->whereIn('type', $mode->typesDeCompte())->orderBy('nom')->get(),
            'intrants' => Intrant::query()->where('actif', true)->orderBy('nom')->get(),
            'magasins' => Magasin::query()->where('actif', true)->orderBy('nom')->get(),
            // Aperçu de la valeur d'une remise, au prix du jour (le service la recalcule).
            'valeurApercu' => $intrant !== null && ctype_digit($this->quantiteIntrant) ? (int) $this->quantiteIntrant * $intrant->prix_unitaire_fcfa : null,
            'peutRemettreIntrants' => $pret->statut === StatutPret::Valide && $pret->forme->accepteIntrants() && $moi->can('decaisser-prets'),
            'rembourse' => $pret->montantRembourse(),
            'comptesRemboursement' => CompteTresorerie::query()->where('actif', true)->orderBy('nom')->get(),
            'peutEncaisser' => in_array($pret->statut, [StatutPret::Valide, StatutPret::Decaisse], true)
                && $pret->restantDu() > 0 && $moi->can('encaisser-remboursements'),
            'peutVerserArgent' => $pret->statut === StatutPret::Valide && $pret->forme !== FormePret::Intrants && $moi->can('decaisser-prets'),
            // Supprimer / corriger : l'auteur ou la direction, tant que rien n'a été remis.
            'peutAnnuler' => in_array($pret->statut, [StatutPret::Demande, StatutPret::Valide], true)
                && $pret->montantRemis() === 0 && $moi->can('annuler-operation', $pret),
            'peutValider' => $pret->statut === StatutPret::Demande && $moi->can('valider-prets')
                && $pret->cree_par !== $moi->id && ! $pret->validations->contains('user_id', $moi->id),
        ])->title('Prêt '.$pret->reference);
    }
}
