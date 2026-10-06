<?php

namespace App\Livewire\Ventes;

use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutVente;
use App\Enums\TypeAcheteur;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\Lot;
use App\Models\User;
use App\Models\Vente;
use App\Services\Ventes;
use App\Support\Mesure;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Revente d'un lot au bureau. Aperçu en direct du montant ; les règles (stock
 * disponible, seuil de validation) sont dans App\Services\Ventes.
 */
#[Title('Nouvelle vente')]
class FormulaireVente extends Component
{
    public string $campagneId = '';

    public string $lotId = '';

    public string $typeAcheteur = 'exportateur';

    public string $acheteurNom = '';

    public string $dateVente = '';

    public string $poidsKg = '';

    public string $prixKg = '';

    public string $qualiteAcceptee = '';

    /** « Modifier » une vente = la remplacer : l'ancienne passe « annulée », la trace reste. */
    #[Url(as: 'corrige', except: '')]
    public string $corrigeId = '';

    public function mount(): void
    {
        $this->authorize('saisir-ventes');

        $campagne = Campagne::query()->where('statut', StatutCampagne::Ouverte)->orderByDesc('debut')->first();
        $this->campagneId = (string) ($campagne->id ?? '');
        $this->dateVente = now()->format('Y-m-d\TH:i');

        if ($this->corrigeId !== '') {
            $vente = $this->venteCorrigee();
            $this->campagneId = (string) $vente->campagne_id;
            $this->lotId = (string) $vente->lot_id;
            $this->typeAcheteur = $vente->type_acheteur->value;
            $this->acheteurNom = $vente->acheteur_nom;
            $this->dateVente = $vente->date_vente->format('Y-m-d\TH:i');
            $this->poidsKg = Mesure::versSaisie($vente->poids_net_g, 3);
            $this->prixKg = (string) $vente->prix_kg_fcfa;
            $this->qualiteAcceptee = (string) $vente->qualite_acceptee;
        }
    }

    /** La vente à remplacer : à son auteur ou à la direction, pas déjà refusée ni annulée. */
    private function venteCorrigee(): Vente
    {
        $vente = Vente::query()->findOrFail($this->corrigeId);
        $this->authorize('annuler-operation', $vente);
        abort_unless(in_array($vente->statut, [StatutVente::AValider, StatutVente::Valide], true), 403, 'Cette vente ne se modifie plus.');

        return $vente;
    }

    public function updatedCampagneId(): void
    {
        $this->lotId = (string) ($this->lotsVendables()->first()->id ?? '');
    }

    /** @return Collection<int, Lot> */
    private function lotsVendables(): Collection
    {
        if ($this->campagneId === '') {
            return collect();
        }

        // En modification, le lot de la vente reste proposé : ses kilos y reviennent à l'annulation.
        $lotCorrige = $this->corrigeId === '' ? null : Vente::query()->whereKey($this->corrigeId)->value('lot_id');

        return Lot::query()->with('magasin')->where('campagne_id', (int) $this->campagneId)
            ->where(fn ($q) => $q->whereIn('statut', [StatutLot::Ouvert, StatutLot::Ferme])->when($lotCorrige, fn ($q, $id) => $q->orWhere('id', $id)))
            ->orderBy('code')->get()
            ->filter(fn (Lot $l) => $l->stock() > 0 || $l->id === $lotCorrige)->values();
    }

    public function enregistrer(): void
    {
        $this->authorize('saisir-ventes');
        $this->resetErrorBag();

        $this->validate([
            'campagneId' => ['required', 'integer', Rule::exists('campagnes', 'id')],
            'lotId' => ['required', 'integer', Rule::exists('lots', 'id')],
            'typeAcheteur' => ['required', Rule::enum(TypeAcheteur::class)],
            'acheteurNom' => ['required', 'string', 'max:255'],
            'dateVente' => ['required', 'date'],
            'poidsKg' => ['required', Mesure::regle(3, '500,000')],
            'prixKg' => ['required', Montant::regle()],
            'qualiteAcceptee' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'campagneId' => 'campagne', 'lotId' => 'lot', 'acheteurNom' => 'nom de l\'acheteur',
            'dateVente' => 'date', 'poidsKg' => 'poids vendu', 'prixKg' => 'prix au kilo',
        ]);

        $donnees = [
            'campagne_id' => (int) $this->campagneId,
            'lot_id' => (int) $this->lotId,
            'type_acheteur' => TypeAcheteur::from($this->typeAcheteur),
            'acheteur_nom' => $this->acheteurNom,
            'date_vente' => Carbon::parse($this->dateVente),
            'poids_net_g' => (int) Mesure::depuisSaisie($this->poidsKg, 3),
            'prix_kg_fcfa' => (int) Montant::depuisSaisie($this->prixKg),
            'qualite_acceptee' => $this->qualiteAcceptee ?: null,
        ];

        try {
            $vente = DB::transaction(function () use ($donnees) {
                if ($this->corrigeId === '') {
                    return Ventes::enregistrer($donnees, $this->moi());
                }
                // Annuler d'abord : les kilos de l'ancienne vente reviennent dans le lot avant la nouvelle sortie.
                $ancienne = Ventes::annuler($this->venteCorrigee(), $this->moi(), 'Modifiée : remplacée par une nouvelle saisie');
                $nouvelle = Ventes::enregistrer($donnees + ['facture' => $ancienne->facture], $this->moi());
                $ancienne->update(['motif_annulation' => "Modifiée : remplacée par la vente {$nouvelle->reference}"]);

                return $nouvelle;
            });
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['vente' => $e->getMessage()]);
        }

        session()->flash('statut', $this->corrigeId !== ''
            ? "Vente modifiée : elle est remplacée par {$vente->reference}, l'ancienne reste visible, marquée annulée."
            : ($vente->statut === StatutVente::Valide
            ? "Vente {$vente->reference} enregistrée : le stock du lot est sorti."
            : "Vente {$vente->reference} enregistrée : elle attend la validation d'une autre personne."));
        $this->redirectRoute('ventes');
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Aperçu en entiers ; le service refait tout lors de l'enregistrement.
     *
     * @return array{poids: ?int, montant: ?int, stockDisponible: ?int}
     */
    private function apercu(): array
    {
        $poids = Mesure::depuisSaisie($this->poidsKg, 3);
        $prix = Montant::depuisSaisie($this->prixKg);
        $lot = $this->lotId === '' ? null : $this->lotsVendables()->firstWhere('id', (int) $this->lotId);

        return [
            'poids' => $poids,
            'montant' => ($poids !== null && $prix !== null) ? intdiv($poids * $prix + 500, 1000) : null,
            'stockDisponible' => $lot?->stock(),
        ];
    }

    public function render(): View
    {
        $campagne = $this->campagneId === '' ? null : Campagne::query()->with('produit')->find((int) $this->campagneId);

        return view('livewire.ventes.formulaire-vente', [
            'campagne' => $campagne,
            'lots' => $this->lotsVendables(),
            'typesAcheteur' => TypeAcheteur::cases(),
            'apercu' => $this->apercu(),
            'venteCorrigee' => $this->corrigeId === '' ? null : Vente::query()->find($this->corrigeId),
        ]);
    }
}
