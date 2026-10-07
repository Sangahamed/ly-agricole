<?php

namespace App\Livewire\Tresorerie;

use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\TypeCompte;
use App\Exceptions\OperationRefusee;
use App\Livewire\Investisseurs\GestionApports;
use App\Models\Apport;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\User;
use App\Services\Apports;
use App\Services\Tresorerie;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Comptes de trésorerie et leurs soldes (somme des mouvements), avec les opérations
 * saisies au bureau : entrée d'argent, virement interne, avance à un agent.
 */
#[Title('Trésorerie')]
class Comptes extends Component
{
    /** null | 'compte' | 'entree' | 'virement' | 'avance' */
    public ?string $formulaire = null;

    public string $statut = '';

    // Nouveau compte
    public string $nom = '';

    public string $type = '';

    public string $titulaireId = '';

    public string $campagneId = '';

    // Opérations
    public string $compteId = '';

    public string $compteDestinationId = '';

    public string $nature = '';

    /** Nature « apport d'un investisseur » : qui apporte ('ly' = LY AGRICOLE elle-même). */
    public string $investisseurId = '';

    public string $montant = '';

    public string $dateOperation = '';

    public string $libelle = '';

    public string $reference = '';

    public function mount(): void
    {
        $this->authorize('gerer-tresorerie');
    }

    public function ouvrir(string $formulaire): void
    {
        $this->authorize('gerer-tresorerie');
        abort_unless(in_array($formulaire, ['compte', 'entree', 'virement', 'avance'], true), 404);

        $this->resetErrorBag();
        $this->reset('nom', 'type', 'titulaireId', 'campagneId', 'compteId', 'compteDestinationId', 'nature', 'investisseurId', 'montant', 'libelle', 'reference', 'statut');
        $this->dateOperation = Carbon::today()->toDateString();
        $this->formulaire = $formulaire;
    }

    public function annuler(): void
    {
        $this->resetErrorBag();
        $this->formulaire = null;
    }

    public function enregistrer(): void
    {
        $this->authorize('gerer-tresorerie');
        $this->resetErrorBag();

        match ($this->formulaire) {
            'compte' => $this->creerCompte(),
            'entree', 'virement', 'avance' => $this->saisirOperation(),
            default => abort(404),
        };
    }

    private function creerCompte(): void
    {
        $this->validate([
            'nom' => ['required', 'string', 'max:255', Rule::unique('comptes_tresorerie', 'nom')],
            'type' => ['required', Rule::enum(TypeCompte::class)],
            'titulaireId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Agent->value)],
            'campagneId' => ['nullable', 'integer', Rule::exists('campagnes', 'id')],
        ], attributes: ['nom' => 'nom', 'type' => 'type', 'titulaireId' => 'titulaire', 'campagneId' => 'campagne']);

        $compte = CompteTresorerie::query()->create([
            'nom' => trim($this->nom),
            'type' => $this->type,
            'titulaire_id' => $this->titulaireId === '' ? null : (int) $this->titulaireId,
            'campagne_id' => $this->campagneId === '' ? null : (int) $this->campagneId,
        ]);

        $this->statut = "Compte « {$compte->nom} » créé.";
        $this->formulaire = null;
    }

    /** Apport : la campagne du compte choisi est proposée d'office (modifiable). */
    public function updatedCompteId(): void
    {
        if ($this->nature === NatureMouvement::ApportCampagne->value && $this->campagneId === '' && ctype_digit($this->compteId)) {
            $this->campagneId = (string) (CompteTresorerie::query()->whereKey((int) $this->compteId)->value('campagne_id') ?? '');
        }
    }

    public function updatedNature(): void
    {
        $this->updatedCompteId();
    }

