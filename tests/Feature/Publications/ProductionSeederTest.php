<?php

namespace Tests\Feature\Publications;

use App\Enums\Role;
use App\Models\PrixMarche;
use App\Models\Produit;
use App\Models\User;
use Database\Seeders\CulturesSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Base de production : cultures et prix publiés des campagnes passées, sans aucun compte. */
class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sans_compte_direction_rien_n_est_seme(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->seed(ProductionSeeder::class);
        } finally {
            $this->assertSame(0, Produit::count());
        }
    }

    #[Test]
    public function seme_cultures_et_prix_sans_creer_de_compte_ni_de_doublon(): void
    {
        User::factory()->role(Role::Direction)->create();

        $this->seed(ProductionSeeder::class);

        $this->assertSame(count(CulturesSeeder::CULTURES), Produit::count());
        $this->assertGreaterThan(0, $prix = PrixMarche::count());
        $this->assertSame(1, User::count());

        $this->seed(ProductionSeeder::class);

        $this->assertSame(count(CulturesSeeder::CULTURES), Produit::count());
        $this->assertSame($prix, PrixMarche::count());
    }
}
