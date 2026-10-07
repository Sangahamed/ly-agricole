<?php

namespace App\Livewire\Resultat;

use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\User;
use App\Services\Apports;
use App\Services\PartageResultat;
use App\Services\ResultatCampagne;
use App\Services\ValorisationsStock;
use App\Support\Montant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Résultat net PROVISOIRE d'une campagne et son partage entre les investisseurs et LY
 * (contrat art. 10 à 14), pour la direction et la comptabilité. Rien n'est communiqué aux
 * investisseurs : ils ne voient encore aucun résultat. Le résultat n'est DÉFINITIF que si les
 * conditions de clôture sont remplies (question 32) ; la direction saisit ici la valorisation
 * du stock invendu à partir de deux offres écrites (art. 11.3).
 */
#[Title('Résultat de campagne')]
class ResultatDeCampagne extends Component
{
    #[Url(as: 'campagne', except: '')]
    public string $campagneId = '';

    /** Art. 13.4 : décision de la direction, jamais déduite. Ne joue que sur une perte. */
    public bool $fauteLy = false;

    public bool $formulaireStock = false;

    public string $poidsKg = '';

    public string $fournisseur1 = '';

    public string $date1 = '';

    public string $prix1 = '';

    public string $fournisseur2 = '';

    public string $date2 = '';

    public string $prix2 = '';

    public string $valeurRetenue = '';

    public string $motifValorisation = '';

    public string $statut = '';

    public function mount(): void
    {
        $this->authorize('voir-resultat-campagne');
        if ($this->campagneId === '') {
            $this->campagneId = (string) (Campagne::query()->orderByDesc('debut')->value('id') ?? '');
        }
    }

    public function ouvrirValorisation(): void
    {
        $this->authorize('valoriser-stock');
        $this->resetErrorBag();
        $campagne = Campagne::query()->find((int) $this->campagneId);
        if ($campagne === null) {
            return;
        }
        $stock = ResultatCampagne::etat($campagne)['info']['stock_invendu_g'];
        $this->poidsKg = (string) intdiv(max(0, $stock), 1000);
        $this->date1 = $this->date2 = Carbon::today()->toDateString();
        $this->formulaireStock = true;
    }

    public function fermerValorisation(): void
    {
        $this->formulaireStock = false;
        $this->resetErrorBag();
    }

    public function valoriser(): void
    {
        $this->authorize('valoriser-stock');
        $this->resetErrorBag();
        $campagne = Campagne::query()->findOrFail((int) $this->campagneId);

        $this->validate([
            'poidsKg' => ['required', Montant::regle()],
            'fournisseur1' => ['required', 'string', 'max:255'],
            'date1' => ['required', 'date', 'before_or_equal:today'],
            'prix1' => ['required', Montant::regle()],
            'fournisseur2' => ['required', 'string', 'max:255'],
            'date2' => ['required', 'date', 'before_or_equal:today'],
            'prix2' => ['required', Montant::regle()],
            'valeurRetenue' => ['required', 'regex:/^\s*[0-9][0-9\s\x{00A0}\x{202F}]*$/u'],
            'motifValorisation' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'poidsKg' => 'poids', 'fournisseur1' => 'fournisseur de l\'offre 1', 'date1' => 'date de l\'offre 1', 'prix1' => 'prix de l\'offre 1',
            'fournisseur2' => 'fournisseur de l\'offre 2', 'date2' => 'date de l\'offre 2', 'prix2' => 'prix de l\'offre 2',
            'valeurRetenue' => 'valeur retenue',
        ]);

        try {
            ValorisationsStock::enregistrer($campagne, [
                'poids_g' => (int) Montant::depuisSaisie($this->poidsKg) * 1000,
                'offre1_fournisseur' => $this->fournisseur1,
                'offre1_date' => Carbon::parse($this->date1),
                'offre1_prix_kg_fcfa' => (int) Montant::depuisSaisie($this->prix1),
                'offre2_fournisseur' => $this->fournisseur2,
                'offre2_date' => Carbon::parse($this->date2),
                'offre2_prix_kg_fcfa' => (int) Montant::depuisSaisie($this->prix2),
                'valeur_retenue_fcfa' => (int) Montant::depuisSaisie($this->valeurRetenue),
                'motif' => $this->motifValorisation,
            ], $this->moi());
        } catch (OperationRefusee $e) {
            throw ValidationException::withMessages(['valeurRetenue' => $e->getMessage()]);
        }

        $this->reset('poidsKg', 'fournisseur1', 'date1', 'prix1', 'fournisseur2', 'date2', 'prix2', 'valeurRetenue', 'motifValorisation');
        $this->formulaireStock = false;
        $this->statut = 'Valorisation du stock enregistrée : elle remplace la précédente, l\'historique est conservé.';
    }

    private function moi(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        $campagne = Campagne::query()->with('produit')->find((int) $this->campagneId);
        $campagnes = Campagne::query()->with('produit')->orderByDesc('debut')->get();

        $etat = null;
        $partage = null;
        $noms = collect();
        $refus = null;
        $conditions = [];
        $valeursOffres = null;
        if ($campagne !== null) {
            $etat = ResultatCampagne::etat($campagne);
            $repartition = Apports::repartition($campagne);
            $noms = $repartition['lignes']->mapWithKeys(fn (array $l) => [$l['cle'] => $l['nom']]);
            $investis = $repartition['lignes']->mapWithKeys(fn (array $l) => [$l['cle'] => $l['montant']])->all();
            try {
                $partage = PartageResultat::calculer($etat['resultat_net'], $investis, $repartition['parLy'], $this->fauteLy);
            } catch (OperationRefusee $e) {
                $refus = $e->getMessage();
            }
            $conditions = ResultatCampagne::conditionsDeCloture($etat, $partage);
            $valeursOffres = $etat['valorisation'] === null ? null : ValorisationsStock::valeursDesOffres($etat['valorisation']);
        }

        return view('livewire.resultat.resultat-de-campagne', [
            'campagnes' => $campagnes,
            'campagne' => $campagne,
            'etat' => $etat,
            'partage' => $partage,
            'refus' => $refus,
            'noms' => $noms,
            'conditions' => $conditions,
            'definitif' => $conditions !== [] && collect($conditions)->every(fn (array $c) => $c['ok']),
            'valeursOffres' => $valeursOffres,
        ]);
    }
}
