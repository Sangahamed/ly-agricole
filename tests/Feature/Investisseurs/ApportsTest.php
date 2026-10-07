<?php

namespace Tests\Feature\Investisseurs;

use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Exceptions\OperationRefusee;
use App\Exceptions\RegistreImmuableException;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\User;
use App\Services\Apports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApportsTest extends TestCase
{
    use RefreshDatabase;

    private User $direction;

    private User $comptable;

    private User $investisseurA;

    private User $investisseurB;

    private Campagne $campagne;

    private CompteTresorerie $compteDedie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->investisseurA = User::factory()->role(Role::Investisseur)->create(['nom' => 'Fonds A']);
        $this->investisseurB = User::factory()->role(Role::Investisseur)->create(['nom' => 'Fonds B']);
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Preparation)->create();
        $this->compteDedie = CompteTresorerie::factory()->create(['campagne_id' => $this->campagne->id, 'nom' => 'Campagne '.$this->campagne->code]);
    }

    private function refusAttendu(callable $tentative, string $message): void
    {
        try {
            $tentative();
            $this->fail("Aucun refus, « $message » attendu.");
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    #[Test]
    public function un_apport_va_sur_le_compte_dedie_de_la_campagne(): void
    {
        $apport = Apports::enregistrer(
            $this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 3_000_000, Carbon::today(), $this->direction,
        );

        $this->assertSame(3_000_000, $apport->montant_fcfa);
        $this->assertSame(3_000_000, $this->compteDedie->solde());
    }

    #[Test]
    public function un_apport_va_sur_tout_compte_actif_mais_pas_un_compte_desactive(): void
    {
        // Depuis le 2026-10-07 (choix du développeur) : l'art. 5 n'est plus imposé par le logiciel.
        $compteLibre = CompteTresorerie::factory()->create();
        $apport = Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $compteLibre->id, 1_000_000, Carbon::today(), $this->direction);
        $this->assertSame(1_000_000, $compteLibre->solde());
        $this->assertSame($this->campagne->id, $apport->campagne_id);

        $compteFerme = CompteTresorerie::factory()->create(['actif' => false]);
        $this->refusAttendu(
            fn () => Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $compteFerme->id, 1_000_000, Carbon::today(), $this->direction),
            'désactivé',
        );
    }

    #[Test]
    public function un_investisseur_sans_compte_compte_par_son_nom_dans_les_parts(): void
    {
        Apports::enregistrer(null, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $this->direction, null, 'Koné  Ibrahim');
        Apports::enregistrer(null, $this->campagne->id, $this->compteDedie->id, 500_000, Carbon::today(), $this->direction, null, 'koné ibrahim');
        Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 1_500_000, Carbon::today(), $this->direction);
        Apports::enregistrer(null, $this->campagne->id, $this->compteDedie->id, 700_000, Carbon::today(), $this->direction);

        $r = Apports::repartition($this->campagne);

        $this->assertSame(700_000, $r['parLy']);
        $this->assertSame(3_000_000, $r['parInvestisseurs']);
        $nom = $r['lignes']->firstWhere('investisseur', null);
        $this->assertSame('Koné Ibrahim', $nom['nom']);
        $this->assertSame(1_500_000, $nom['montant']);
        $this->assertSame(500, $nom['part_pour_mille']);
        $this->refusAttendu(
            fn () => Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 1, Carbon::today(), $this->direction, null, 'Autre'),
            'pas les deux',
        );
    }

    #[Test]
    public function un_apport_de_ly_n_a_pas_d_investisseur(): void
    {
        $apport = Apports::enregistrer(null, $this->campagne->id, $this->compteDedie->id, 500_000, Carbon::today(), $this->comptable, 'Apport propre LY');

        $this->assertTrue($apport->estDeLy());
        $this->assertNull($apport->investisseur_id);
    }

    #[Test]
    public function un_utilisateur_sans_le_role_investisseur_est_refuse(): void
    {
        $this->refusAttendu(
            fn () => Apports::enregistrer($this->comptable->id, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $this->direction),
            'n\'a pas le rôle investisseur',
        );
    }

    #[Test]
    public function un_agent_ne_peut_pas_enregistrer_d_apport(): void
    {
        $agent = User::factory()->role(Role::Agent)->create();

        $this->refusAttendu(
            fn () => Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $agent),
            'enregistrer un apport',
        );
    }

    #[Test]
    public function une_contre_passation_retire_l_argent_et_compense_l_apport(): void
    {
        $apport = Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 2_000_000, Carbon::today(), $this->direction);

        Apports::contrePasser($apport, 'Virement jamais reçu', $this->comptable);

        $this->assertSame(0, $this->compteDedie->solde());
    }

    #[Test]
    public function un_apport_ne_se_modifie_pas(): void
    {
        $apport = Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $this->direction);

        $this->expectException(RegistreImmuableException::class);
        $apport->update(['montant_fcfa' => 1]);
    }

    #[Test]
    public function la_repartition_donne_la_part_de_chaque_investisseur_sans_calculer_de_resultat(): void
    {
        Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 6_000_000, Carbon::today(), $this->direction);
        Apports::enregistrer($this->investisseurB->id, $this->campagne->id, $this->compteDedie->id, 4_000_000, Carbon::today(), $this->direction);
        Apports::enregistrer(null, $this->campagne->id, $this->compteDedie->id, 5_000_000, Carbon::today(), $this->direction, 'Apport propre');

        $repartition = Apports::repartition($this->campagne);

        $this->assertSame(5_000_000, $repartition['parLy']);
        $this->assertSame(10_000_000, $repartition['parInvestisseurs']);
        $this->assertSame('Fonds A', $repartition['lignes'][0]['investisseur']->nom);
        $this->assertSame(6_000_000, $repartition['lignes'][0]['montant']);
        $this->assertSame(600, $repartition['lignes'][0]['part_pour_mille']);
        $this->assertSame(400, $repartition['lignes'][1]['part_pour_mille']);
    }

    #[Test]
    public function une_contre_passation_totale_sort_l_investisseur_de_la_repartition(): void
    {
        $apport = Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $this->direction);
        Apports::contrePasser($apport, 'Erreur de saisie', $this->comptable);

        $repartition = Apports::repartition($this->campagne);

        $this->assertSame(0, $repartition['parInvestisseurs']);
        $this->assertCount(0, $repartition['lignes']);
    }
}
