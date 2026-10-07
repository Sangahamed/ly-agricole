<?php

namespace Tests\Feature\Investisseurs;

use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Livewire\Investisseurs\GestionApports;
use App\Livewire\Investisseurs\PortailInvestisseur;
use App\Models\Apport;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\User;
use App\Services\Apports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EcransApportsTest extends TestCase
{
    use RefreshDatabase;

    private const FINE = "\u{202F}";

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
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create();
        $this->compteDedie = CompteTresorerie::factory()->create(['campagne_id' => $this->campagne->id, 'nom' => 'Campagne '.$this->campagne->code]);
    }

    /** @return array<string, array{Role, int, int}> [rôle, /apports, /mon-investissement] */
    public static function acces(): array
    {
        return [
            'direction' => [Role::Direction, 200, 403],
            'comptable' => [Role::Comptable, 200, 403],
            'investisseur' => [Role::Investisseur, 403, 200],
            'agent' => [Role::Agent, 403, 403],
            'agronome' => [Role::Agronome, 403, 403],
            'admin' => [Role::Admin, 403, 403],
        ];
    }

    #[Test]
    #[DataProvider('acces')]
    public function acces_aux_apports_et_au_portail_investisseur(Role $role, int $apports, int $portail): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->get('/apports')->assertStatus($apports);
        $this->get('/mon-investissement')->assertStatus($portail);
    }

    #[Test]
    public function la_direction_enregistre_un_apport_depuis_l_ecran(): void
    {
        $this->actingAs($this->direction);

        Livewire::test(GestionApports::class, ['campagneId' => (string) $this->campagne->id])
            ->set('campagneId', (string) $this->campagne->id)
            ->call('ouvrir')
            ->set('investisseurId', (string) $this->investisseurA->id)
            ->set('compteId', (string) $this->compteDedie->id)
            ->set('montant', '3 000 000')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSee('Apport enregistré');

        $apport = Apport::firstOrFail();
        $this->assertSame(3_000_000, $apport->montant_fcfa);
        $this->assertSame($this->investisseurA->id, $apport->investisseur_id);
        $this->assertSame(3_000_000, $this->compteDedie->solde());
    }

    #[Test]
    public function un_nom_tape_dans_le_champ_investisseur_enregistre_un_investisseur_sans_compte(): void
    {
        $compteLibre = CompteTresorerie::factory()->create();
        $this->actingAs($this->direction);

        Livewire::test(GestionApports::class, ['campagneId' => (string) $this->campagne->id])
            ->call('ouvrir')
            ->assertSee($compteLibre->nom)
            ->set('investisseurId', 'Coopérative Wassa')
            ->set('compteId', (string) $compteLibre->id)
            ->set('montant', '1 000 000')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSee('Coopérative Wassa');

        $apport = Apport::query()->sole();
        $this->assertNull($apport->investisseur_id);
        $this->assertSame('Coopérative Wassa', $apport->apporteur_nom);
        $this->assertFalse($apport->estDeLy());
    }

    #[Test]
    public function contre_passer_un_apport_depuis_l_ecran(): void
    {
        $this->actingAs($this->direction);
        $apport = Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 2_000_000, Carbon::today(), $this->direction);

        Livewire::test(GestionApports::class, ['campagneId' => (string) $this->campagne->id])
            ->call('preparerContrePassation', $apport->id)
            ->set('motifContrePassation', 'Virement jamais reçu')
            ->call('contrePasser')
            ->assertHasNoErrors()
            ->assertSee('Apport contre-passé');

        $this->assertSame(0, $this->compteDedie->solde());
    }

    #[Test]
    public function l_ecran_de_gestion_affiche_la_repartition_sans_parler_de_resultat(): void
    {
        $this->actingAs($this->direction);
        Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 6_000_000, Carbon::today(), $this->direction);
        Apports::enregistrer($this->investisseurB->id, $this->campagne->id, $this->compteDedie->id, 4_000_000, Carbon::today(), $this->direction);

        Livewire::test(GestionApports::class, ['campagneId' => (string) $this->campagne->id])
            ->assertSee('Fonds A')
            ->assertSee('60,0 %')
            ->assertSee('Fonds B')
            ->assertSee('40,0 %')
            ->assertSee('pas une quote-part du résultat');
    }

    #[Test]
    public function un_investisseur_ne_voit_que_ses_propres_apports_sur_le_portail(): void
    {
        Apports::enregistrer($this->investisseurA->id, $this->campagne->id, $this->compteDedie->id, 6_000_000, Carbon::today(), $this->direction);
        Apports::enregistrer($this->investisseurB->id, $this->campagne->id, $this->compteDedie->id, 4_000_000, Carbon::today(), $this->direction);

        Livewire::actingAs($this->investisseurA)->test(PortailInvestisseur::class)
            ->assertSee('6'.self::FINE.'000'.self::FINE.'000 FCFA')
            ->assertSee('60,0 %')
            ->assertDontSee('4'.self::FINE.'000'.self::FINE.'000 FCFA');
    }

    #[Test]
    public function un_investisseur_sans_apport_voit_un_message_clair(): void
    {
        Livewire::actingAs($this->investisseurA)->test(PortailInvestisseur::class)
            ->assertSee('Aucun apport enregistré à votre nom');
    }
}
