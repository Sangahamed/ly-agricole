<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\CleParametre;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutDepense;
use App\Exceptions\OperationRefusee;
use App\Livewire\Depenses\ListeDepenses;
use App\Models\CategorieDepense;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Parametre;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Depenses;
use App\Services\SuppressionFiches;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** « Supprimer » côté direction : une dépense s'annule (contre-passation), une fiche inutilisée s'efface. */
class SuppressionParLaDirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $comptable;

    private User $direction;

    private CompteTresorerie $caisse;

    private CategorieDepense $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->caisse = CompteTresorerie::factory()->create(['nom' => 'Caisse centrale']);
        $this->transport = CategorieDepense::query()->create(['nom' => 'Transport']);
        Tresorerie::entree($this->caisse, 5_000_000, NatureMouvement::Apport, Carbon::today(), 'Apport', $this->direction);
    }

    private function depense(int $montant): Depense
    {
        return Depenses::saisir([
            'categorie_id' => $this->transport->id, 'compte_id' => $this->caisse->id, 'montant_fcfa' => $montant,
            'date_depense' => Carbon::today(), 'beneficiaire' => 'Transporteur Kouamé',
        ], 'depenses/justificatifs/recu.jpg', $this->comptable);
    }

    #[Test]
    public function supprimer_une_depense_payee_rend_l_argent_a_la_caisse(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationDepense], ['valeur' => '1000000']);
        $depense = $this->depense(150_000);
        $this->assertSame(StatutDepense::Payee, $depense->statut);
        $this->assertSame(4_850_000, $this->caisse->solde());

        Depenses::annuler($depense, $this->direction, 'Facture saisie deux fois');

        $this->assertSame(StatutDepense::Annulee, $depense->refresh()->statut);
        $this->assertSame(5_000_000, $this->caisse->refresh()->solde());
        $this->assertStringContainsString('Facture saisie deux fois', (string) $depense->motif_refus);
    }

    #[Test]
    public function supprimer_une_depense_a_valider_par_son_auteur_ou_la_direction_seulement(): void
    {
        $depense = $this->depense(150_000);
        $this->assertSame(StatutDepense::AValider, $depense->statut);

        // Un autre comptable n'en est pas l'auteur : refusé (l'auteur, lui, peut — 2026-10-06).
        try {
            Depenses::annuler($depense, User::factory()->role(Role::Comptable)->create(), 'Je retire sa saisie');
            $this->fail('Un autre comptable a pu supprimer');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('auteur de la dépense et la direction', $e->getMessage());
        }

        Livewire::actingAs($this->direction)->test(ListeDepenses::class)
            ->call('preparerAnnulation', $depense->id)
            ->set('motifAnnulation', 'Mauvaise catégorie')
            ->call('annulerDepense')
            ->assertHasNoErrors()
            ->assertSee('Dépense supprimée');

        $this->assertSame(StatutDepense::Annulee, $depense->refresh()->statut);
        $this->assertSame(5_000_000, $this->caisse->refresh()->solde());
    }

    #[Test]
    public function une_fiche_producteur_inutilisee_s_efface_mais_pas_une_fiche_qui_a_servi(): void
    {
        $neuf = Producteur::factory()->create();
        SuppressionFiches::producteur($neuf, $this->direction);
        $this->assertNull(Producteur::query()->find($neuf->id));
        $this->assertTrue(DB::table('journal_activite')->where('objet_type', 'producteur')->where('objet_id', $neuf->id)->where('action', 'suppression')->exists());

        $avecHistorique = Producteur::factory()->create();
        DB::table('decisions_plafond')->insert([
            'producteur_id' => $avecHistorique->id, 'raison_proposition' => 'Essai', 'plafond_retenu_fcfa' => 100_000,
            'donnees' => '{}', 'calcule_at' => now(), 'cree_par' => $this->direction->id,
        ]);
        try {
            SuppressionFiches::producteur($avecHistorique, $this->direction);
            $this->fail('Une fiche qui a servi a été effacée');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Désactivez-la', $e->getMessage());
        }
        $this->assertNotNull(Producteur::query()->find($avecHistorique->id));
    }

    #[Test]
    public function le_bouton_de_la_fiche_supprime_pour_la_direction_seulement(): void
    {
        $p = Producteur::factory()->create();

        $this->actingAs($this->comptable)->delete(route('producteurs.supprimer', $p))->assertForbidden();
        $this->actingAs($this->direction)->get(route('producteurs.fiche', $p))->assertOk()->assertSee('Oui, supprimer la fiche');
        $this->actingAs($this->direction)->delete(route('producteurs.supprimer', $p))->assertRedirect(route('producteurs'));
        $this->assertNull(Producteur::query()->find($p->id));
    }
}
