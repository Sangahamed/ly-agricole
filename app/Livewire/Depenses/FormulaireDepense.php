<?php

namespace App\Livewire\Depenses;

use App\Enums\Role;
use App\Enums\StatutDepense;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\CategorieDepense;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\User;
use App\Services\Depenses;
use App\Support\Fichiers;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Saisie d'une dépense avec son justificatif (obligatoire). Le seuil et la séparation
 * des tâches sont appliqués par App\Services\Depenses, pas par cet écran.
 */
#[Title('Nouvelle dépense')]
class FormulaireDepense extends Component
{
    use WithFileUploads;

    public const JUSTIFICATIF_MAX_KO = 8192;

    public string $categorieId = '';

    public string $compteId = '';

    public string $montant = '';

    public string $dateDepense = '';

    public string $beneficiaire = '';

    public string $description = '';

    public string $campagneId = '';

    /** @var TemporaryUploadedFile|null */
    public $justificatif = null;

    /** « Modifier » une dépense = la remplacer : l'ancienne passe « annulée », la trace reste. */
    #[Url(as: 'corrige', except: '')]
    public string $corrigeId = '';

    public function mount(): void
    {
        $this->authorize('saisir-depenses');
        $this->dateDepense = Carbon::today()->toDateString();

        if ($this->corrigeId !== '') {
            $depense = $this->depenseCorrigee();
            $this->categorieId = (string) $depense->categorie_id;
            $this->compteId = (string) $depense->compte_id;
            $this->montant = (string) $depense->montant_fcfa;
            $this->dateDepense = $depense->date_depense->toDateString();
            $this->beneficiaire = $depense->beneficiaire;
            $this->description = (string) $depense->description;
            $this->campagneId = $depense->campagne_id === null ? '' : (string) $depense->campagne_id;
        }
    }

    /** La dépense à remplacer : à son auteur ou à la direction, à valider ou payée. */
    private function depenseCorrigee(): Depense
    {
        $depense = Depense::query()->findOrFail($this->corrigeId);
        $this->authorize('annuler-operation', $depense);
        abort_unless(in_array($depense->statut, [StatutDepense::AValider, StatutDepense::Payee], true), 403, 'Cette dépense ne se modifie plus.');

        return $depense;
    }

    public function updatedJustificatif(): void
    {
        $this->validateOnly('justificatif', ['justificatif' => $this->regleJustificatif()], attributes: ['justificatif' => 'justificatif']);
    }

    /** @return list<string> */
    private function regleJustificatif(): array
    {
        // En modification, le justificatif de la dépense remplacée reste valable.
        return [$this->corrigeId === '' ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::JUSTIFICATIF_MAX_KO];
    }

    public function enregistrer(): void
    {
        $this->authorize('saisir-depenses');

        $this->validate([
            'categorieId' => ['required', 'integer', Rule::exists('categories_depense', 'id')->where('actif', true)],
            'compteId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')],
            'montant' => ['required', Montant::regle()],
            'dateDepense' => ['required', 'date', 'before_or_equal:today'],
            'beneficiaire' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'campagneId' => ['nullable', 'integer', Rule::exists('campagnes', 'id')],
            'justificatif' => $this->regleJustificatif(),
        ], [
            'justificatif.required' => 'Le justificatif (photo du reçu ou PDF) est obligatoire.',
            'dateDepense.before_or_equal' => 'La date ne peut pas être dans le futur.',
        ], [
            'categorieId' => 'catégorie',
            'compteId' => 'compte',
            'dateDepense' => 'date',
            'beneficiaire' => 'bénéficiaire',
            'campagneId' => 'campagne',
            'justificatif' => 'justificatif',
        ]);

        /** @var User $auteur */
        $auteur = auth()->user();
        $nouveauFichier = $this->justificatif !== null;
        $chemin = $nouveauFichier
            ? $this->justificatif->store('depenses/justificatifs', Fichiers::disque())
            : Depense::query()->whereKey($this->corrigeId)->value('justificatif');
        $donnees = [
            'categorie_id' => (int) $this->categorieId,
            'compte_id' => (int) $this->compteId,
            'montant_fcfa' => (int) Montant::depuisSaisie($this->montant),
            'date_depense' => Carbon::parse($this->dateDepense),
            'beneficiaire' => $this->beneficiaire,
            'description' => $this->description ?: null,
            'campagne_id' => $this->campagneId === '' ? null : (int) $this->campagneId,
        ];

        try {
            $depense = DB::transaction(function () use ($donnees, $chemin, $auteur) {
                if ($this->corrigeId === '') {
                    return Depenses::saisir($donnees, (string) $chemin, $auteur);
                }
                $ancienne = Depenses::annuler($this->depenseCorrigee(), $auteur, 'Modifiée : remplacée par une nouvelle saisie');

                return Depenses::saisir($donnees + ['parcelle_id' => $ancienne->parcelle_id], (string) $chemin, $auteur);
            });
        } catch (OperationRefusee $e) {
            if ($nouveauFichier) {
                Storage::disk(Fichiers::disque())->delete((string) $chemin);
            }
            throw ValidationException::withMessages(['montant' => $e->getMessage()]);
        } catch (\Throwable $e) {
            if ($nouveauFichier) {
                Storage::disk(Fichiers::disque())->delete((string) $chemin);
            }
            throw $e;
        }

        session()->flash('statut', match (true) {
            $this->corrigeId !== '' => 'Dépense modifiée : l\'ancienne reste visible, marquée annulée, et la nouvelle la remplace.',
            $depense->statut === StatutDepense::Payee => 'Dépense enregistrée et payée.',
            default => 'Dépense enregistrée : elle attend la validation d\'une autre personne.',
        });
        $this->redirectRoute('depenses');
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.depenses.formulaire-depense', [
            'categories' => CategorieDepense::query()->where('actif', true)->orderBy('nom')->get(),
            'comptes' => CompteTresorerie::query()->orderBy('nom')->get()
                ->filter(fn (CompteTresorerie $c) => Depenses::peutPayerDepuis($user, $c)),
            'campagnes' => Campagne::query()->with('produit')->orderByDesc('debut')->get(),
            'regleSeuil' => $user->aLeRole(Role::Direction)
                ? 'Vous êtes la direction : votre dépense est payée dès l\'enregistrement, sans autre validation.'
                : Depenses::libelleSeuil(),
            'depenseCorrigee' => $this->corrigeId === '' ? null : Depense::query()->find($this->corrigeId),
        ]);
    }
}
