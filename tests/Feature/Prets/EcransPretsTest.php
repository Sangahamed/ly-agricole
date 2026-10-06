<?php

namespace Tests\Feature\Prets;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutPret;
use App\Livewire\Prets\FichePret;
use App\Livewire\Prets\FormulairePret;
use App\Livewire\Prets\ListePrets;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Parametre;
use App\Models\Parcelle;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Prets;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EcransPretsTest extends TestCase
{
    use RefreshDatabase;

    private const FINE = "\u{202F}";

    private User $agent;

    private User $direction;

    private User $comptable;

    private Campagne $campagne;

    private CompteTresorerie $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->agent = User::factory()->role(Role::Agent)->create(['nom' => 'Koffi Agent']);
        $this->direction = User::factory()->role(Role::Direction)->create(['nom' => 'Mariam Direction']);
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create();
        $this->caisse = CompteTresorerie::factory()->create(['nom' => 'Caisse centrale']);
        Tresorerie::entree($this->caisse, 25_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds', $this->direction);
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationPret, 'valeur' => '5000000']);
    }

    private function pretDemande(int $montant = 3_000_000): Pret
    {
        return Prets::demander([
            'producteur_id' => Producteur::factory()->create()->id,
            'campagne_id' => $this->campagne->id,
            'montant_fcfa' => $montant,
            'forme' => FormePret::Especes,
            'echeance' => Carbon::today()->addMonths(6),
        ], $this->agent);
    }

    /** @return array<string, array{Role, int, int}> [rôle, liste et fiche, nouvelle demande] */
    public static function acces(): array
    {
        return [
            'direction' => [Role::Direction, 200, 200],
            'comptable' => [Role::Comptable, 200, 200],
            'agent' => [Role::Agent, 200, 200],
            'agronome' => [Role::Agronome, 403, 403],
            'admin' => [Role::Admin, 403, 403],
            'investisseur' => [Role::Investisseur, 403, 403],
        ];
    }

    #[Test]
    #[DataProvider('acces')]
    public function acces_aux_ecrans_de_prets(Role $role, int $voir, int $saisir): void
    {
        $pret = $this->pretDemande();
        $this->actingAs(User::factory()->role($role)->create());

        $this->get('/prets')->assertStatus($voir);
        $this->get("/prets/{$pret->id}")->assertStatus($voir);
        $this->get('/prets/nouveau')->assertStatus($saisir);
    }

    #[Test]
    public function un_agent_saisit_une_demande_avec_ses_parcelles_et_les_kilos_attendus(): void
    {
        $this->actingAs($this->agent);
        $producteur = Producteur::factory()->create(['nom' => 'Coulibaly', 'prenoms' => 'Awa']);
        $parcelle = Parcelle::factory()->carre(100)->create(['producteur_id' => $producteur->id, 'nom' => 'Champ du marigot']);

        Livewire::test(FormulairePret::class)
            ->assertSet('campagneId', (string) $this->campagne->id)
            ->set('producteurId', $producteur->id)
            ->assertSee('Champ du marigot')
            ->set('parcelleIds', [$parcelle->id])
            ->set('montant', '3 000 000')
            ->set('forme', 'especes')
            ->set('prixReference', '400')
            ->set('echeance', Carbon::today()->addMonths(5)->toDateString())
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertRedirect();

        $pret = Pret::firstOrFail();
        $this->assertSame(3_000_000, $pret->montant_fcfa);
        $this->assertSame(7_500_000, $pret->grammes_attendus);
        $this->assertSame([$parcelle->id], $pret->parcelles()->pluck('parcelles.id')->all());

        $this->get(route('prets.fiche', $pret))
            ->assertSee('7'.self::FINE.'500 kg')
            ->assertSee('Votre demande : la validation revient à une autre personne.')
            ->assertSee('1,00 ha');
    }

    #[Test]
    public function un_proche_de_la_direction_sans_accord_est_refuse_a_l_ecran(): void
    {
        $this->actingAs($this->agent);
        $producteur = Producteur::factory()->create();

        $ecran = Livewire::test(FormulairePret::class)
            ->set('producteurId', $producteur->id)
            ->set('montant', '500000')
            ->set('forme', 'mixte')
            ->set('echeance', Carbon::today()->addMonth()->toDateString());

        $ecran->set('partieLiee', true)
            ->call('enregistrer')
            ->assertHasErrors(['accordEcrit' => 'required']);

        $ecran->set('accordEcrit', UploadedFile::fake()->create('accord.pdf', 20, 'application/pdf'))
            ->call('enregistrer')
            ->assertHasNoErrors();

        Storage::disk('local')->assertExists((string) Pret::firstOrFail()->accord_ecrit);
    }

    #[Test]
    public function la_direction_valide_puis_la_comptable_verse_avec_le_recu(): void
    {
        $pret = $this->pretDemande();

        $this->actingAs($this->direction);
        Livewire::test(FichePret::class, ['pret' => $pret])
            ->assertSeeHtml('wire:click="valider"')
            ->call('valider')
            ->assertSee('Prêt validé : il peut être décaissé.')
            ->assertSee('Mariam Direction');

        $this->actingAs($this->comptable);
        $ecran = Livewire::test(FichePret::class, ['pret' => $pret])
            ->call('ouvrirDecaissement')
            ->assertSet('montant', '3000000')
            ->set('compteId', (string) $this->caisse->id)
            ->call('decaisser')
            ->assertHasErrors(['recu' => 'required']);

        $ecran->set('recu', UploadedFile::fake()->image('recu-signe.jpg'))
            ->call('decaisser')
            ->assertHasNoErrors()
            ->assertSee('Versement enregistré');

        $this->assertSame(StatutPret::Decaisse, $pret->refresh()->statut);
        $this->assertSame(22_000_000, $this->caisse->solde());
    }

    #[Test]
    public function le_pret_de_la_direction_est_accorde_sans_bouton_valider_et_l_appel_force_est_refuse(): void
    {
        $pret = Prets::demander([
            'producteur_id' => Producteur::factory()->create()->id,
            'campagne_id' => $this->campagne->id,
            'montant_fcfa' => 1_000_000,
            'forme' => FormePret::Especes,
            'echeance' => Carbon::today()->addMonth(),
        ], $this->direction);

        $this->actingAs($this->direction);
        Livewire::test(FichePret::class, ['pret' => $pret])
            ->assertSee('accordé directement par la direction, sans 2e accord')
            ->assertDontSeeHtml('wire:click="valider"')
            ->call('valider')
            ->assertHasErrors('action')
            ->assertSee('votre propre demande');

        $this->assertSame(1, $pret->validations()->count());
    }

    #[Test]
    public function le_portefeuille_totalise_accorde_decaisse_et_kilos(): void
    {
        $this->actingAs($this->comptable);
        for ($i = 0; $i < 3; $i++) {
            $pret = Prets::demander([
                'producteur_id' => Producteur::factory()->create()->id,
                'campagne_id' => $this->campagne->id,
                'montant_fcfa' => 3_000_000,
                'forme' => FormePret::Especes,
                'echeance' => Carbon::today()->addMonths(6),
                'prix_reference_kg_fcfa' => 400,
            ], $this->agent);
            Prets::valider($pret, $this->direction);
        }
        Prets::decaisser(Pret::query()->firstOrFail(), [
            'compte_id' => $this->caisse->id, 'mode' => ModeDecaissement::Especes,
            'montant_fcfa' => 3_000_000, 'date' => Carbon::today(),
        ], $this->comptable, 'prets/recus/r.jpg');
        $this->pretDemande(2_000_000);

        Livewire::test(ListePrets::class)
            ->assertSeeHtml('<dd class="mt-1 text-lg font-semibold tabular-nums" id="total-demandes">1 · 2'.self::FINE.'000'.self::FINE.'000 FCFA</dd>')
            ->assertSeeHtml('id="total-accordes">3 · 9'.self::FINE.'000'.self::FINE.'000 FCFA</dd>')
            ->assertSeeHtml('id="total-decaisse">3'.self::FINE.'000'.self::FINE.'000 FCFA</dd>')
            ->assertSee('reste à remettre 6'.self::FINE.'000'.self::FINE.'000 FCFA')
            ->assertSeeHtml('id="total-kilos">22'.self::FINE.'500 kg</dd>');
    }
}
