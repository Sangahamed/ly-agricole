<?php

namespace Tests\Feature\Ventes;

use App\Enums\CleParametre;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutVente;
use App\Enums\TypeFournisseur;
use App\Exceptions\RegistreImmuableException;
use App\Livewire\Ventes\FicheVente;
use App\Livewire\Ventes\FormulaireVente;
use App\Livewire\Ventes\ListeVentes;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\Parametre;
use App\Models\Producteur;
use App\Models\User;
use App\Models\Vente;
use App\Services\Achats;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EcransVentesTest extends TestCase
{
    use RefreshDatabase;

    private const FINE = "\u{202F}";

    private User $agent;

    private User $comptable;

    private User $direction;

    private Campagne $campagne;

    private Magasin $magasin;

    private Lot $lot;

    private CompteTresorerie $caisseCentrale;

    private CompteTresorerie $caisseAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->role(Role::Agent)->create();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create(['prix_officiel_kg_fcfa' => 400]);
        $this->magasin = Magasin::factory()->create(['nom' => 'Magasin central']);
        $this->lot = Lot::query()->create(['produit_id' => $this->campagne->produit_id, 'campagne_id' => $this->campagne->id,
            'magasin_id' => $this->magasin->id, 'statut' => StatutLot::Ouvert, 'cree_par' => $this->comptable->id]);
        $this->caisseCentrale = CompteTresorerie::factory()->create(['nom' => 'Caisse centrale']);
        $this->caisseAgent = CompteTresorerie::factory()->caisseDe($this->agent)->create();
        Tresorerie::entree($this->caisseCentrale, 5_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds', $this->direction);
        Tresorerie::avanceAgent($this->caisseCentrale, $this->caisseAgent, 1_000_000, Carbon::today(), 'Avance achats', $this->direction);
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationAchat, 'valeur' => '5000000']);

        Achats::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id, 'fournisseur_type' => TypeFournisseur::Producteur,
            'producteur_id' => Producteur::factory()->create()->id, 'date_achat' => now()->subDay(),
            'poids_brut_g' => 505_000, 'tare_g' => 5_000, 'prix_kg_fcfa' => 425, 'compte_id' => $this->caisseAgent->id,
        ], $this->agent);
    }

    /** @return array<string, array{Role, int, int}> [rôle, /ventes, /ventes/nouvelle] */
    public static function acces(): array
    {
        return [
            'direction' => [Role::Direction, 200, 200],
            'comptable' => [Role::Comptable, 200, 200],
            // Depuis le 2026-10-07, l'agent de terrain vend aussi (ses ventes seulement).
            'agent' => [Role::Agent, 200, 200],
            'agronome' => [Role::Agronome, 403, 403],
            'admin' => [Role::Admin, 403, 403],
            'investisseur' => [Role::Investisseur, 403, 403],
        ];
    }

    #[Test]
    #[DataProvider('acces')]
    public function acces_aux_ventes(Role $role, int $liste, int $nouveau): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->get('/ventes')->assertStatus($liste);
        $this->get('/ventes/nouvelle')->assertStatus($nouveau);
    }

    #[Test]
    public function le_comptable_vend_400_kg_depuis_l_ecran_sans_seuil_defini(): void
    {
        // Le comptable : sa vente attend une autre personne (la direction, elle, est validée tout de suite).
        $this->actingAs($this->comptable);

        Livewire::test(FormulaireVente::class)
            ->set('lotId', (string) $this->lot->id)
            ->set('acheteurNom', 'Ivoire Export SA')
            ->set('poidsKg', '400')
            ->set('prixKg', '900')
            ->assertSee('360'.self::FINE.'000 FCFA')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertRedirect(route('ventes'));

        $vente = Vente::firstOrFail();
        $this->assertSame(StatutVente::AValider, $vente->statut);
        $this->assertSame(360_000, $vente->montant_fcfa);
        // Le stock ne bouge pas tant que ce n'est pas validé.
        $this->assertSame(500_000, $this->lot->stock());
    }

    #[Test]
    public function une_vente_sous_le_seuil_se_valide_depuis_la_liste_par_un_autre(): void
    {
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationVente, 'valeur' => '100']);
        $this->actingAs($this->comptable);
        Livewire::test(FormulaireVente::class)
            ->set('lotId', (string) $this->lot->id)->set('acheteurNom', 'Grossiste Abidjan')
            ->set('poidsKg', '100')->set('prixKg', '900')->call('enregistrer')->assertHasNoErrors();
        $vente = Vente::firstOrFail();
        $this->assertSame(StatutVente::AValider, $vente->statut);

        $this->actingAs($this->direction);
        Livewire::test(ListeVentes::class)
            ->assertSeeHtml("wire:click=\"valider('{$vente->id}')\"")
            ->call('valider', $vente->id)
            ->assertHasNoErrors()
            ->assertSee('Vente validée');

        $this->assertSame(400_000, $this->lot->stock());
    }

    #[Test]
    public function encaisser_puis_contre_passer_depuis_la_fiche_de_la_vente(): void
    {
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationVente, 'valeur' => '100000000']);
        $this->actingAs($this->direction);
        Livewire::test(FormulaireVente::class)
            ->set('lotId', (string) $this->lot->id)->set('acheteurNom', 'Ivoire Export SA')
            ->set('poidsKg', '400')->set('prixKg', '900')->call('enregistrer')->assertHasNoErrors();
        $vente = Vente::firstOrFail();

        $this->actingAs($this->comptable);
        $fiche = Livewire::test(FicheVente::class, ['vente' => $vente])
            ->call('ouvrirEncaissement')
            ->set('compteId', (string) $this->caisseCentrale->id)
            ->set('montantEncaisse', '200 000')
            ->call('encaisser')
            ->assertHasNoErrors()
            ->assertSee('Encaissement enregistré')
            ->assertSeeHtml('160'.self::FINE.'000 FCFA'); // reste à encaisser : 360 000 − 200 000

        $encaissement = $vente->encaissements()->firstOrFail();
        // Caisse centrale : 5 000 000 (apport) − 1 000 000 (avance à l'agent) + 200 000 (encaissement).
        $this->assertSame(4_200_000, $this->caisseCentrale->solde());

        $fiche->call('preparerContrePassation', $encaissement->id)
            ->set('motifContrePassation', 'Chèque rejeté par la banque')
            ->call('contrePasser')
            ->assertHasNoErrors()
            ->assertSee('Encaissement contre-passé');

        $this->assertSame(0, $vente->encaisse());
        $this->assertSame(4_000_000, $this->caisseCentrale->solde());
    }

    #[Test]
    public function l_encaissement_ne_se_modifie_ni_ne_se_supprime(): void
    {
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationVente, 'valeur' => '100000000']);
        $this->actingAs($this->direction);
        Livewire::test(FormulaireVente::class)
            ->set('lotId', (string) $this->lot->id)->set('acheteurNom', 'Ivoire Export SA')
            ->set('poidsKg', '400')->set('prixKg', '900')->call('enregistrer')->assertHasNoErrors();
        $vente = Vente::firstOrFail();

        $this->actingAs($this->comptable);
        Livewire::test(FicheVente::class, ['vente' => $vente])
            ->call('ouvrirEncaissement')->set('compteId', (string) $this->caisseCentrale->id)
            ->set('montantEncaisse', '100000')->call('encaisser')->assertHasNoErrors();

        $encaissement = $vente->encaissements()->firstOrFail();
        $this->expectException(RegistreImmuableException::class);
        $encaissement->delete();
    }

    #[Test]
    public function la_marge_du_lot_s_affiche_sur_la_fiche_de_la_vente(): void
    {
        Parametre::query()->create(['cle' => CleParametre::SeuilValidationVente, 'valeur' => '100000000']);
        $this->actingAs($this->direction);
        Livewire::test(FormulaireVente::class)
            ->set('lotId', (string) $this->lot->id)->set('acheteurNom', 'Ivoire Export SA')
            ->set('poidsKg', '500')->set('prixKg', '900')->call('enregistrer')->assertHasNoErrors();
        $vente = Vente::firstOrFail();

        Livewire::test(FicheVente::class, ['vente' => $vente])
            ->assertSee('212'.self::FINE.'500 FCFA') // coût de l'achat : 500 kg × 425
            ->assertSee('450'.self::FINE.'000 FCFA') // revenu de la vente : 500 kg × 900
            ->assertSee('237'.self::FINE.'500 FCFA'); // marge
    }
}
