<?php

namespace App\Livewire\Investisseurs;

use App\Models\Apport;
use App\Models\User;
use App\Services\Apports;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ce qu'un investisseur voit de son compte : ses apports, campagne par campagne, et sa
 * part de l'ensemble des apports d'investisseurs sur chacune. PAS un calcul de
 * résultat ni de quote-part (contrat art. 10 à 14, texte non disponible — voir
 * App\Services\Apports) : l'écran le dit explicitement, pour ne rien laisser croire de
 * plus que ce qui est garanti.
 */
#[Title('Mon investissement')]
class PortailInvestisseur extends Component
{
    public function mount(): void
    {
        $this->authorize('voir-portail-investisseur');
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

        $campagnes = Apport::query()->where('investisseur_id', $moi->id)
            ->with('campagne.produit')->get()->pluck('campagne')->unique('id')->sortByDesc('debut');

        $lignes = $campagnes->map(function ($campagne) use ($moi) {
            $repartition = Apports::repartition($campagne);
            $mesApports = Apport::query()->where('investisseur_id', $moi->id)->where('campagne_id', $campagne->id)
                ->orderByDesc('date_apport')->get();
            $maLigne = $repartition['lignes']->firstWhere(fn ($l) => $l['investisseur']?->id === $moi->id);

            return [
                'campagne' => $campagne,
                'monApport' => $maLigne['montant'] ?? 0,
                'partPourMille' => $maLigne['part_pour_mille'] ?? 0,
                'totalInvestisseurs' => $repartition['parInvestisseurs'],
                'mesApports' => $mesApports,
            ];
        });

        return view('livewire.investisseurs.portail-investisseur', ['lignes' => $lignes]);
    }
}
