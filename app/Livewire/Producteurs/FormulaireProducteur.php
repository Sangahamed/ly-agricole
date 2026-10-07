<?php

namespace App\Livewire\Producteurs;

use App\Enums\ActionJournal;
use App\Enums\OperateurMobileMoney;
use App\Enums\Sexe;
use App\Enums\TypePiece;
use App\Models\GroupeProducteur;
use App\Models\Langue;
use App\Models\Producteur;
use App\Models\Village;
use App\Services\DetectionDoublons;
use App\Services\Journal;
use App\Support\Fichiers;
use App\Support\Telephone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Création et modification d'une fiche producteur au bureau. (Sur le terrain, la
 * fiche sera créée hors ligne par l'appli, semaine 8 ; mêmes règles côté serveur.)
 */
class FormulaireProducteur extends Component
{
    use WithFileUploads;

    public const PHOTO_MAX_KO = 4096;

    /** null = création. */
    #[Locked]
    public ?string $producteurId = null;

    public string $nom = '';

    public string $prenoms = '';

    public string $sexe = '';

    public string $anneeNaissance = '';

    public string $telephone = '';

    public string $numeroMobileMoney = '';

    public string $operateurMm = '';

    public string $pieceType = '';

    public string $pieceNumero = '';

    public string $villageId = '';

    public string $groupeId = '';

    /** Langue des messages (question 12) ; vide = français. */
    public string $langueId = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    /** Consentement du producteur, obligatoire à la création (loi 2013-450). */
    public bool $consentement = false;

    /** @var list<string> Alertes de doublon affichées, à confirmer avant d'enregistrer. */
    public array $alertesDoublons = [];

    public bool $doublonsConfirmes = false;

    public function mount(?Producteur $producteur = null): void
    {
        $this->authorize('gerer-producteurs');

        if ($producteur?->exists) {
            $this->producteurId = $producteur->id;
            $this->nom = $producteur->nom;
            $this->prenoms = $producteur->prenoms;
            $this->sexe = $producteur->sexe->value ?? '';
            $this->anneeNaissance = (string) ($producteur->annee_naissance ?? '');
            $this->telephone = $producteur->telephone ?? '';
            $this->numeroMobileMoney = $producteur->numero_mobile_money ?? '';
            $this->operateurMm = $producteur->operateur_mm->value ?? '';
            $this->pieceType = $producteur->piece_type->value ?? '';
            $this->pieceNumero = $producteur->piece_numero ?? '';
            $this->villageId = (string) $producteur->village_id;
            $this->groupeId = (string) ($producteur->groupe_id ?? '');
            $this->langueId = (string) ($producteur->langue_id ?? '');
        }
    }

