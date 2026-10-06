<?php

namespace App\Livewire\Achats;

use App\Enums\StatutAchat;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutPret;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Models\Achat;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\Parametre;
use App\Models\Pisteur;
use App\Models\PointCollecte;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Achats;
use App\Services\Depenses;
use App\Services\Remboursements;
use App\Support\Format;
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
 * Achat bord-champ au bureau (sur le terrain : appli hors ligne, semaine 8). Aperçu en
 * direct du poids net, du montant, de la part retenue sur le prêt et du paiement ; les
 * règles sont dans App\Services\Achats.
 */
#[Title('Nouvel achat')]
class FormulaireAchat extends Component
{
    public string $campagneId = '';

    public string $lotId = '';

    public string $fournisseurType = 'producteur';

    #[Url(as: 'producteur', except: '')]
    public string $producteurId = '';

    public string $pisteurId = '';

    public string $fournisseurNom = '';

    public string $pointCollecteId = '';

    public string $dateAchat = '';

    public string $poidsBrutKg = '';

    public string $tareKg = '0';

    public string $humidite = '';

    public string $kor = '';

    public string $grainage = '';

    public string $prixKg = '';

    public string $compteId = '';

    public string $pretId = '';

    public string $kilosRetenus = '';

    /** « Modifier » un achat = le remplacer : l'ancien passe « annulé », ses effets sont contre-passés. */
    #[Url(as: 'corrige', except: '')]
    public string $corrigeId = '';

    public function mount(): void
    {
        $this->authorize('saisir-achats');

        $campagne = Campagne::query()->where('statut', StatutCampagne::Ouverte)->orderByDesc('debut')->first();
        $this->campagneId = (string) ($campagne->id ?? '');
        $this->prixKg = (string) ($campagne->prix_officiel_kg_fcfa ?? '');
        $this->dateAchat = now()->format('Y-m-d\TH:i');
        /** @var User $moi */
        $moi = auth()->user();
        $this->compteId = (string) (CompteTresorerie::query()->where('titulaire_id', $moi->id)->where('actif', true)->value('id') ?? '');
        $this->choisirPretParDefaut();

        if ($this->corrigeId !== '') {
            $achat = $this->achatCorrige();
            $this->campagneId = (string) $achat->campagne_id;
            $this->lotId = (string) $achat->lot_id;
            $this->fournisseurType = $achat->fournisseur_type->value;
            $this->producteurId = (string) $achat->producteur_id;
            $this->pisteurId = $achat->pisteur_id === null ? '' : (string) $achat->pisteur_id;
            $this->fournisseurNom = (string) $achat->fournisseur_nom;
            $this->pointCollecteId = $achat->point_collecte_id === null ? '' : (string) $achat->point_collecte_id;
            $this->dateAchat = $achat->date_achat->format('Y-m-d\TH:i');
            $this->poidsBrutKg = Mesure::versSaisie($achat->poids_brut_g, 3);
            $this->tareKg = Mesure::versSaisie($achat->tare_g, 3);
            $this->humidite = Mesure::versSaisie($achat->humidite_pour_mille, 1);
            $this->kor = Mesure::versSaisie($achat->kor_centieme_lbs, 2);
            $this->grainage = $achat->grainage_noix_kg === null ? '' : (string) $achat->grainage_noix_kg;
            $this->prixKg = (string) $achat->prix_kg_fcfa;
            $this->compteId = (string) $achat->compte_id;
            $this->pretId = (string) $achat->pret_id;
            $this->kilosRetenus = $achat->pret_id === null ? '' : Mesure::versSaisie($achat->grammes_rembourses, 3);
        }
    }

    /** L'achat à remplacer : à son auteur ou à la direction, à valider ou validé. */
    private function achatCorrige(): Achat
    {
        $achat = Achat::query()->findOrFail($this->corrigeId);
        $this->authorize('annuler-operation', $achat);
        abort_unless(in_array($achat->statut, [StatutAchat::AValider, StatutAchat::Valide], true), 403, 'Cet achat ne se modifie plus.');

        return $achat;
    }

    public function updatedProducteurId(): void
    {
        $this->choisirPretParDefaut();
    }

    public function updatedPretId(): void
    {
        $this->kilosRetenus = '';
    }

    private function choisirPretParDefaut(): void
    {
        $this->pretId = (string) ($this->pretsOuverts()->first()->id ?? '');
        $this->kilosRetenus = '';
    }

