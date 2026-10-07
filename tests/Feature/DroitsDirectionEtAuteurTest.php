<?php

namespace Tests\Feature;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutAchat;
use App\Enums\StatutCampagne;
use App\Enums\StatutDepense;
use App\Enums\StatutLot;
use App\Enums\StatutPret;
use App\Enums\StatutVente;
use App\Enums\TypeAcheteur;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Livewire\Achats\ListeAchats;
use App\Livewire\Prets\FichePret;
use App\Livewire\Prets\FormulairePret;
use App\Livewire\Tresorerie\Comptes;
use App\Livewire\Ventes\FormulaireVente;
use App\Models\Apport;
use App\Models\Campagne;
use App\Models\CategorieDepense;
use App\Models\CompteTresorerie;
use App\Models\Encaissement;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\Parametre;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Models\Vente;
use App\Services\Achats;
use App\Services\Depenses;
use App\Services\Encaissements;
use App\Services\Prets;
use App\Services\Tresorerie;
use App\Services\Ventes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Demande du 2026-10-06 : la direction est le compte supérieur — ce qu'elle saisit (prêt,
 * dépense, achat, vente) est validé dès la saisie, à son nom. Chacun supprime (annule) et
 * modifie (remplace) ce qu'il a saisi ; la direction, tout. Rien ne s'efface : l'opération
 * annulée reste visible, ses effets sont contre-passés.
 */
class DroitsDirectionEtAuteurTest extends TestCase
{
    use RefreshDatabase;

    private User $direction;

    private User $comptable;

    private User $agent;

    private User $autreAgent;

    private Campagne $campagne;

    private Producteur $producteur;

    private CompteTresorerie $caisse;

    private Lot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->agent = User::factory()->role(Role::Agent)->create();
        $this->autreAgent = User::factory()->role(Role::Agent)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create(['prix_officiel_kg_fcfa' => 400]);
        $this->producteur = Producteur::factory()->create();
        // Aucun seuil réglé : sans la règle « direction », tout attendrait une validation.
        $this->caisse = CompteTresorerie::factory()->create();
        Tresorerie::entree($this->caisse, 10_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds', $this->direction);
        $this->lot = Lot::query()->create([
            'produit_id' => $this->campagne->produit_id, 'campagne_id' => $this->campagne->id,
            'magasin_id' => Magasin::factory()->create()->id, 'statut' => StatutLot::Ouvert, 'cree_par' => $this->comptable->id,
        ]);
    }

