<?php

namespace App\Models;

use App\Models\Builders\BuilderImmuable;
use App\Models\Concerns\Immuable;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Registre immuable 🔒 : argent apporté à une campagne (contrat art. 5 et 9), par un
 * investisseur à compte (investisseur_id), un investisseur sans compte (apporteur_nom, depuis le
 * 2026-10-07) ou LY elle-même (ni l'un ni l'autre). Montant signé : une
 * contre-passation est négative.
 *
 * @property int $id
 * @property int|null $investisseur_id
 * @property string|null $apporteur_nom
 * @property int $campagne_id
 * @property int $montant_fcfa
 * @property Carbon $date_apport
 * @property string|null $motif
 * @property int|null $mouvement_id
 * @property int|null $annule_id
 * @property int $cree_par
 * @property-read User|null $investisseur
 * @property-read Campagne $campagne
 * @property-read MouvementTresorerie|null $mouvement
 * @property-read User $auteur
 * @property-read Apport|null $contrePassation
 */
#[UseEloquentBuilder(BuilderImmuable::class)]
class Apport extends Model
{
    use Immuable, Journalise;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function estDeLy(): bool
    {
        return $this->investisseur_id === null && $this->apporteur_nom === null;
    }

    /** Nom affiché de qui a apporté : l'investisseur à compte, le nom saisi, ou LY. */
    public function nomApporteur(): string
    {
        return $this->investisseur->nom ?? $this->apporteur_nom ?? 'LY AGRICOLE (apport propre)';
    }

    /** @return BelongsTo<User, $this> */
    public function investisseur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investisseur_id');
    }

    /** @return BelongsTo<Campagne, $this> */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class);
    }

    /** @return BelongsTo<MouvementTresorerie, $this> */
    public function mouvement(): BelongsTo
    {
        return $this->belongsTo(MouvementTresorerie::class, 'mouvement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /**
     * La contre-passation de cet apport, s'il y en a une.
     *
     * @return HasOne<Apport, $this>
     */
    public function contrePassation(): HasOne
    {
        return $this->hasOne(self::class, 'annule_id');
    }

    protected function casts(): array
    {
        return [
            'montant_fcfa' => 'integer',
            'date_apport' => 'date',
        ];
    }
}
