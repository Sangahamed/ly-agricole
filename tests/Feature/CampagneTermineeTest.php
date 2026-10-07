<?php

namespace Tests\Feature;

use App\Enums\FormePret;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Achats;
use App\Services\Apports;
use App\Services\Prets;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Constaté en production le 2026-10-07 : une campagne dont la date de fin est passée recevait
 * encore de l'argent. Fin passée = plus d'apport, de prêt ni d'achat, jugé sur la DATE DE
 * L'OPÉRATION (un achat hors ligne fait avant la fin reste accepté quand il arrive après).
 */
class CampagneTermineeTest extends TestCase
{
    use RefreshDatabase;

    private User $direction;

    private Campagne $campagne;

    private CompteTresorerie $compteDedie;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-10-10 09:00:00');
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create([
            'debut' => '2026-12-01', 'fin' => '2027-09-30', 'prix_officiel_kg_fcfa' => 400,
        ]);
        $this->compteDedie = CompteTresorerie::factory()->create(['campagne_id' => $this->campagne->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refusAttendu(callable $tentative): void
    {
        try {
            $tentative();
            $this->fail('Aucun refus, « campagne terminée » attendu.');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('est terminée', $e->getMessage());
        }
    }

    #[Test]
    public function apres_la_date_de_fin_plus_d_apport_ni_de_pret(): void
    {
        $this->assertTrue($this->campagne->estTerminee());
        $investisseur = User::factory()->role(Role::Investisseur)->create();

        $this->refusAttendu(fn () => Apports::enregistrer($investisseur->id, $this->campagne->id, $this->compteDedie->id, 1_000_000, Carbon::today(), $this->direction));
        $this->refusAttendu(fn () => Prets::demander([
            'producteur_id' => Producteur::factory()->create()->id, 'campagne_id' => $this->campagne->id, 'montant_fcfa' => 100_000,
            'forme' => FormePret::Especes, 'echeance' => Carbon::today()->addMonth(),
        ], $this->direction));

        // Un virement reçu le dernier jour, saisi après : accepté (date de l'apport ≤ fin).
        $apport = Apports::enregistrer($investisseur->id, $this->campagne->id, $this->compteDedie->id, 500_000, Carbon::parse('2027-09-30'), $this->direction);
        $this->assertSame(500_000, $apport->montant_fcfa);
    }

    #[Test]
    public function un_achat_fait_avant_la_fin_passe_un_achat_apres_la_fin_non(): void
    {
        $lot = Lot::query()->create([
            'produit_id' => $this->campagne->produit_id, 'campagne_id' => $this->campagne->id,
            'magasin_id' => Magasin::factory()->create()->id, 'statut' => StatutLot::Ouvert, 'cree_par' => $this->direction->id,
        ]);
        $caisse = CompteTresorerie::factory()->create();
        Tresorerie::entree($caisse, 1_000_000, NatureMouvement::Apport, Carbon::parse('2027-09-01'), 'Fonds', $this->direction);
        $achat = fn (string $date) => Achats::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $lot->id, 'fournisseur_type' => TypeFournisseur::Producteur,
            'producteur_id' => Producteur::factory()->create()->id, 'date_achat' => Carbon::parse($date), 'poids_brut_g' => 101_000,
            'tare_g' => 1_000, 'prix_kg_fcfa' => 400, 'compte_id' => $caisse->id,
        ], $this->direction);

        $this->assertSame(100_000, $achat('2027-09-29 15:00')->poids_net_g);
        $this->refusAttendu(fn () => $achat('2027-10-02 10:00'));
    }
}
