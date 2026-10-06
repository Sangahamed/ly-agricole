<?php

namespace Tests\Feature\Prets;

use App\Enums\CleParametre;
use App\Enums\FormePret;
use App\Enums\ModeDecaissement;
use App\Enums\NatureMouvement;
use App\Enums\Role;
use App\Enums\StatutCampagne;
use App\Enums\StatutPret;
use App\Enums\TypeCompte;
use App\Exceptions\OperationRefusee;
use App\Exceptions\RegistreImmuableException;
use App\Models\Campagne;
use App\Models\CompteTresorerie;
use App\Models\Decaissement;
use App\Models\MouvementTresorerie;
use App\Models\Parametre;
use App\Models\Parcelle;
use App\Models\Pret;
use App\Models\Producteur;
use App\Models\User;
use App\Services\Prets;
use App\Services\Tresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PretsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $directeur;

    private User $directrice;

    private User $comptable;

    private Campagne $campagne;

    private CompteTresorerie $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->role(Role::Agent)->create();
        $this->directeur = User::factory()->role(Role::Direction)->create();
        $this->directrice = User::factory()->role(Role::Direction)->create();
        $this->comptable = User::factory()->role(Role::Comptable)->create();
        $this->campagne = Campagne::factory()->statut(StatutCampagne::Ouverte)->create();
        $this->caisse = CompteTresorerie::factory()->create(['nom' => 'Caisse centrale']);
        Tresorerie::entree($this->caisse, 30_000_000, NatureMouvement::Apport, Carbon::today(), 'Fonds de campagne', $this->directeur);
    }

    private function parametre(CleParametre $cle, ?int $valeur): void
    {
        Parametre::query()->updateOrCreate(['cle' => $cle], ['valeur' => $valeur === null ? null : (string) $valeur]);
    }

    /** @param  array<string, mixed>  $surcharge */
    private function demander(int $montant = 3_000_000, array $surcharge = [], ?string $accord = null): Pret
    {
        return Prets::demander(array_merge([
            'producteur_id' => Producteur::factory()->create()->id,
            'campagne_id' => $this->campagne->id,
            'montant_fcfa' => $montant,
            'forme' => FormePret::Especes,
            'echeance' => Carbon::today()->addMonths(6),
        ], $surcharge), $this->agent, $accord);
    }

    private function decaisserTout(Pret $pret): Decaissement
    {
        return Prets::decaisser($pret, [
            'compte_id' => $this->caisse->id,
            'mode' => ModeDecaissement::Especes,
            'montant_fcfa' => $pret->resteARemettre(),
            'date' => Carbon::today(),
        ], $this->comptable, 'prets/recus/recu.jpg');
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
    public function sept_prets_de_3_millions_de_bout_en_bout_font_baisser_la_caisse_de_21_millions(): void
    {
        // Livrable de la semaine 4 (docs/PLAN_IMPLEMENTATION.md).
        $this->parametre(CleParametre::SeuilValidationPret, 5_000_000);
        $avant = $this->caisse->solde();

        for ($i = 0; $i < 7; $i++) {
            $pret = $this->demander(3_000_000, ['prix_reference_kg_fcfa' => 400]);
            $this->assertSame(1, $pret->validations_requises);

            $pret = Prets::valider($pret, $this->directeur);
            $this->assertSame(StatutPret::Valide, $pret->statut);

            $this->decaisserTout($pret);
            $pret->refresh();
            $this->assertSame(StatutPret::Decaisse, $pret->statut);
            $this->assertSame(0, $pret->resteARemettre());
            // 3 000 000 ÷ 400 FCFA/kg = 7 500 kg attendus.
            $this->assertSame(7_500_000, $pret->grammes_attendus);
        }

        $this->assertSame(21_000_000, $avant - $this->caisse->solde());
        $this->assertSame(21_000_000, (int) Decaissement::query()->sum('montant_fcfa'));
        $this->assertSame(7, MouvementTresorerie::query()->where('nature', NatureMouvement::DecaissementPret)->count());
    }

    #[Test]
    public function l_auteur_ne_valide_pas_sa_propre_demande(): void
    {
        $pret = Prets::demander([
            'producteur_id' => Producteur::factory()->create()->id,
            'campagne_id' => $this->campagne->id,
            'montant_fcfa' => 500_000,
            'forme' => FormePret::Especes,
            'echeance' => Carbon::today()->addMonth(),
        ], $this->agent);

        $this->refusAttendu(fn () => Prets::valider($pret, $this->agent), 'Votre rôle');
        $this->assertSame(0, $pret->validations()->count());

        // Seule exception (2026-10-06) : la direction, compte supérieur, accorde son propre prêt
        // dès la saisie — trace : une validation à son nom. Plus rien à valider ni à refuser.
        $pretDirection = Prets::demander([
            'producteur_id' => Producteur::factory()->create()->id,
            'campagne_id' => $this->campagne->id,
            'montant_fcfa' => 500_000,
            'forme' => FormePret::Especes,
            'echeance' => Carbon::today()->addMonth(),
        ], $this->directeur);

        $this->assertSame(StatutPret::Valide, $pretDirection->statut);
        $this->assertSame([$this->directeur->id], $pretDirection->validations()->pluck('user_id')->all());
        $this->refusAttendu(fn () => Prets::refuser($pretDirection, $this->directrice, 'Je me ravise'), 'plus en demande');
    }

    #[Test]
    public function sans_seuil_defini_il_faut_deux_validateurs_distincts(): void
    {
        $pret = $this->demander(100_000);
        $this->assertSame(2, $pret->validations_requises);

        $pret = Prets::valider($pret, $this->directeur);
        $this->assertSame(StatutPret::Demande, $pret->statut);

        $this->refusAttendu(fn () => Prets::valider($pret, $this->directeur), 'déjà validé');
        $this->refusAttendu(fn () => $this->decaisserTout($pret), 'Seul un prêt validé');

        $pret = Prets::valider($pret, $this->directrice);
        $this->assertSame(StatutPret::Valide, $pret->statut);
        $this->assertEqualsCanonicalizing([$this->directeur->id, $this->directrice->id], $pret->validations()->pluck('user_id')->all());
    }

    #[Test]
    public function au_dessus_du_seuil_deux_validations_en_dessous_une(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 1_000_000);

        $this->assertSame(1, $this->demander(1_000_000)->validations_requises);
        $this->assertSame(2, $this->demander(1_000_001)->validations_requises);
    }

    #[Test]
    public function seule_la_direction_valide_un_pret(): void
    {
        $pret = $this->demander();

        $this->refusAttendu(fn () => Prets::valider($pret, $this->comptable), 'ne permet pas de valider');
    }

    #[Test]
    public function un_refus_est_motive_et_definitif(): void
    {
        $pret = $this->demander();

        $this->refusAttendu(fn () => Prets::refuser($pret, $this->directeur, ''), 'motif');
        $pret = Prets::refuser($pret, $this->directeur, 'Parcelle non visitée');

        $this->assertSame(StatutPret::Refuse, $pret->statut);
        $this->refusAttendu(fn () => Prets::valider($pret, $this->directrice), 'n\'est plus en demande');
    }

    #[Test]
    public function on_ne_decaisse_jamais_plus_que_le_montant_et_les_tranches_s_additionnent(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 10_000_000);
        $pret = Prets::valider($this->demander(3_000_000), $this->directeur);

        $tranche = fn (int $montant) => Prets::decaisser($pret, [
            'compte_id' => $this->caisse->id, 'mode' => ModeDecaissement::Especes,
            'montant_fcfa' => $montant, 'date' => Carbon::today(),
        ], $this->comptable, 'prets/recus/r.jpg');

        $tranche(1_000_000);
        $this->assertSame(2_000_000, $pret->refresh()->resteARemettre());
        $this->assertSame(StatutPret::Valide, $pret->statut);

        $this->refusAttendu(fn () => $tranche(2_000_001), 'reste à remettre');

        $tranche(2_000_000);
        $this->assertSame(StatutPret::Decaisse, $pret->refresh()->statut);
        $this->refusAttendu(fn () => $tranche(1), 'Seul un prêt validé');
        $this->assertSame(27_000_000, $this->caisse->solde());
    }

    #[Test]
    public function un_versement_doit_avoir_sa_preuve_et_partir_du_bon_compte(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 10_000_000);
        $pretEspeces = Prets::valider($this->demander(200_000), $this->directeur);
        $pretMm = Prets::valider($this->demander(200_000, ['forme' => FormePret::MobileMoney]), $this->directeur);
        $wave = CompteTresorerie::factory()->create(['type' => TypeCompte::Wave]);
        Tresorerie::virement($this->caisse, $wave, 500_000, Carbon::today(), 'Alimentation Wave', $this->directeur);

        $verser = fn (Pret $p, CompteTresorerie $c, ModeDecaissement $m, ?string $ref, ?string $recu) => Prets::decaisser($p, [
            'compte_id' => $c->id, 'mode' => $m, 'montant_fcfa' => 200_000, 'date' => Carbon::today(), 'reference' => $ref,
        ], $this->comptable, $recu);

        $this->refusAttendu(fn () => $verser($pretEspeces, $this->caisse, ModeDecaissement::Especes, null, null), 'reçu signé');
        $this->refusAttendu(fn () => $verser($pretEspeces, $wave, ModeDecaissement::Especes, null, 'r.jpg'), 'ne part pas du compte');
        $this->refusAttendu(fn () => $verser($pretEspeces, $wave, ModeDecaissement::MobileMoney, 'W1', null), 'de la même façon');
        $this->refusAttendu(fn () => $verser($pretMm, $wave, ModeDecaissement::MobileMoney, null, null), 'référence de la transaction');

        $verser($pretMm, $wave, ModeDecaissement::MobileMoney, 'WAVE-TX-8842', null);
        $this->assertSame(300_000, $wave->solde());
        $this->assertSame('WAVE-TX-8842', MouvementTresorerie::query()->latest('id')->firstOrFail()->reference_externe);
    }

    #[Test]
    public function un_agent_ne_decaisse_pas(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 10_000_000);
        $pret = Prets::valider($this->demander(), $this->directeur);

        $this->refusAttendu(fn () => Prets::decaisser($pret, [
            'compte_id' => $this->caisse->id, 'mode' => ModeDecaissement::Especes, 'montant_fcfa' => 1, 'date' => Carbon::today(),
        ], $this->agent, 'r.jpg'), 'ne permet pas de décaisser');
    }

    #[Test]
    public function contre_passer_un_versement_rouvre_le_reste_a_decaisser(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 10_000_000);
        $pret = Prets::valider($this->demander(3_000_000), $this->directeur);
        $decaissement = $this->decaisserTout($pret);
        $this->assertSame(StatutPret::Decaisse, $pret->refresh()->statut);

        Tresorerie::contrePasser($decaissement->mouvement, 'Versé au mauvais producteur', $this->comptable);

        $pret->refresh();
        $this->assertSame(StatutPret::Valide, $pret->statut);
        $this->assertSame(3_000_000, $pret->resteARemettre());
        $this->assertSame(30_000_000, $this->caisse->solde());
        // Le décaissement reste inscrit : on ne réécrit pas l'histoire.
        $this->assertSame(1, Decaissement::count());
    }

    #[Test]
    public function un_decaissement_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->parametre(CleParametre::SeuilValidationPret, 10_000_000);
        $decaissement = $this->decaisserTout(Prets::valider($this->demander(), $this->directeur));

        foreach ([fn () => $decaissement->update(['montant_fcfa' => 1]), fn () => $decaissement->delete(), fn () => Decaissement::query()->delete()] as $tentative) {
            try {
                $tentative();
                $this->fail('Registre modifié.');
            } catch (RegistreImmuableException) {
            }
        }

        $this->assertSame(3_000_000, (int) Decaissement::query()->sum('montant_fcfa'));
    }

    #[Test]
    public function plafond_par_producteur_sur_la_campagne(): void
    {
        $this->parametre(CleParametre::PlafondPretProducteur, 3_000_000);
        $producteur = Producteur::factory()->create();

        $this->demander(2_000_000, ['producteur_id' => $producteur->id]);
        $this->refusAttendu(fn () => $this->demander(1_000_001, ['producteur_id' => $producteur->id]), 'Plafond par producteur dépassé');
        $this->demander(1_000_000, ['producteur_id' => $producteur->id]);

        // Un prêt refusé ne compte pas dans le plafond.
        $autre = Producteur::factory()->create();
        Prets::refuser($this->demander(3_000_000, ['producteur_id' => $autre->id]), $this->directeur, 'Dossier incomplet');
        $this->demander(3_000_000, ['producteur_id' => $autre->id]);
    }

    #[Test]
    public function plafond_par_hectare_sur_les_surfaces_relevees(): void
    {
        $this->parametre(CleParametre::PlafondPretHectare, 1_000_000);
        $producteur = Producteur::factory()->create();
        $deuxHectares = Parcelle::factory()->carre(141)->create(['producteur_id' => $producteur->id]);
        $nonRelevee = Parcelle::factory()->create(['producteur_id' => $producteur->id]);
        $surface = (int) $deuxHectares->surface_m2;

        $this->refusAttendu(fn () => $this->demander(100_000, ['producteur_id' => $producteur->id, 'parcelle_ids' => [$nonRelevee->id]]), 'surface est relevée');

        $maximum = intdiv(1_000_000 * $surface, 10_000);
        $this->refusAttendu(fn () => $this->demander($maximum + 1, ['producteur_id' => $producteur->id, 'parcelle_ids' => [$deuxHectares->id]]), 'Plafond par hectare dépassé');

        $pret = $this->demander($maximum, ['producteur_id' => $producteur->id, 'parcelle_ids' => [$deuxHectares->id, $nonRelevee->id]]);
        $this->assertSame($surface, $pret->surfaceFinanceeM2());
    }

    #[Test]
    public function une_parcelle_d_un_autre_producteur_est_refusee(): void
    {
        $ailleurs = Parcelle::factory()->create();

        $this->refusAttendu(fn () => $this->demander(100_000, ['parcelle_ids' => [$ailleurs->id]]), 'n\'appartient pas');
    }

    #[Test]
    public function un_proche_de_la_direction_exige_l_accord_ecrit(): void
    {
        $this->refusAttendu(fn () => $this->demander(100_000, ['partie_liee' => true]), 'art. 17.3');

        $pret = $this->demander(100_000, ['partie_liee' => true], 'prets/accords/accord.pdf');
        $this->assertTrue($pret->partie_liee);
        $this->assertSame('prets/accords/accord.pdf', $pret->accord_ecrit);
    }

    #[Test]
    public function demandes_invalides(): void
    {
        $cloturee = Campagne::factory()->statut(StatutCampagne::Cloturee)->create();

        $this->refusAttendu(fn () => $this->demander(100_000, ['campagne_id' => $cloturee->id]), 'clôturée');
        $this->refusAttendu(fn () => $this->demander(0), 'supérieur à zéro');
        $this->refusAttendu(fn () => $this->demander(100_000, ['echeance' => Carbon::yesterday()]), 'échéance');
        $this->refusAttendu(fn () => $this->demander(100_000, ['producteur_id' => Producteur::factory()->create(['actif' => false])->id]), 'désactivé');

        $this->assertSame(0, Pret::count());
    }

    #[Test]
    public function les_references_se_suivent(): void
    {
        $this->assertSame('LYPR-000001', $this->demander()->reference);
        $this->assertSame('LYPR-000002', $this->demander()->reference);
    }

    #[Test]
    public function kilos_attendus_arrondis_au_gramme_en_entiers(): void
    {
        $this->assertSame(7_500_000, Prets::grammesAttendus(3_000_000, 400));
        // 1 000 ÷ 3 = 333,333… kg → 333 333 g.
        $this->assertSame(333_333, Prets::grammesAttendus(1_000, 3));
        // 2 000 ÷ 3 = 666,666… kg → 666 667 g (au plus proche).
        $this->assertSame(666_667, Prets::grammesAttendus(2_000, 3));
    }
}
