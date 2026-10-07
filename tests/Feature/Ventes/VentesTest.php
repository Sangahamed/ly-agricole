<?php

namespace Tests\Feature\Ventes;

use App\Enums\CleParametre;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutLot;
use App\Enums\StatutVente;
use App\Enums\TypeAcheteur;
use App\Enums\TypeFournisseur;
use App\Exceptions\OperationRefusee;
use App\Exceptions\RegistreImmuableException;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Lot;
use App\Models\Magasin;
use App\Models\Parametre;
use App\Models\Producteur;
use App\Models\User;
use App\Models\Vente;
use App\Services\Achats;
use App\Services\Encaissements;
use App\Services\Tresorerie;
use App\Services\Ventes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VentesTest extends TestCase
{
    use RefreshDatabase;

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
        $this->magasin = Magasin::factory()->create();
        $this->lot = Lot::query()->create([
            'produit_id' => $this->campagne->produit_id, 'campagne_id' => $this->campagne->id,
            'magasin_id' => $this->magasin->id, 'statut' => StatutLot::Ouvert, 'cree_par' => $this->comptable->id,
        ]);
        $this->caisseCentrale = CompteTresorerie::factory()->create();
        $this->caisseAgent = CompteTresorerie::factory()->caisseDe($this->agent)->create();
        Tresorerie::entree($this->caisseCentrale, 5_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds', $this->direction);
        Tresorerie::avanceAgent($this->caisseCentrale, $this->caisseAgent, 2_000_000, Carbon::today(), 'Avance achats', $this->direction);
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationAchat], ['valeur' => '5000000']);
    }

    /** Remplit le lot de 500 kg à 425 F/kg, achat validé sous le seuil. */
    private function remplirLeLot(int $netG = 500_000, int $prix = 425): void
    {
        Achats::enregistrer([
            'campagne_id' => $this->campagne->id, 'lot_id' => $this->lot->id,
            'fournisseur_type' => TypeFournisseur::Producteur, 'producteur_id' => Producteur::factory()->create()->id,
            'date_achat' => now()->subDay(), 'poids_brut_g' => $netG + 5_000, 'tare_g' => 5_000,
            'prix_kg_fcfa' => $prix, 'compte_id' => $this->caisseAgent->id,
        ], $this->agent);
    }

    /** @param  array<string, mixed>  $surcharge */
    private function vendre(array $surcharge = [], ?User $auteur = null): Vente
    {
        return Ventes::enregistrer(array_merge([
            'campagne_id' => $this->campagne->id,
            'lot_id' => $this->lot->id,
            'type_acheteur' => TypeAcheteur::Exportateur,
            'acheteur_nom' => 'Ivoire Export SA',
            'date_vente' => now()->subMinute(),
            'poids_net_g' => 400_000,
            'prix_kg_fcfa' => 900,
        ], $surcharge), $auteur ?? $this->direction);
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
    public function une_vente_sous_le_seuil_sort_le_stock_tout_de_suite(): void
    {
        $this->remplirLeLot();
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);

        $vente = $this->vendre();

        $this->assertSame(StatutVente::Valide, $vente->statut);
        $this->assertSame(intdiv(400_000 * 900 + 500, 1000), $vente->montant_fcfa);
        $this->assertSame(100_000, $this->lot->refresh()->stock());
    }

    #[Test]
    public function sans_seuil_defini_une_vente_attend_une_autre_personne(): void
    {
        $this->remplirLeLot();

        // Le comptable (la direction, compte supérieur, serait validée tout de suite).
        $vente = $this->vendre(auteur: $this->comptable);

        $this->assertSame(StatutVente::AValider, $vente->statut);
        // Le stock ne bouge pas tant que la vente n'est pas validée.
        $this->assertSame(500_000, $this->lot->refresh()->stock());
    }

    #[Test]
    public function une_vente_ne_peut_pas_depasser_le_stock_disponible(): void
    {
        $this->remplirLeLot(netG: 300_000);

        $this->refusAttendu(fn () => $this->vendre(['poids_net_g' => 400_000]), 'Stock insuffisant');
    }

    #[Test]
    public function l_auteur_ne_peut_pas_valider_sa_propre_vente(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '0']);
        $this->remplirLeLot();
        $vente = $this->vendre(auteur: $this->comptable);

        $this->refusAttendu(fn () => Ventes::valider($vente, $this->comptable), 'propre vente');
    }

    #[Test]
    public function une_vente_validee_vide_le_lot_qui_passe_vendu(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '0']);
        $this->remplirLeLot(netG: 400_000);
        $vente = $this->vendre(['poids_net_g' => 400_000], auteur: $this->comptable);

        Ventes::valider($vente, $this->direction);

        $this->assertSame(0, $this->lot->refresh()->stock());
        $this->assertSame(StatutLot::Vendu, $this->lot->statut);
    }

    #[Test]
    public function un_refus_documente_ne_touche_pas_au_stock(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '0']);
        $this->remplirLeLot();
        $vente = $this->vendre(auteur: $this->comptable);

        Ventes::refuser($vente, $this->direction, 'Acheteur s\'est rétracté');

        $this->assertSame(StatutVente::Refuse, $vente->refresh()->statut);
        $this->assertSame(500_000, $this->lot->refresh()->stock());
    }

    #[Test]
    public function un_agent_saisit_une_vente_qui_attend_le_bureau_mais_l_agronome_ne_vend_pas(): void
    {
        // Depuis le 2026-10-07, l'agent de terrain vend ; sa vente attend la validation du bureau.
        $this->remplirLeLot();
        $this->assertSame(StatutVente::AValider, $this->vendre(auteur: $this->agent)->statut);
        $this->refusAttendu(fn () => $this->vendre(auteur: User::factory()->role(Role::Agronome)->create()), 'saisir une vente');
    }

    #[Test]
    public function l_encaissement_ne_peut_pas_depasser_le_montant_de_la_vente(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);
        $this->remplirLeLot();
        $vente = $this->vendre();

        $this->refusAttendu(
            fn () => Encaissements::encaisser($vente, $this->caisseCentrale, $vente->montant_fcfa + 1, Carbon::today(), $this->comptable),
            'reste à encaisser',
        );
    }

    #[Test]
    public function un_encaissement_partiel_laisse_un_reste_et_la_tresorerie_monte(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);
        $this->remplirLeLot();
        $vente = $this->vendre();
        $soldeAvant = $this->caisseCentrale->solde();

        $moitie = intdiv($vente->montant_fcfa, 2);
        Encaissements::encaisser($vente, $this->caisseCentrale, $moitie, Carbon::today(), $this->comptable);

        $vente->refresh();
        $this->assertSame($moitie, $vente->encaisse());
        $this->assertSame($vente->montant_fcfa - $moitie, $vente->resteAEncaisser());
        $this->assertSame($soldeAvant + $moitie, $this->caisseCentrale->solde());
    }

    #[Test]
    public function une_contre_passation_d_encaissement_rouvre_le_reste_a_encaisser(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);
        $this->remplirLeLot();
        $vente = $this->vendre();
        $encaissement = Encaissements::encaisser($vente, $this->caisseCentrale, $vente->montant_fcfa, Carbon::today(), $this->comptable);
        $soldeApresEncaissement = $this->caisseCentrale->solde();

        Encaissements::contrePasser($encaissement, 'Chèque rejeté', $this->direction);

        $vente->refresh();
        $this->assertSame(0, $vente->encaisse());
        $this->assertSame($vente->montant_fcfa, $vente->resteAEncaisser());
        $this->assertSame($soldeApresEncaissement - $vente->montant_fcfa, $this->caisseCentrale->solde());
    }

    #[Test]
    public function un_encaissement_ne_se_modifie_pas(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);
        $this->remplirLeLot();
        $vente = $this->vendre();
        $encaissement = Encaissements::encaisser($vente, $this->caisseCentrale, 100_000, Carbon::today(), $this->comptable);

        $this->expectException(RegistreImmuableException::class);
        $encaissement->update(['montant_fcfa' => 999]);
    }

    #[Test]
    public function la_marge_d_un_lot_est_le_revenu_des_ventes_moins_le_cout_des_achats(): void
    {
        Parametre::query()->updateOrCreate(['cle' => CleParametre::SeuilValidationVente], ['valeur' => '1000000000']);
        $this->remplirLeLot(netG: 500_000, prix: 425); // coût = 212 500 F
        $this->vendre(['poids_net_g' => 500_000, 'prix_kg_fcfa' => 900]); // revenu = 450 000 F

        $marge = Ventes::margeLot($this->lot->refresh());

        $this->assertSame(212_500, $marge['cout']);
        $this->assertSame(450_000, $marge['revenu']);
        $this->assertSame(237_500, $marge['marge']);
    }
}
