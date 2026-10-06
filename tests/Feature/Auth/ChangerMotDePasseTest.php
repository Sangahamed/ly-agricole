<?php

namespace Tests\Feature\Auth;

use App\Enums\ActionJournal;
use App\Livewire\Auth\ChangerMotDePasse;
use App\Models\JournalActivite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Chacun change son propre mot de passe en redonnant l'actuel. */
class ChangerMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'ancien-mdp-1']);
    }

    private function formulaire(string $actuel, string $nouveau, ?string $confirmation = null): Testable
    {
        return Livewire::actingAs($this->user)->test(ChangerMotDePasse::class)
            ->set('actuel', $actuel)
            ->set('nouveau', $nouveau)
            ->set('nouveau_confirmation', $confirmation ?? $nouveau)
            ->call('changer');
    }

    #[Test]
    public function la_page_est_reservee_aux_comptes_connectes_et_dans_le_menu(): void
    {
        $this->get('/mon-mot-de-passe')->assertRedirect(route('login'));

        $this->actingAs($this->user)->get('/mon-mot-de-passe')->assertOk()->assertSee('Mon mot de passe');
        $this->actingAs($this->user)->get('/tableau-de-bord')->assertSee(route('mot-de-passe'));
    }

    #[Test]
    public function avec_le_bon_mot_de_passe_actuel_le_nouveau_est_enregistre_et_journalise(): void
    {
        $jetonAvant = $this->user->remember_token;

        $this->formulaire('ancien-mdp-1', 'nouveau-mdp-2')->assertHasNoErrors()->assertSee('Mot de passe changé.');

        $this->user->refresh();
        $this->assertTrue(Hash::check('nouveau-mdp-2', $this->user->password));
        $this->assertNotSame($jetonAvant, $this->user->remember_token);

        $ligne = JournalActivite::where('action', ActionJournal::Modification)->where('objet_id', (string) $this->user->id)->sole();
        $this->assertSame('(masqué)', $ligne->apres['password']);
        $this->assertStringNotContainsString($this->user->password, (string) json_encode([$ligne->avant, $ligne->apres]));
    }

    #[Test]
    public function un_mauvais_mot_de_passe_actuel_ne_change_rien(): void
    {
        $this->formulaire('pas-le-bon', 'nouveau-mdp-2')->assertHasErrors('actuel');

        $this->assertTrue(Hash::check('ancien-mdp-1', $this->user->refresh()->password));
    }

    #[Test]
    public function confirmation_differente_trop_court_ou_identique_sont_refuses(): void
    {
        $this->formulaire('ancien-mdp-1', 'nouveau-mdp-2', 'autre-chose')->assertHasErrors('nouveau');
        $this->formulaire('ancien-mdp-1', 'court')->assertHasErrors('nouveau');
        $this->formulaire('ancien-mdp-1', 'ancien-mdp-1')->assertHasErrors('nouveau');

        $this->assertTrue(Hash::check('ancien-mdp-1', $this->user->refresh()->password));
    }

    #[Test]
    public function trop_d_essais_bloquent_meme_le_bon_mot_de_passe(): void
    {
        for ($i = 0; $i < ChangerMotDePasse::TENTATIVES_MAX; $i++) {
            $this->formulaire('pas-le-bon', 'nouveau-mdp-2');
        }

        $this->formulaire('ancien-mdp-1', 'nouveau-mdp-2')->assertHasErrors('actuel');
        $this->assertTrue(Hash::check('ancien-mdp-1', $this->user->refresh()->password));
    }
}
