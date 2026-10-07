<?php

namespace App\Livewire\Prets;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutPret;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\Parametre;
use App\Models\Parcelle;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Services\CautionSolidaire;
use App\Services\Prets;
use App\Support\Fichiers;
use App\Support\Format;
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
 * Demande de prêt (après la visite de la parcelle). Les règles — plafonds, nombre de
 * validations, art. 17.3 — sont dans App\Services\Prets.
 */
#[Title('Nouvelle demande de prêt')]
class FormulairePret extends Component
{
    use WithFileUploads;

    #[Url(as: 'producteur', except: '')]
    public string $producteurId = '';

    public string $campagneId = '';

    public string $montant = '';

    public string $forme = '';

    public string $prixReference = '';

    public string $echeance = '';

    /**
     * Cases cochées. Après un décochage, Livewire peut renvoyer des clés non
     * consécutives : d'où le array_values() à l'enregistrement.
     *
     * @var array<int|string, string>
     */
    public array $parcelleIds = [];

    public bool $partieLiee = false;

    /** « Modifier » un prêt = le remplacer : l'ancien passe « annulé », la trace reste. */
    #[Url(as: 'corrige', except: '')]
    public string $corrigeId = '';

    /** @var TemporaryUploadedFile|null */
    public $accordEcrit = null;

    public function mount(): void
    {
        $this->authorize('saisir-prets');
        $this->campagneId = (string) (Campagne::query()->where('statut', StatutCampagne::Ouverte)->value('id') ?? '');

        if ($this->corrigeId !== '') {
            $pret = $this->pretCorrige();
            $this->producteurId = $pret->producteur_id;
            $this->campagneId = (string) $pret->campagne_id;
            $this->montant = (string) $pret->montant_fcfa;
            $this->forme = $pret->forme->value;
            $this->prixReference = $pret->prix_reference_kg_fcfa === null ? '' : (string) $pret->prix_reference_kg_fcfa;
            $this->echeance = $pret->echeance->toDateString();
            $this->parcelleIds = $pret->parcelles()->pluck('parcelles.id')->all();
            $this->partieLiee = $pret->partie_liee;
        }
    }

    /** Le prêt à remplacer : à son auteur ou à la direction, et rien encore remis. */
    private function pretCorrige(): Pret
    {
        $pret = Pret::query()->findOrFail($this->corrigeId);
        $this->authorize('annuler-operation', $pret);
        abort_unless(in_array($pret->statut, [StatutPret::Demande, StatutPret::Valide], true) && $pret->montantRemis() === 0, 403,
            'Ce prêt ne se modifie plus : quelque chose a déjà été remis au producteur.');

        return $pret;
    }

    public function updatedProducteurId(): void
    {
        $this->parcelleIds = [];
    }

