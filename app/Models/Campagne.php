<?php

namespace App\Models;

use App\Enums\StatutCampagne;
use App\Exceptions\OperationRefusee;
use App\Models\Concerns\Journalise;
use Database\Factories\CampagneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Saison de commercialisation d'un produit (code `2026-2027`). Le prix officiel
 * bord-champ est propre à chaque campagne : jamais une constante du code.
 *
 * @property int $id
 * @property int $produit_id
 * @property string $code
 * @property Carbon $debut
 * @property Carbon $fin
 * @property StatutCampagne $statut
 * @property int|null $prix_officiel_kg_fcfa
 * @property-read Produit $produit
 */
#[Fillable(['produit_id', 'code', 'debut', 'fin', 'statut', 'prix_officiel_kg_fcfa'])]
class Campagne extends Model
{
    /** @use HasFactory<CampagneFactory> */
    use HasFactory, Journalise;

    /** @return BelongsTo<Produit, $this> */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * Date de fin passée (le jour de fin compte encore) ou campagne clôturée : plus d'argent
     * qui entre (apports), plus de prêt ni d'achat. La clôture formelle reste une décision
     * (résultat, partage) ; ventes, encaissements, dépenses et remboursements continuent.
     */
    public function estTerminee(?Carbon $au = null): bool
    {
        return $this->statut === StatutCampagne::Cloturee || ($au ?? Carbon::now())->gt($this->fin->copy()->endOfDay());
    }

    /**
     * Refus quand l'opération tombe après la fin (ou campagne clôturée). On compare la DATE
     * DE L'OPÉRATION, pas celle de l'envoi : un achat fait hors ligne avant la fin et
     * synchronisé après reste accepté. `$quoi` = « apport », « prêt », « achat ».
     */
    public function exigerEnCours(string $quoi, ?Carbon $au = null): void
    {
        if ($this->estTerminee($au)) {
            throw new OperationRefusee("La campagne {$this->code} est terminée (fin le {$this->fin->format('d/m/Y')}) : plus de nouvel {$quoi}.");
        }
    }

    protected function casts(): array
    {
        return [
            'debut' => 'date',
            'fin' => 'date',
            'statut' => StatutCampagne::class,
            'prix_officiel_kg_fcfa' => 'integer',
        ];
    }
}
