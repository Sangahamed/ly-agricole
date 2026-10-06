<?php

namespace Tests\Feature\Utilisateurs;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Premier compte d'une base neuve, ouvert depuis le serveur (ly:creer-compte). */
class CreerCompteTest extends TestCase
{
    use RefreshDatabase;

    private function lancer(string $role, string $motDePasse, string $confirmation, string $email = 'direction@ylagro.com'): PendingCommand
    {
        return $this->artisan('ly:creer-compte', ['--role' => $role])
            ->expectsQuestion('Nom', 'Direction LY')
            ->expectsQuestion('Adresse e-mail', $email)
            ->expectsQuestion('Mot de passe (au moins 8 caractères)', $motDePasse)
            ->expectsQuestion('Mot de passe, encore une fois', $confirmation);
    }

    #[Test]
    public function un_compte_direction_est_cree_actif_avec_un_mot_de_passe_hache(): void
    {
        $this->lancer('direction', 'secret-solide', 'secret-solide')->assertSuccessful();

        $user = User::where('email', 'direction@ylagro.com')->sole();
        $this->assertSame(Role::Direction, $user->role);
        $this->assertTrue($user->actif);
        $this->assertNotSame('secret-solide', $user->password);
        $this->assertTrue(Hash::check('secret-solide', $user->password));
    }

    #[Test]
    public function une_confirmation_differente_ne_cree_rien(): void
    {
        $this->lancer('admin', 'secret-solide', 'secret-autre')->assertFailed();

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function un_mot_de_passe_trop_court_ne_cree_rien(): void
    {
        $this->lancer('admin', 'court', 'court')->assertFailed();

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function une_adresse_deja_prise_ne_cree_rien(): void
    {
        User::factory()->create(['email' => 'direction@ylagro.com']);

        $this->lancer('direction', 'secret-solide', 'secret-solide')->assertFailed();

        $this->assertSame(1, User::count());
    }

    #[Test]
    public function seuls_admin_et_direction_s_ouvrent_depuis_le_serveur(): void
    {
        $this->artisan('ly:creer-compte', ['--role' => 'agent'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
