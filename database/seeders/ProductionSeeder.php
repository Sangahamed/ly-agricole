<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Base de production : cultures et prix bord-champ PUBLIÉS des campagnes passées, chacun avec sa
 * source. Aucun compte (`php artisan ly:creer-compte`), aucune donnée fictive, aucun prix de
 * gestion : ceux-là restent des décisions du responsable projet. Relancer ne crée aucun doublon.
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        // Les prix publiés portent un auteur de la direction (journal, mentions sur la vitrine).
        if (! User::query()->where('role', Role::Direction)->where('actif', true)->exists()) {
            throw new RuntimeException('Aucun compte direction actif : lancer d\'abord php artisan ly:creer-compte --role=direction');
        }

        $this->call(CulturesSeeder::class);
        $this->call(HistoriquePrixSeeder::class);
        $this->call(HistoriquePrixCulturesSeeder::class);
    }
}