    private function saisirOperation(): void
    {
        $regles = [
            'compteId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')],
            'montant' => ['required', Montant::regle()],
            'dateOperation' => ['required', 'date', 'before_or_equal:today'],
            'libelle' => ['required', 'string', 'max:250'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
        if ($this->formulaire === 'entree') {
            $regles['nature'] = ['required', Rule::in(array_keys(self::naturesEntree()))];
            if ($this->nature === NatureMouvement::ApportCampagne->value) {
                // Liste ET saisie, comme l'écran Apports : un investisseur à compte, un nom tapé, ou « ly ».
                $regles['investisseurId'] = ['required', 'string', 'max:150'];
                $regles['campagneId'] = ['required', 'integer', Rule::exists('campagnes', 'id')];
            }
        } else {
            $regles['compteDestinationId'] = ['required', 'integer', 'different:compteId', Rule::exists('comptes_tresorerie', 'id')];
        }

        $this->validate($regles, [
            'dateOperation.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'compteDestinationId.different' => 'Choisir un compte différent du compte de départ.',
        ], [
            'compteId' => 'compte',
            'compteDestinationId' => 'compte de destination',
            'dateOperation' => 'date',
            'libelle' => 'libellé',
            'reference' => 'référence',
            'investisseurId' => 'investisseur',
        ]);

        $compte = CompteTresorerie::query()->findOrFail((int) $this->compteId);
        $montant = (int) Montant::depuisSaisie($this->montant);
        $date = Carbon::parse($this->dateOperation);
        /** @var User $auteur */
        $auteur = auth()->user();

        try {
            match (true) {
                // Apport d'un investisseur : passe par le registre des apports (contrat art. 5 et 9), donc
                // compte dédié à une campagne, et il compte dans la quote-part de l'investisseur (/apports).
                $this->formulaire === 'entree' && $this->nature === NatureMouvement::ApportCampagne->value => Apports::enregistrer(
                    GestionApports::apporteur($this->investisseurId)[0], (int) $this->campagneId,
                    $compte->id, $montant, $date, $auteur, trim($this->libelle.($this->reference !== '' ? ' — réf. '.$this->reference : '')),
                    GestionApports::apporteur($this->investisseurId)[1],
                ),
                $this->formulaire === 'entree' => Tresorerie::entree($compte, $montant, NatureMouvement::from($this->nature), $date, $this->libelle, $auteur, $this->reference ?: null),
                $this->formulaire === 'virement' => Tresorerie::virement($compte, CompteTresorerie::query()->findOrFail((int) $this->compteDestinationId), $montant, $date, $this->libelle, $auteur, reference: $this->reference ?: null),
                $this->formulaire === 'avance' => Tresorerie::avanceAgent($compte, CompteTresorerie::query()->findOrFail((int) $this->compteDestinationId), $montant, $date, $this->libelle, $auteur),
                default => abort(404),
            };
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['montant' => $e->getMessage()]);
        }

        $this->statut = 'Opération enregistrée.';
        $this->formulaire = null;
    }

    /**
     * Natures proposées pour une entrée d'argent : les entrées simples, plus l'apport d'un
     * investisseur (demande du 2026-10-06), enregistré comme sur l'écran Apports.
     *
     * @return array<string, string> valeur => libellé
     */
    private static function naturesEntree(): array
    {
        $natures = [];
        foreach (NatureMouvement::entreesManuelles() as $n) {
            $natures[$n->value] = $n->libelle();
        }
        $natures[NatureMouvement::ApportCampagne->value] = 'Apport d\'un investisseur (campagne)';

        return $natures;
    }

    public function render(): View
    {
        $comptes = CompteTresorerie::query()->with('titulaire', 'campagne.produit')->orderByDesc('actif')->orderBy('nom')->get();
        $soldes = $comptes->mapWithKeys(fn (CompteTresorerie $c) => [$c->id => $c->solde()]);

        return view('livewire.tresorerie.comptes', [
            'comptes' => $comptes,
            'soldes' => $soldes,
            'types' => TypeCompte::cases(),
            'natures' => self::naturesEntree(),
            'investisseurs' => User::query()->where('role', Role::Investisseur->value)->where('actif', true)->orderBy('nom')->get(),
            'nomsSansCompte' => Apport::query()->whereNotNull('apporteur_nom')->distinct()->orderBy('apporteur_nom')->pluck('apporteur_nom'),
            'agents' => User::query()->where('role', Role::Agent->value)->where('actif', true)->orderBy('nom')->get(),
            'campagnes' => Campagne::query()->with('produit')->orderByDesc('debut')->get(),
        ]);
    }
}
