<?php

namespace Tests\Feature\Achats;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\NatureMouvement;
use App\Enums\RegleValorisationNature;
use App\Enums\Role;
use App\Enums\StatutAchat;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutPret;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Livewire\Achats\ListeAchats;
use App\Models\Achat;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\MouvementStock;
use App\Models\Parametre;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Achats;
use App\Services\Prets;
use App\Services\Stock;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * « Supprimer » un achat (direction seule) : rien ne s'efface, ses effets sont contre-passés
 * ensemble — stock, remboursement en kilos, caisse. Les chiffres reviennent comme avant.
 */
class AnnulationAchatTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $comptable;

    private User $direction;

    private Campagne $campagne;

    private Lot $lot;

    private CompteTresorerie $caisseAgent;

    private Producteur $producteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = User::factory()->role(Role::Agent)->create();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create(['prix_officiel_kg_fcfa' => 400]);
        $magasin = Magasin::factory()->create();
        $this->lot = Lot::query()->create([
            'produit_id' => $this->campagne->produit_id, 'campagne_id' => $this->campagne->id,
            'magasin_id' => $magasin->id, 'statut' => StatutLot::Ouvert, 'cree_par' => $this->comptable->id,
        ]);
        $caisseCentrale = CompteTresorerie::factory()->create();
        $this->caisseAgent = CompteTresorerie::factory()->caisseDe($this->agent)->create();
        Tresorerie::entree($caisseCentrale, 10_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds', $this->direction);
        Tresorerie::avanceAgent($caisseCentrale, $this->caisseAgent, 2_000_000, Carbon::today(), 'Avance achats', $this->direction);
        $this->producteur = Producteur::factory()->create();
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationAchat], ['valeur' => '5000000']);
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationPret], ['valeur' => '5000000']);
        Parametre::query()->updateOrCreate(['cle' => CleParametre::RegleRemboursementNature], ['valeur' => RegleValorisationNature::PrixAchat->value]);
    }

    private function pretDecaisse(): Pret
    {
        $pret = Prets::demander([
            'producteur_id' => $this->producteur->id, 'campagne_id' => $this->campagne->id, 'montant_fcfa' => 300_000,
            'forme' => FormePret::Especes, 'echeance' => Carbon::today()->addMonths(5),
        ], $this->agent);
        Prets::valider($pret, $this->direction);
        Prets::decaisser($pret, [
            'compte_id' => CompteTresorerie::query()->whereNull('titulaire_id')->value('id'), 'mode' => ModeDecaissement::Especes, 'montant_fcfa' => 300_000, 'date' => Carbon::today(),
        ], $this->comptable, 'prets/recus/r.jpg');

        return $pret->refresh();
    }

    /** 500 kg à 425 F : 212 500 F, sous le seuil, donc validé tout de suite. */
    private function acheter(array $surcharge = []): Achat
    {
        return Achats::enregistrer(array_merge([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id, 'fournisseur_type' => TypeFournisseur::Producteur,
            'producteur_id' => $this->producteur->id, 'date_achat' => now()->subMinute(), 'poids_brut_g' => 505_000, 'tare_g' => 5_000,
            'prix_kg_fcfa' => 425, 'compte_id' => $this->caisseAgent->id,
        ], $surcharge), $this->agent);
    }

    #[Test]
    public function annuler_un_achat_valide_remet_stock_pret_et_caisse_comme_avant(): void
    {
        $pret = $this->pretDecaisse();
        $caisseAvant = $this->caisseAgent->solde();
        $achat = $this->acheter(['pret_id' => $pret->id, 'grammes_rembourses' => 400_000]);

        $this->assertSame(StatutAchat::Valide, $achat->statut);
        $this->assertSame(500_000, $this->lot->stock());
        $this->assertSame(130_000, $pret->refresh()->restantDu());
        $this->assertSame($caisseAvant - 42_500, $this->caisseAgent->solde());

        $annule = Achats::annuler($achat, $this->direction, 'Pesée saisie deux fois');

        $this->assertSame(StatutAchat::Annule, $annule->statut);
        $this->assertSame('Pesée saisie deux fois', $annule->motif_annulation);
        $this->assertSame($this->direction->id, $annule->annule_par);
        $this->assertSame(0, $this->lot->refresh()->stock());
        $this->assertSame(300_000, $pret->refresh()->restantDu());
        $this->assertSame(StatutPret::Decaisse, $pret->statut);
        $this->assertSame($caisseAvant, $this->caisseAgent->refresh()->solde());
        // Rien n'est effacé : l'entrée et son inverse restent au registre.
        $this->assertSame(2, MouvementStock::query()->where('achat_id', $achat->id)->count());
    }

    #[Test]
    public function annuler_un_achat_a_valider_ne_touche_a_rien(): void
    {
        Parametre::query()->where('cle', CleParametre::SeuilValidationAchat)->update(['valeur' => '1000']);
        $achat = $this->acheter();
        $this->assertSame(StatutAchat::AValider, $achat->statut);

        Achats::annuler($achat, $this->direction, 'Mauvais producteur');

        $this->assertSame(StatutAchat::Annule, $achat->refresh()->statut);
        $this->assertSame(0, MouvementStock::query()->count());
    }

    #[Test]
    public function seuls_l_auteur_et_la_direction_annulent_avec_un_motif_et_une_seule_fois(): void
    {
        $achat = $this->acheter();

        // Le comptable n'est pas l'auteur (l'agent l'est : il peut annuler, voir DroitsDirectionEtAuteurTest).
        try {
            Achats::annuler($achat, $this->comptable, 'Je voudrais annuler');
            $this->fail('Annulation acceptée pour le comptable');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('auteur de l\'achat et la direction', $e->getMessage());
        }

        try {
            Achats::annuler($achat, $this->direction, 'non');
            $this->fail('Annulation sans vrai motif acceptée');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('motif', $e->getMessage());
        }

        Achats::annuler($achat, $this->direction, 'Doublon de saisie');
        $this->expectException(OperationRefusee::class);
        Achats::annuler($achat, $this->direction, 'Doublon de saisie');
    }

    #[Test]
    public function on_ne_peut_pas_annuler_si_les_kilos_ne_sont_plus_dans_le_lot(): void
    {
        $achat = $this->acheter();
        Stock::perte($this->lot, $this->lot->magasin, 200_000, Carbon::today(), 'Sacs abîmés', $this->comptable);

        try {
            Achats::annuler($achat, $this->direction, 'Erreur de saisie');
            $this->fail('Annulation acceptée alors que le lot n\'a plus les kilos');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Stock insuffisant', $e->getMessage());
        }
        // Tout ou rien : l'achat reste validé, rien n'a bougé.
        $this->assertSame(StatutAchat::Valide, $achat->refresh()->statut);
        $this->assertSame(300_000, $this->lot->refresh()->stock());
    }

    #[Test]
    public function le_bouton_supprimer_de_la_liste_annule_l_achat(): void
    {
        $achat = $this->acheter();

        Livewire::actingAs($this->direction)->test(ListeAchats::class)
            ->assertSee('Supprimer')
            ->call('preparerAnnulation', $achat->id)
            ->assertSeeHtml('Supprimer l\'achat '.$achat->reference)
            ->set('motifAnnulation', 'Saisi sur le mauvais lot')
            ->call('annulerAchat')
            ->assertHasNoErrors()
            ->assertSee('supprimé (annulé)');

        $this->assertSame(StatutAchat::Annule, $achat->refresh()->statut);

        Livewire::actingAs($this->comptable)->test(ListeAchats::class)
            ->call('preparerAnnulation', $achat->id)->assertForbidden();
    }
}