    public function enregistrer(): void
    {
        $this->authorize('saisir-prets');
        $this->resetErrorBag();

        $this->validate([
            'producteurId' => ['required', 'uuid', Rule::exists('producteurs', 'id')],
            'campagneId' => ['required', 'integer', Rule::exists('campagnes', 'id')],
            'montant' => ['required', Montant::regle()],
            'forme' => ['required', Rule::enum(FormePret::class)],
            'prixReference' => ['nullable', Montant::regle()],
            'echeance' => ['required', 'date', 'after_or_equal:today'],
            'parcelleIds' => ['array'],
            'parcelleIds.*' => ['uuid'],
            'accordEcrit' => [$this->partieLiee && $this->accordDuPretCorrige() === null ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ], [
            'accordEcrit.required' => 'Producteur lié à la direction : joindre l\'accord écrit (contrat, art. 17.3).',
            'echeance.after_or_equal' => 'L\'échéance ne peut pas être passée.',
        ], [
            'producteurId' => 'producteur',
            'campagneId' => 'campagne',
            'prixReference' => 'prix de référence',
            'echeance' => 'échéance',
            'accordEcrit' => 'accord écrit',
        ]);

        /** @var User $auteur */
        $auteur = auth()->user();
        $chemin = $this->partieLiee ? $this->accordEcrit?->store('prets/accords', Fichiers::disque()) : null;
        $donnees = [
            'producteur_id' => $this->producteurId,
            'campagne_id' => (int) $this->campagneId,
            'montant_fcfa' => (int) Montant::depuisSaisie($this->montant),
            'forme' => FormePret::from($this->forme),
            'echeance' => Carbon::parse($this->echeance),
            'prix_reference_kg_fcfa' => Montant::depuisSaisie($this->prixReference),
            'parcelle_ids' => array_values($this->parcelleIds),
            'partie_liee' => $this->partieLiee,
        ];

        try {
            $pret = DB::transaction(function () use ($donnees, $auteur, $chemin) {
                if ($this->corrigeId === '') {
                    return Prets::demander($donnees, $auteur, $chemin ?: null);
                }
                // Annuler d'abord : l'ancien montant ne doit pas compter dans le plafond du nouveau.
                $ancien = Prets::annuler($this->pretCorrige(), $auteur, 'Modifié : remplacé par une nouvelle saisie');
                $nouveau = Prets::demander($donnees, $auteur, ($chemin ?: null) ?? ($this->partieLiee ? $ancien->accord_ecrit : null));
                $ancien->update(['motif_annulation' => "Modifié : remplacé par le prêt {$nouveau->reference}"]);

                return $nouveau;
            });
        } catch (OperationRefusee $e) {
            if ($chemin) {
                Storage::disk(Fichiers::disque())->delete($chemin);
            }
            throw ValidationException::withMessages(['montant' => $e->getMessage()]);
        }

        session()->flash('statut', match (true) {
            $this->corrigeId !== '' => "Prêt modifié : il est remplacé par {$pret->reference}, l'ancien reste visible, marqué annulé.",
            $pret->statut === StatutPret::Valide => "Prêt {$pret->reference} accordé directement (direction) : il peut être versé.",
            default => "Demande {$pret->reference} enregistrée : {$pret->validations_requises} validation(s) de la direction requise(s).",
        });
        $this->redirectRoute('prets.fiche', $pret);
    }

    private function accordDuPretCorrige(): ?string
    {
        return $this->corrigeId === '' ? null : Pret::query()->whereKey($this->corrigeId)->value('accord_ecrit');
    }

    private function producteurChoisi(): ?Producteur
    {
        return Producteur::query()->find($this->producteurId);
    }

    public function render(): View
    {
        $seuil = Parametre::entier(CleParametre::SeuilValidationPret);
        /** @var User $moi */
        $moi = auth()->user();

        // Caution solidaire du groupe (question 37) : message générique, jamais le nom d'un autre producteur.
        $caution = $this->producteurId === '' ? null : ($this->producteurChoisi() === null ? null : CautionSolidaire::controle($this->producteurChoisi()));

        return view('livewire.prets.formulaire-pret', [
            'caution' => $caution !== null && $caution['niveau'] !== CautionSolidaire::AUCUN ? $caution : null,
            'producteurs' => Producteur::query()->with('village')->where('actif', true)->orderBy('nom')->orderBy('prenoms')->get(),
            'parcelles' => $this->producteurId === ''
                ? collect()
                : Parcelle::query()->where('producteur_id', $this->producteurId)->where('actif', true)->orderBy('nom')->get(),
            'campagnes' => Campagne::query()->with('produit')->where('statut', '!=', StatutCampagne::Cloturee)->orderByDesc('debut')->get(),
            'formes' => FormePret::cases(),
            'pretCorrige' => $this->corrigeId === '' ? null : Pret::query()->find($this->corrigeId),
            'regleValidation' => $moi->aLeRole(Role::Direction)
                ? 'Vous êtes la direction : votre prêt est accordé dès l\'enregistrement, sans autre validation.'
                : ($seuil === null
                ? 'Seuil non défini : toute demande sera validée par deux personnes de la direction.'
                : 'Au-dessus de '.Format::fcfa($seuil).', deux validations de la direction ; en dessous, une.'),
        ]);
    }
}