    /** Changer un champ sensible annule une confirmation de doublon déjà donnée. */
    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['telephone', 'numeroMobileMoney', 'pieceType', 'pieceNumero'], true)) {
            $this->alertesDoublons = [];
            $this->doublonsConfirmes = false;
        }

        if ($propriete === 'villageId') {
            $this->groupeId = '';
        }

        // Dire tout de suite qu'un fichier n'est pas une photo, pas au moment d'enregistrer.
        if ($propriete === 'photo') {
            $this->validateOnly('photo', ['photo' => $this->reglePhoto()]);
        }
    }

    /** @return list<string> */
    private function reglePhoto(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_MAX_KO];
    }

    public function enregistrer(): void
    {
        $this->authorize('gerer-producteurs');

        $existant = $this->producteurId ? Producteur::findOrFail($this->producteurId) : null;

        $this->telephone = (string) Telephone::normaliser($this->telephone);
        $this->numeroMobileMoney = (string) Telephone::normaliser($this->numeroMobileMoney);
        $this->pieceNumero = mb_strtoupper(trim($this->pieceNumero));

        $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'prenoms' => ['required', 'string', 'max:255'],
            'sexe' => ['nullable', Rule::enum(Sexe::class)],
            'anneeNaissance' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'telephone' => ['nullable', Telephone::REGLE],
            'numeroMobileMoney' => ['nullable', Telephone::REGLE, 'required_with:operateurMm'],
            'operateurMm' => ['nullable', Rule::enum(OperateurMobileMoney::class), 'required_with:numeroMobileMoney'],
            'pieceType' => ['nullable', Rule::enum(TypePiece::class), 'required_with:pieceNumero'],
            'pieceNumero' => ['nullable', 'string', 'max:50', 'required_with:pieceType'],
            'villageId' => ['required', 'integer', Rule::exists('villages', 'id')],
            'groupeId' => ['nullable', 'integer',
                Rule::exists('groupes_producteurs', 'id')->where('village_id', $this->villageId ?: null)],
            'langueId' => ['nullable', 'integer', Rule::exists('langues', 'id')->where('actif', true)],
            'photo' => $this->reglePhoto(),
            'consentement' => $existant ? [] : ['accepted'],
        ], [
            'consentement.accepted' => 'Sans l\'accord du producteur, sa fiche ne peut pas être créée.',
            'telephone.regex' => 'Le téléphone doit avoir 10 chiffres (ex. 07 01 02 03 04).',
            'numeroMobileMoney.regex' => 'Le numéro Mobile Money doit avoir 10 chiffres.',
        ], [
            'nom' => 'nom',
            'prenoms' => 'prénoms',
            'anneeNaissance' => 'année de naissance',
            'telephone' => 'téléphone',
            'numeroMobileMoney' => 'numéro Mobile Money',
            'operateurMm' => 'opérateur Mobile Money',
            'pieceType' => 'type de pièce',
            'pieceNumero' => 'numéro de pièce',
            'villageId' => 'village',
            'groupeId' => 'groupe',
            'langueId' => 'langue',
        ]);

        $doublons = DetectionDoublons::verifier([
            'piece_type' => $this->pieceType ?: null,
            'piece_numero' => $this->pieceNumero ?: null,
            'telephone' => $this->telephone ?: null,
            'numero_mobile_money' => $this->numeroMobileMoney ?: null,
        ], $existant?->id);

        if ($doublons['bloquants'] !== []) {
            throw ValidationException::withMessages(['pieceNumero' => $doublons['bloquants']]);
        }

        if ($doublons['alertes'] !== [] && ! $this->doublonsConfirmes) {
            $this->alertesDoublons = $doublons['alertes'];

            return;
        }

        $attributs = [
            'nom' => trim($this->nom),
            'prenoms' => trim($this->prenoms),
            'sexe' => $this->sexe ?: null,
            'annee_naissance' => $this->anneeNaissance === '' ? null : (int) $this->anneeNaissance,
            'telephone' => $this->telephone ?: null,
            'numero_mobile_money' => $this->numeroMobileMoney ?: null,
            'operateur_mm' => $this->operateurMm ?: null,
            'piece_type' => $this->pieceType ?: null,
            'piece_numero' => $this->pieceNumero ?: null,
            'village_id' => (int) $this->villageId,
            'groupe_id' => $this->groupeId === '' ? null : (int) $this->groupeId,
            'langue_id' => $this->langueId === '' ? null : (int) $this->langueId,
        ];

        $anciennePhoto = $existant?->photo;
        $nouvellePhoto = $this->photo?->store('producteurs/photos', Fichiers::disque());
        if ($nouvellePhoto !== null) {
            $attributs['photo'] = $nouvellePhoto;
        }

        try {
            $producteur = $this->ecrire($existant, $attributs, $doublons['alertes']);
        } catch (\Throwable $e) {
            // Pas de photo orpheline si la fiche n'a pas été écrite.
            if ($nouvellePhoto !== null) {
                Storage::disk(Fichiers::disque())->delete($nouvellePhoto);
            }
            throw $e;
        }

        // Minimisation : l'ancienne photo d'identité ne reste pas sur le disque.
        if ($nouvellePhoto !== null && $anciennePhoto !== null) {
            Storage::disk(Fichiers::disque())->delete($anciennePhoto);
        }

        session()->flash('statut', $existant ? 'Fiche modifiée.' : "Fiche créée : {$producteur->code}.");
        $this->redirectRoute('producteurs.fiche', $producteur);
    }

    /**
     * @param  array<string, mixed>  $attributs
     * @param  list<string>  $alertes
     */
    private function ecrire(?Producteur $existant, array $attributs, array $alertes): Producteur
    {
        return DB::transaction(function () use ($existant, $attributs, $alertes) {
            if ($existant) {
                $existant->update($attributs);
                $producteur = $existant;
            } else {
                $producteur = Producteur::create($attributs + [
                    'consentement_at' => now(),
                    'consentement_par' => auth()->id(),
                    'cree_par' => auth()->id(),
                ]);
            }

            // Un doublon accepté en connaissance de cause laisse une trace à part :
            // c'est ce qu'on relira si un prête-nom est découvert.
            if ($alertes !== []) {
                Journal::enregistrer(ActionJournal::DoublonConfirme, $producteur, apres: ['alertes' => $alertes]);
            }

            return $producteur;
        });
    }

    public function render(): View
    {
        return view('livewire.producteurs.formulaire-producteur', [
            'villages' => Village::query()->with('zone')->where('actif', true)->orderBy('nom')->get(),
            'groupes' => $this->villageId === ''
                ? collect()
                : GroupeProducteur::query()->where('village_id', (int) $this->villageId)->where('actif', true)->orderBy('nom')->get(),
            'langues' => Langue::query()->where('actif', true)->orderBy('nom')->get(),
            'sexes' => Sexe::cases(),
            'operateurs' => OperateurMobileMoney::cases(),
            'typesPiece' => TypePiece::cases(),
        ])->title($this->producteurId ? 'Modifier un producteur' : 'Nouveau producteur');
    }
}