    private function demanderPret(User $auteur, int $montant = 300_000): Pret
    {
        return Prets::demander([
            'producteur_id' => $this->producteur->id, 'campagne_id' => $this->campagne->id, 'montant_fcfa' => $montant,
            'forme' => FormePret::Especes, 'echeance' => Carbon::today()->addMonths(5),
        ], $auteur);
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

    /** 500 kg net dans le lot, par la direction (validé tout de suite). */
    private function remplirLeLot(): void
    {
        Achats::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id, 'fournisseur_type' => TypeFournisseur::Producteur,
            'producteur_id' => $this->producteur->id, 'date_achat' => now()->subDay(), 'poids_brut_g' => 505_000, 'tare_g' => 5_000,
            'prix_kg_fcfa' => 400, 'compte_id' => $this->caisse->id,
        ], $this->direction);
    }

    private function vendre(User $auteur, int $grammes = 500_000): Vente
    {
        return Ventes::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id, 'type_acheteur' => TypeAcheteur::Exportateur,
            'acheteur_nom' => 'Ivoire Export', 'date_vente' => now()->subMinute(), 'poids_net_g' => $grammes, 'prix_kg_fcfa' => 900,
        ], $auteur);
    }

    #[Test]
    public function un_pret_saisi_par_la_direction_est_accorde_sans_autre_validation(): void
    {
        $pret = $this->demanderPret($this->direction, 8_000_000);

        $this->assertSame(StatutPret::Valide, $pret->statut);
        $this->assertSame(1, $pret->validations_requises);
        $this->assertNotNull($pret->valide_at);
        $this->assertSame([$this->direction->id], $pret->validations()->pluck('user_id')->all());

        // Il se verse tout de suite.
        Prets::decaisser($pret, ['compte_id' => $this->caisse->id, 'mode' => ModeDecaissement::Especes, 'montant_fcfa' => 8_000_000,
            'date' => Carbon::today()], $this->comptable, 'prets/recus/r.jpg');
        $this->assertSame(StatutPret::Decaisse, $pret->refresh()->statut);
    }

    #[Test]
    public function le_pret_d_un_agent_attend_toujours_deux_validations_et_l_auteur_ne_valide_pas(): void
    {
        $pret = $this->demanderPret($this->agent);

        $this->assertSame(StatutPret::Demande, $pret->statut);
        $this->assertSame(2, $pret->validations_requises);
        $this->refusAttendu(fn () => Prets::valider($pret, $this->agent), 'Votre rôle');

        $pretComptable = $this->demanderPret($this->comptable);
        $this->assertSame(StatutPret::Demande, $pretComptable->statut);
    }

    #[Test]
    public function depense_achat_et_vente_de_la_direction_sont_valides_a_son_nom_sans_seuil(): void
    {
        $categorie = CategorieDepense::query()->create(['nom' => 'Carburant', 'exclue_fonds_campagne' => false, 'actif' => true]);
        $depense = Depenses::saisir([
            'categorie_id' => $categorie->id, 'compte_id' => $this->caisse->id, 'montant_fcfa' => 900_000,
            'date_depense' => Carbon::today(), 'beneficiaire' => 'Station',
        ], 'depenses/justificatifs/j.jpg', $this->direction);
        $this->assertSame(StatutDepense::Payee, $depense->statut);
        $this->assertSame($this->direction->id, $depense->valide_par);

        $this->remplirLeLot();
        $this->assertSame(500_000, $this->lot->stock());

        $vente = $this->vendre($this->direction, 200_000);
        $this->assertSame(StatutVente::Valide, $vente->statut);
        $this->assertSame($this->direction->id, $vente->valide_par);

        // Le comptable, lui, attend toujours une autre personne.
        $venteComptable = $this->vendre($this->comptable, 100_000);
        $this->assertSame(StatutVente::AValider, $venteComptable->statut);
        $this->refusAttendu(fn () => Ventes::valider($venteComptable, $this->comptable), 'propre vente');
    }

    #[Test]
    public function l_auteur_et_la_direction_annulent_un_pret_les_autres_non(): void
    {
        $pret = $this->demanderPret($this->agent);

        $this->refusAttendu(fn () => Prets::annuler($pret, $this->autreAgent, 'Erreur de saisie'), 'auteur du prêt');
        $this->refusAttendu(fn () => Prets::annuler($pret, $this->comptable, 'Erreur de saisie'), 'auteur du prêt');
        $this->refusAttendu(fn () => Prets::annuler($pret, $this->agent, 'non'), 'motif');

        $annule = Prets::annuler($pret, $this->agent, 'Mauvais producteur');
        $this->assertSame(StatutPret::Annule, $annule->statut);
        $this->assertSame($this->agent->id, $annule->annule_par);
        $this->assertSame('Mauvais producteur', $annule->motif_annulation);
        $this->refusAttendu(fn () => Prets::annuler($pret, $this->direction, 'Encore une fois'), 'ne s\'annule plus');

        // La direction annule le prêt de quelqu'un d'autre.
        $autre = $this->demanderPret($this->comptable);
        $this->assertSame(StatutPret::Annule, Prets::annuler($autre, $this->direction, 'Doublon')->statut);
    }

    #[Test]
    public function un_pret_annule_ne_compte_plus_dans_le_plafond(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::PlafondPretProducteur], ['valeur' => '500000']);
        $pret = $this->demanderPret($this->agent, 400_000);
        $this->refusAttendu(fn () => $this->demanderPret($this->agent, 400_000), 'Plafond par producteur');

        Prets::annuler($pret, $this->agent, 'Montant faux');
        $this->assertSame(StatutPret::Demande, $this->demanderPret($this->agent, 400_000)->statut);
    }

    #[Test]
    public function un_pret_deja_verse_ne_s_annule_pas(): void
    {
        $pret = $this->demanderPret($this->direction);
        Prets::decaisser($pret, ['compte_id' => $this->caisse->id, 'mode' => ModeDecaissement::Especes, 'montant_fcfa' => 100_000,
            'date' => Carbon::today()], $this->comptable, 'prets/recus/r.jpg');

        $this->refusAttendu(fn () => Prets::annuler($pret, $this->direction, 'Trop tard'), 'déjà été remis');
    }

    #[Test]
    public function annuler_une_vente_validee_rend_les_kilos_et_contre_passe_l_encaissement(): void
    {
        $this->remplirLeLot();
        $soldeAvant = $this->caisse->solde();
        $vente = $this->vendre($this->direction);
        $this->assertSame(0, $this->lot->stock());
        $this->assertSame(StatutLot::Vendu, $this->lot->refresh()->statut);
        Encaissements::encaisser($vente, $this->caisse, 300_000, Carbon::today(), $this->comptable);
        $this->assertSame($soldeAvant + 300_000, $this->caisse->solde());

        $this->refusAttendu(fn () => Ventes::annuler($vente, $this->comptable, 'Pas la mienne'), 'auteur de la vente');
        $annulee = Ventes::annuler($vente, $this->direction, 'Acheteur désisté');

        $this->assertSame(StatutVente::Annule, $annulee->statut);
        $this->assertSame(500_000, $this->lot->stock());
        $this->assertSame(StatutLot::Ouvert, $this->lot->refresh()->statut);
        $this->assertSame($soldeAvant, $this->caisse->solde());
        $this->assertSame(0, (int) Encaissement::query()->where('vente_id', $vente->id)->sum('montant_fcfa'));
        $this->refusAttendu(fn () => Encaissements::encaisser($vente, $this->caisse, 1_000, Carbon::today(), $this->comptable), 'rien à encaisser');
    }

    #[Test]
    public function un_agent_supprime_son_propre_achat_depuis_la_liste_pas_celui_d_un_autre(): void
    {
        $caisseAgent = CompteTresorerie::factory()->caisseDe($this->agent)->create();
        Tresorerie::avanceAgent($this->caisse, $caisseAgent, 1_000_000, Carbon::today(), 'Avance', $this->direction);
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationAchat], ['valeur' => '5000000']);
        $achat = Achats::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id, 'fournisseur_type' => TypeFournisseur::Producteur,
            'producteur_id' => $this->producteur->id, 'date_achat' => now()->subMinute(), 'poids_brut_g' => 105_000, 'tare_g' => 5_000,
            'prix_kg_fcfa' => 400, 'compte_id' => $caisseAgent->id,
        ], $this->agent);
        $this->assertSame(StatutAchat::Valide, $achat->statut);

        $this->refusAttendu(fn () => Achats::annuler($achat, $this->autreAgent, 'Pas à moi'), 'auteur de l\'achat');

        Livewire::actingAs($this->agent)->test(ListeAchats::class)
            ->assertSeeHtml("preparerAnnulation('{$achat->id}')")
            ->call('preparerAnnulation', $achat->id)
            ->set('motifAnnulation', 'Poids mal lu')
            ->call('annulerAchat')
            ->assertHasNoErrors();

        $this->assertSame(StatutAchat::Annule, $achat->refresh()->statut);
        $this->assertSame(0, $this->lot->stock());
        $this->assertSame(1_000_000, $caisseAgent->solde());
    }

    #[Test]
    public function modifier_un_pret_le_remplace_et_garde_l_ancien_annule(): void
    {
        $ancien = $this->demanderPret($this->agent);

        Livewire::actingAs($this->agent)->test(FormulairePret::class, ['corrigeId' => $ancien->id])
            ->assertSet('montant', '300000')
            ->assertSet('producteurId', $this->producteur->id)
            ->set('montant', '350000')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $ancien->refresh();
        $nouveau = Pret::query()->whereKeyNot($ancien->id)->sole();
        $this->assertSame(StatutPret::Annule, $ancien->statut);
        $this->assertSame("Modifié : remplacé par le prêt {$nouveau->reference}", $ancien->motif_annulation);
        $this->assertSame(350_000, $nouveau->montant_fcfa);
        $this->assertSame(StatutPret::Demande, $nouveau->statut);

        // Un autre agent ne peut pas ouvrir la modification.
        Livewire::actingAs($this->autreAgent)->test(FormulairePret::class, ['corrigeId' => $nouveau->id])->assertForbidden();
    }

    #[Test]
    public function la_fiche_du_pret_propose_supprimer_a_l_auteur_et_annule(): void
    {
        $pret = $this->demanderPret($this->agent);

        Livewire::actingAs($this->autreAgent)->test(FichePret::class, ['pret' => $pret])->assertDontSeeHtml('wire:click="ouvrirAnnulation"');

        Livewire::actingAs($this->agent)->test(FichePret::class, ['pret' => $pret])
            ->assertSeeHtml('wire:click="ouvrirAnnulation"')
            ->call('ouvrirAnnulation')
            ->set('motifAnnulation', 'Demande en double')
            ->call('annulerPret')
            ->assertHasNoErrors()
            ->assertSee('Demande en double');

        $this->assertSame(StatutPret::Annule, $pret->refresh()->statut);
    }

    #[Test]
    public function modifier_une_vente_rend_les_kilos_puis_ressort_le_nouveau_poids(): void
    {
        $this->remplirLeLot();
        $ancienne = $this->vendre($this->direction, 500_000);
        $this->assertSame(StatutLot::Vendu, $this->lot->refresh()->statut);

        Livewire::actingAs($this->direction)->test(FormulaireVente::class, ['corrigeId' => $ancienne->id])
            ->assertSet('poidsKg', '500,000')
            ->set('poidsKg', '450')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame(StatutVente::Annule, $ancienne->refresh()->statut);
        $nouvelle = Vente::query()->whereKeyNot($ancienne->id)->sole();
        $this->assertSame(450_000, $nouvelle->poids_net_g);
        $this->assertSame(50_000, $this->lot->stock());
    }

    #[Test]
    public function un_agent_vend_voit_ses_ventes_seulement_et_le_bureau_valide(): void
    {
        $this->remplirLeLot();
        $vente = $this->vendre($this->agent, 200_000);
        $this->assertSame(StatutVente::AValider, $vente->statut);
        $this->refusAttendu(fn () => Ventes::valider($vente, $this->agent), 'Votre rôle');

        $autre = $this->vendre($this->direction, 100_000);
        $this->actingAs($this->autreAgent)->get(route('ventes.fiche', $vente))->assertForbidden();
        $this->actingAs($this->agent)->get(route('ventes.fiche', $vente))->assertOk()->assertDontSee('Marge du lot');
        $this->actingAs($this->agent)->get(route('ventes.fiche', $autre))->assertForbidden();

        Ventes::valider($vente, $this->comptable);
        $this->assertSame(200_000, $this->lot->stock());

        // Encaissée par le bureau : l'agent ne peut plus l'annuler, la direction si.
        Encaissements::encaisser($vente->refresh(), $this->caisse, 50_000, Carbon::today(), $this->comptable);
        $this->refusAttendu(fn () => Ventes::annuler($vente, $this->agent, 'Acheteur parti'), 'déjà été encaissé');
        $this->assertSame(StatutVente::Annule, Ventes::annuler($vente, $this->direction, 'Acheteur parti')->statut);
    }

    #[Test]
    public function la_tresorerie_enregistre_l_apport_d_un_investisseur_dans_le_registre_des_apports(): void
    {
        $investisseur = User::factory()->role(Role::Investisseur)->create(['nom' => 'Fonds A']);
        $compteDedie = CompteTresorerie::factory()->create(['campagne_id' => $this->campagne->id, 'nom' => 'Compte campagne']);

        Livewire::actingAs($this->comptable)->test(Comptes::class)
            ->call('ouvrir', 'entree')
            ->assertSee('Apport d&#039;un investisseur (campagne)', false)
            ->set('compteId', (string) $compteDedie->id)
            ->set('nature', 'apport_campagne')
            ->set('investisseurId', (string) $investisseur->id)
            ->set('montant', '2 000 000')
            ->set('libelle', 'Souscription Fonds A')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $apport = Apport::query()->sole();
        $this->assertSame($investisseur->id, $apport->investisseur_id);
        $this->assertSame($this->campagne->id, $apport->campagne_id);
        $this->assertSame(2_000_000, $apport->montant_fcfa);
        $this->assertSame(2_000_000, $compteDedie->solde());

        // Sur un compte sans campagne (permis depuis le 2026-10-07) : il faut choisir la campagne ;
        // un nom tapé = investisseur sans compte.
        Livewire::actingAs($this->comptable)->test(Comptes::class)
            ->call('ouvrir', 'entree')
            ->set('compteId', (string) $this->caisse->id)
            ->set('nature', 'apport_campagne')
            ->set('investisseurId', 'Fonds B sans compte')
            ->set('montant', '1000')
            ->set('libelle', 'Souscription')
            ->call('enregistrer')
            ->assertHasErrors('campagneId')
            ->set('campagneId', (string) $this->campagne->id)
            ->call('enregistrer')
            ->assertHasNoErrors();
        $this->assertSame('Fonds B sans compte', Apport::query()->latest('id')->first()?->apporteur_nom);
    }
}