    /** @return Collection<int, Pret> */
    private function pretsOuverts(): Collection
    {
        if ($this->fournisseurType !== 'producteur' || $this->producteurId === '') {
            return collect();
        }

        // En modification, le prêt que remboursait l'achat reste proposé : sa part lui sera rendue.
        $pretCorrige = $this->corrigeId === '' ? null : Achat::query()->whereKey($this->corrigeId)->value('pret_id');

        return Pret::query()->where('producteur_id', $this->producteurId)
            ->whereIn('statut', [StatutPret::Valide, StatutPret::Decaisse, StatutPret::Solde])->orderBy('created_at')->get()
            ->filter(fn (Pret $p) => ($p->statut !== StatutPret::Solde && $p->restantDu() > 0) || $p->id === $pretCorrige)->values();
    }

    public function enregistrer(): void
    {
        $this->authorize('saisir-achats');
        $this->resetErrorBag();

        $this->validate([
            'campagneId' => ['required', 'integer', Rule::exists('campagnes', 'id')],
            'lotId' => ['required', 'integer', Rule::exists('lots', 'id')],
            'fournisseurType' => ['required', Rule::enum(TypeFournisseur::class)],
            'producteurId' => ['required_if:fournisseurType,producteur', 'nullable', 'uuid'],
            'pisteurId' => ['required_if:fournisseurType,pisteur', 'nullable', 'integer'],
            'fournisseurNom' => ['required_if:fournisseurType,cooperative', 'nullable', 'string', 'max:255'],
            'dateAchat' => ['required', 'date'],
            'poidsBrutKg' => ['required', Mesure::regle(3, '505,250')],
            'tareKg' => ['required', Mesure::regle(3, '5')],
            'humidite' => ['nullable', Mesure::regle(1, '8,5')],
            'kor' => ['nullable', Mesure::regle(2, '48,50')],
            'grainage' => ['nullable', 'integer', 'min:1'],
            'prixKg' => ['required', Montant::regle()],
            'compteId' => ['required', 'integer', Rule::exists('comptes_tresorerie', 'id')],
            'pretId' => ['nullable', 'uuid'],
            'kilosRetenus' => ['nullable', Mesure::regle(3, '400')],
        ], [
            'producteurId.required_if' => 'Choisir le producteur.',
            'pisteurId.required_if' => 'Choisir le pisteur.',
            'fournisseurNom.required_if' => 'Indiquer le nom de la coopérative.',
        ], [
            'campagneId' => 'campagne', 'lotId' => 'lot', 'dateAchat' => 'date', 'poidsBrutKg' => 'poids brut',
            'tareKg' => 'tare', 'prixKg' => 'prix au kilo', 'compteId' => 'caisse', 'kilosRetenus' => 'kilos retenus',
        ]);

        $apercu = $this->apercu();

        $donnees = [
            'campagne_id' => (int) $this->campagneId,
            'lot_id' => (int) $this->lotId,
            'fournisseur_type' => TypeFournisseur::from($this->fournisseurType),
            'producteur_id' => $this->producteurId ?: null,
            'pisteur_id' => $this->pisteurId === '' ? null : (int) $this->pisteurId,
            'fournisseur_nom' => $this->fournisseurNom ?: null,
            'point_collecte_id' => $this->pointCollecteId === '' ? null : (int) $this->pointCollecteId,
            'date_achat' => Carbon::parse($this->dateAchat),
            'poids_brut_g' => (int) Mesure::depuisSaisie($this->poidsBrutKg, 3),
            'tare_g' => (int) Mesure::depuisSaisie($this->tareKg, 3),
            'humidite_pour_mille' => Mesure::depuisSaisie($this->humidite, 1),
            'kor_centieme_lbs' => Mesure::depuisSaisie($this->kor, 2),
            'grainage_noix_kg' => $this->grainage === '' ? null : (int) $this->grainage,
            'prix_kg_fcfa' => (int) Montant::depuisSaisie($this->prixKg),
            'pret_id' => $this->fournisseurType === 'producteur' && $this->pretId !== '' ? $this->pretId : null,
            'grammes_rembourses' => $apercu['grammesRetenus'] ?? 0,
            'compte_id' => (int) $this->compteId,
        ];

        try {
            $achat = DB::transaction(function () use ($donnees) {
                if ($this->corrigeId === '') {
                    return Achats::enregistrer($donnees, $this->moi());
                }
                // Annuler d'abord : stock, caisse et prêt reviennent comme avant la nouvelle saisie.
                $ancien = Achats::annuler($this->achatCorrige(), $this->moi(), 'Modifié : remplacé par une nouvelle saisie');
                $nouveau = Achats::enregistrer($donnees + ['photo_pesee' => $ancien->photo_pesee], $this->moi());
                $ancien->update(['motif_annulation' => "Modifié : remplacé par l'achat {$nouveau->reference}"]);

                return $nouveau;
            });
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['achat' => $e->getMessage()]);
        }

