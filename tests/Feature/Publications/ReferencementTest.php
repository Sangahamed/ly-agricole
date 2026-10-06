<?php

namespace Tests\Feature\Publications;

use App\Enums\Role;
use App\Models\Actualite;
use App\Models\Produit;
use App\Models\User;
use App\Services\Publications;
use App\Services\Referencement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Ce que lisent les moteurs et les assistants IA : uniquement des prix réellement publiés. */
class ReferencementTest extends TestCase
{
    use RefreshDatabase;

    private User $direction;

    private Produit $anacarde;

    private Produit $tomate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->direction = User::factory()->role(Role::Direction)->create();
        $this->anacarde = Produit::query()->create(['code' => 'anacarde', 'nom' => 'Anacarde', 'actif' => true]);
        $this->tomate = Produit::query()->create(['code' => 'tomate', 'nom' => 'Tomate', 'actif' => true]);
        Publications::publierPrix($this->anacarde, 425, Carbon::parse('2025-01-17'), 'Communiqué 2025', null, null, $this->direction);
        Publications::publierPrix($this->anacarde, 400, Carbon::parse('2026-02-06'), 'Communiqué 2026', 'https://exemple.ci/2026', null, $this->direction);
    }

    #[Test]
    public function le_plan_du_site_liste_les_pages_publiques_et_un_lien_par_produit_ayant_un_prix(): void
    {
        $actualite = Actualite::query()->create(['titre' => 'Ouverture de campagne', 'contenu' => 'Texte', 'publie' => true, 'publie_le' => Carbon::yesterday(), 'cree_par' => $this->direction->id]);
        Actualite::query()->create(['titre' => 'Brouillon', 'contenu' => 'Texte', 'publie' => false, 'cree_par' => $this->direction->id]);

        $reponse = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $xml = simplexml_load_string($reponse->getContent());
        $adresses = [];
        foreach ($xml->url as $url) {
            $adresses[] = (string) $url->loc;
        }

        $this->assertContains(route('accueil'), $adresses);
        $this->assertContains(route('prix.evolution'), $adresses);
        $this->assertContains(route('prix.evolution', ['produit' => $this->anacarde->id]), $adresses);
        $this->assertNotContains(route('prix.evolution', ['produit' => $this->tomate->id]), $adresses);
        $this->assertContains(route('actualites.voir', $actualite), $adresses);
        $this->assertCount(5, $adresses);
    }

    #[Test]
    public function si_une_lecture_echoue_le_plan_reste_un_xml_valide_sans_dates(): void
    {
        $this->withoutExceptionHandling();
        Schema::drop('actualites');

        $reponse = $this->get('/sitemap.xml')->assertOk();
        $xml = simplexml_load_string($reponse->getContent());

        $this->assertNotFalse($xml);
        $this->assertCount(3, $xml->url);
        $this->assertSame(route('accueil'), (string) $xml->url[0]->loc);
    }

    #[Test]
    public function les_dates_du_plan_sont_celles_des_derniers_prix_publies(): void
    {
        $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());

        $this->assertSame(now()->toDateString(), (string) $xml->url[0]->lastmod);
    }

    #[Test]
    public function robots_annonce_le_plan_et_ecarte_les_zones_techniques(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Sitemap: '.route('referencement.plan'), false)
            ->assertSee('Disallow: /cron/', false)
            ->assertSee('Disallow: /livewire/', false)
            ->assertDontSee('Disallow: /'."\n", false);
    }

    #[Test]
    public function llms_donne_le_dernier_prix_publie_avec_sa_source(): void
    {
        $this->get('/llms.txt')->assertOk()
            ->assertSee('# LY AGRICOLE', false)
            ->assertSee('Anacarde', false)
            ->assertSee('400 FCFA/kg, depuis le 2026-02-06 (source : Communiqué 2026)', false)
            ->assertDontSee('425 FCFA/kg', false)
            ->assertDontSee('Tomate', false);
    }

    #[Test]
    public function la_page_d_un_produit_a_son_titre_sa_description_chiffree_et_son_adresse_officielle(): void
    {
        $reponse = $this->get(route('prix.evolution', ['produit' => $this->anacarde->id, 'periode' => '6m']))->assertOk();

        $reponse->assertSee('<title>Prix bord-champ Anacarde en Côte d&#039;Ivoire — LY AGRICOLE</title>', false);
        $reponse->assertSee('Prix bord-champ Anacarde en Côte d&#039;Ivoire : 400 FCFA/kg depuis le 6 février 2026', false);
        // Sans la période : une seule adresse par produit pour les moteurs.
        $reponse->assertSee('<link rel="canonical" href="'.route('prix.evolution', ['produit' => $this->anacarde->id]).'">', false);
    }

    #[Test]
    public function un_produit_sans_prix_n_a_jamais_de_chiffre_invente(): void
    {
        $this->get(route('prix.evolution'))->assertOk()
            ->assertSee('anacarde 400 FCFA/kg', false)
            ->assertSee('<link rel="canonical" href="'.route('prix.evolution').'">', false);

        $this->assertStringNotContainsString('FCFA', Referencement::descriptionPrix($this->tomate));
    }

    #[Test]
    public function l_accueil_porte_les_donnees_structurees_de_l_organisation(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<script type="application/ld+json">', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('<link rel="canonical" href="'.route('accueil').'">', false);
    }
}
