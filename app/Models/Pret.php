<?php

namespace App\Models;

use App\Enums\FormePret;
use App\Enums\StatutPret;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Prêt de campagne. Écrire via App\Services\Prets (validations, plafonds,
 * décaissements). Pas d'intérêt ni de marge tant que la question 4 est ouverte :
 * le montant dû est le montant prêté.
 *
 * @property string $id
 * @property string $reference
 * @property string $producteur_id
 * @property int $campagne_id
 * @property int $montant_fcfa
 * @property FormePret $forme
 * @property int|null $prix_reference_kg_fcfa
 * @property int|null $grammes_attendus
 * @property Carbon $echeance
 * @property StatutPret $statut
 * @property int $validations_requises
 * @property bool $partie_liee
 * @property string|null $accord_ecrit
 * @property string|null $motif_refus
 * @property int $cree_par
 * @property Carbon|null $valide_at
 * @property int|null $annule_par
 * @property Carbon|null $annule_at
 * @property string|null $motif_annulation
 * @property-read Producteur $producteur
 * @property-read Campagne $campagne
 * @property-read User $auteur
 * @property-read User|null $annuleur
 */
#[Fillable([
    'id', 'reference', 'producteur_id', 'campagne_id', 'montant_fcfa', 'forme', 'prix_reference_kg_fcfa',
    'grammes_attendus', 'echeance', 'statut', 'validations_requises', 'partie_liee', 'accord_ecrit',
    'motif_refus', 'motif_cloture', 'cree_par', 'valide_at', 'annule_par', 'annule_at', 'motif_annulation',
])]
class Pret extends Model
{
    use HasUuids, Journalise;

    public const PREFIXE_REFERENCE = 'LYPR-';

    protected static function booted(): void
    {
        static::creating(function (Pret $pret) {
            $pret->reference ??= self::PREFIXE_REFERENCE.str_pad((string) Compteur::suivant('pret'), 6, '0', STR_PAD_LEFT);
        });
    }

    /** Argent versé : décaissements dont le paiement n'a pas été contre-passé. */
    public function montantDecaisse(): int
    {
        return (int) $this->decaissements()
            ->whereDoesntHave('mouvement.contrePassation')
            ->sum('montant_fcfa');
    }

    /**
     * Valeur des intrants remis à crédit : les distributions sont en négatif, leurs
     * contre-passations en positif ; la somme les compense d'elle-même.
     */
    public function valeurIntrantsRemis(): int
    {
        return -(int) $this->mouvementsIntrants()->sum('valeur_fcfa');
    }

    /** Tout ce que le producteur a reçu au titre de ce prêt : argent + intrants. */
    public function montantRemis(): int
    {
        return $this->montantDecaisse() + $this->valeurIntrantsRemis();
    }

    public function resteARemettre(): int
    {
        return $this->montant_fcfa - $this->montantRemis();
    }

    /** Σ remboursements (les contre-passations sont en négatif et se compensent). */
    public function montantRembourse(): int
    {
        return (int) $this->remboursements()->sum('montant_fcfa');
    }

    /**
     * Restant dû = ce qui a été remis − remboursements. Pas d'intérêt (question 4) :
     * on ne doit que ce qu'on a reçu. Jamais négatif (invariant 1) : le service refuse
     * un remboursement qui le dépasserait.
     */
    public function restantDu(): int
    {
        return $this->montantRemis() - $this->montantRembourse();
    }

    /** @return HasMany<Remboursement, $this> */
    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class);
    }

    /** Surface relevée des parcelles financées (m²) ; null si aucune n'est relevée. */
    public function surfaceFinanceeM2(): ?int
    {
        $surfaces = $this->parcelles->pluck('surface_m2')->filter(fn ($s) => $s !== null);

        return $surfaces->isEmpty() ? null : (int) $surfaces->sum();
    }

    /** @return BelongsTo<Producteur, $this> */
    public function producteur(): BelongsTo
    {
        return $this->belongsTo(Producteur::class);
    }

    /** @return BelongsTo<Campagne, $this> */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /** @return BelongsTo<User, $this> */
    public function annuleur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    /** @return HasMany<ValidationPret, $this> */
    public function validations(): HasMany
    {
        return $this->hasMany(ValidationPret::class);
    }

    /** @return HasMany<Decaissement, $this> */
    public function decaissements(): HasMany
    {
        return $this->hasMany(Decaissement::class);
    }

    /** @return HasMany<MouvementIntrant, $this> */
    public function mouvementsIntrants(): HasMany
    {
        return $this->hasMany(MouvementIntrant::class);
    }

    /** @return BelongsToMany<Parcelle, $this> */
    public function parcelles(): BelongsToMany
    {
        return $this->belongsToMany(Parcelle::class, 'pret_parcelle');
    }

    protected function casts(): array
    {
        return [
            'forme' => FormePret::class,
            'statut' => StatutPret::class,
            'montant_fcfa' => 'integer',
            'prix_reference_kg_fcfa' => 'integer',
            'grammes_attendus' => 'integer',
            'validations_requises' => 'integer',
            'partie_liee' => 'boolean',
            'echeance' => 'date',
            'valide_at' => 'datetime',
            'annule_at' => 'datetime',
        ];
    }
}