        session()->flash('statut', $this->corrigeId !== ''
            ? "Achat modifié : il est remplacé par {$achat->reference}, l'ancien reste visible, marqué annulé."
            : ($achat->statut === StatutAchat::Valide
            ? "Achat {$achat->reference} enregistré : stock, paiement et prêt à jour."
            : "Achat {$achat->reference} enregistré : il attend la validation d'une autre personne."));
        $this->redirectRoute('achats');
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Calculs d'aperçu, en entiers ; le service refait tout lors de l'enregistrement.
     *
     * @return array{net: ?int, montant: ?int, grammesRetenus: ?int, valeurRetenue: ?int, especes: ?int, pret: ?Pret, prixNature: ?int, erreurRegle: ?string}
     */
    private function apercu(): array
    {
        $brut = Mesure::depuisSaisie($this->poidsBrutKg, 3);
        $tare = Mesure::depuisSaisie($this->tareKg, 3) ?? 0;
        $prix = Montant::depuisSaisie($this->prixKg);
        $net = $brut === null ? null : $brut - $tare;
        $vide = ['net' => $net, 'montant' => null, 'grammesRetenus' => null, 'valeurRetenue' => null, 'especes' => null, 'pret' => null, 'prixNature' => null, 'erreurRegle' => null];

        if ($net === null || $net <= 0 || $prix === null) {
            return $vide;
        }

        $montant = intdiv($net * $prix + 500, 1000);
        $pret = $this->pretId === '' ? null : $this->pretsOuverts()->firstWhere('id', $this->pretId);
        if ($pret === null) {
            return ['net' => $net, 'montant' => $montant, 'especes' => $montant] + $vide;
        }

        try {
            $prixNature = Remboursements::prixNature($pret, $prix);
        } catch (OperationRefusee $e) {
            return ['net' => $net, 'montant' => $montant, 'especes' => $montant, 'pret' => $pret, 'erreurRegle' => $e->getMessage()] + $vide;
        }

        // Par défaut : les kilos qu'il faut pour solder le restant dû, dans la limite du net.
        $besoin = intdiv($pret->restantDu() * 1000 + $prixNature - 1, $prixNature);
        $grammes = Mesure::depuisSaisie($this->kilosRetenus, 3) ?? min($net, $besoin);
        $grammes = max(0, min($grammes, $net));
        $valeur = min(intdiv($grammes * $prixNature + 500, 1000), $pret->restantDu());

        return [
            'net' => $net,
            'montant' => $montant,
            'grammesRetenus' => $grammes,
            'valeurRetenue' => $valeur,
            'especes' => intdiv(($net - $grammes) * $prix + 500, 1000),
            'pret' => $pret,
            'prixNature' => $prixNature,
            'erreurRegle' => null,
        ];
    }

    public function render(): View
    {
        $moi = $this->moi();
        $campagne = $this->campagneId === '' ? null : Campagne::query()->with('produit')->find((int) $this->campagneId);

        return view('livewire.achats.formulaire-achat', [
            'campagne' => $campagne,
            'lots' => Lot::query()->with('magasin')->where('campagne_id', (int) $this->campagneId)
                ->where(fn ($q) => $q->where('statut', StatutLot::Ouvert)->when($this->corrigeId !== '', fn ($q) => $q->orWhere('id', (int) $this->lotId)))
                ->orderBy('code')->get(),
            'achatCorrige' => $this->corrigeId === '' ? null : Achat::query()->find($this->corrigeId),
            'producteurs' => Producteur::query()->with('village')->where('actif', true)->orderBy('nom')->orderBy('prenoms')->get(),
            'pisteurs' => Pisteur::query()->where('actif', true)->orderBy('nom')->get(),
            'pointsCollecte' => PointCollecte::query()->where('actif', true)->orderBy('nom')->get(),
            'comptes' => CompteTresorerie::query()->orderBy('nom')->get()->filter(fn (CompteTresorerie $c) => Depenses::peutPayerDepuis($moi, $c)),
            'prets' => $this->pretsOuverts(),
            'apercu' => $this->apercu(),
            'regleChoisie' => Parametre::regleRemboursementNature(),
            'typesFournisseur' => TypeFournisseur::cases(),
            'format' => Format::class,
        ]);
    }
}
