<?php

namespace App\Models;

use App\Enums\StatutVente;
use App\Enums\TypeAcheteur;
use App\Models\Concerns\Journalise;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Revente d'un lot (cahier §7, stade Revente). Écrire via App\Services\Ventes : seuil de
 * validation, sortie de stock à l'exécution. L'encaissement est séparé (stade suivant) :
 * une vente peut être payée à la livraison ou à terme (question ouverte n° 15).
 *
 * @property string $id
 * @property string $reference
 * @property int $campagne_id
 * @property int $lot_id
 * @property TypeAcheteur $type_acheteur
 * @property string $acheteur_nom
 * @property Carbon $date_vente
 * @property int $poids_net_g
 * @property int $prix_kg_fcfa
 * @property int $montant_fcfa
 * @property string|null $qualite_acceptee
 * @property string|null $facture
 * @property StatutVente $statut
 * @property int $cree_par
 * @property int|null $valide_par
 * @property Carbon|null $valide_at
 * @property string|null $motif_refus
 * @property int|null $annule_par
 * @property Carbon|null $annule_at
 * @property string|null $motif_annulation
 * @property-read Campagne $campagne
 * @property-read Lot $lot
 * @property-read User $auteur
 * @property-read User|null $annuleur
 * @property-read User|null $validateur
 * @property-read HasMany<Encaissement, $this> $encaissements
 */
#[Fillable([
    'id', 'campagne_id', 'lot_id', 'type_acheteur', 'acheteur_nom', 'date_vente', 'poids_net_g',
    'prix_kg_fcfa', 'montant_fcfa', 'qualite_acceptee', 'facture', 'statut', 'cree_par',
    'valide_par', 'valide_at', 'motif_refus', 'annule_par', 'annule_at', 'motif_annulation',
])]
class Vente extends Model
{
    use HasUuids, Journalise;

    public const PREFIXE_REFERENCE = 'VTE-';

    protected static function booted(): void
    {
        static::creating(function (Vente $vente) {
            $vente->reference ??= self::PREFIXE_REFERENCE.str_pad((string) Compteur::suivant('vente'), 6, '0', STR_PAD_LEFT);
        });
    }

    /** Encaissé : somme des lignes, une contre-passation (négative) se compense d'elle-même. */
    public function encaisse(): int
    {
        return (int) $this->encaissements()->sum('montant_fcfa');
    }

    public function resteAEncaisser(): int
    {
        return $this->montant_fcfa - $this->encaisse();
    }

    /** @return BelongsTo<Campagne, $this> */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return HasMany<Encaissement, $this> */
    public function encaissements(): HasMany
    {
        return $this->hasMany(Encaissement::class);
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

    /** @return BelongsTo<User, $this> */
    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    protected function casts(): array
    {
        return [
            'type_acheteur' => TypeAcheteur::class,
            'statut' => StatutVente::class,
            'date_vente' => 'datetime',
            'valide_at' => 'datetime',
            'annule_at' => 'datetime',
            'poids_net_g' => 'integer',
            'prix_kg_fcfa' => 'integer',
            'montant_fcfa' => 'integer',
        ];
    }
}
