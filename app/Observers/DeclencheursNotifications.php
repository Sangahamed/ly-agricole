<?php

namespace App\Observers;

use App\Enums\StatutAchat;
use App\Enums\StatutDepense;
use App\Enums\StatutPret;
use App\Enums\StatutVente;
use App\Models\Achat;
use App\Models\Depense;
use App\Models\Pret;
use App\Models\User;
use App\Models\Vente;
use App\Services\Notifications;
use App\Support\Format;
use Illuminate\Database\Eloquent\Model;

/**
 * Qui prévenir quand une saisie attend une validation, ou vient d'être traitée.
 * LECTURE SEULE : n'écrit jamais dans le modèle observé (achats, ventes… : registres et
 * saisies validables). Les avis partent après le commit (AvisLy::afterCommit).
 *
 * - créée « à valider » → ceux qui ont le droit de valider, sauf l'auteur ;
 * - passée « à valider » → validée ou refusée → l'auteur (sur son téléphone aussi).
 * Une saisie validée d'office (sous le seuil) ne prévient personne.
 */
class DeclencheursNotifications
{
    public function created(Model $modele): void
    {
        $a = $this->aValider($modele);
        if ($a !== null) {
            Notifications::envoyer(Notifications::ayantLeDroit($a['droit'], $a['auteur']), $a['titre'], $a['texte'], $a['url'], 'a_valider');
        }
    }

    public function updated(Model $modele): void
    {
        if (! $modele->wasChanged('statut')) {
            return;
        }

        $t = $this->traite($modele);
        $auteur = $modele->getAttribute('cree_par');
        if ($t === null || ! is_int($auteur)) {
            return;
        }

        $user = User::query()->find($auteur);
        if ($user !== null) {
            Notifications::envoyer([$user], $t['titre'], $t['texte'], $t['url'], $t['refus'] ? 'refuse' : 'valide');
        }
    }

    /** @return array{droit: string, auteur: int, titre: string, texte: string, url: string}|null */
    private function aValider(Model $m): ?array
    {
        return match (true) {
            $m instanceof Achat && $m->statut === StatutAchat::AValider => [
                'droit' => 'valider-achats', 'auteur' => $m->cree_par,
                'titre' => "Achat {$m->reference} à valider",
                'texte' => Format::kg($m->poids_net_g).' à '.Format::fcfa($m->prix_kg_fcfa).'/kg = '.Format::fcfa($m->montant_fcfa).' — '.$m->nomFournisseur(),
                'url' => route('achats', ['statut' => StatutAchat::AValider->value]),
            ],
            $m instanceof Depense && $m->statut === StatutDepense::AValider => [
                'droit' => 'valider-depenses', 'auteur' => $m->cree_par,
                'titre' => 'Dépense à valider',
                'texte' => Format::fcfa($m->montant_fcfa).' à '.$m->beneficiaire.' ('.($m->categorie->nom ?? 'sans catégorie').')',
                'url' => route('depenses'),
            ],
            $m instanceof Pret && $m->statut === StatutPret::Demande => [
                'droit' => 'valider-prets', 'auteur' => $m->cree_par,
                'titre' => "Prêt {$m->reference} à valider",
                'texte' => Format::fcfa($m->montant_fcfa).' pour '.$m->producteur->nomComplet(),
                'url' => route('prets.fiche', $m),
            ],
            $m instanceof Vente && $m->statut === StatutVente::AValider => [
                'droit' => 'valider-ventes', 'auteur' => $m->cree_par,
                'titre' => "Vente {$m->reference} à valider",
                'texte' => Format::kg($m->poids_net_g).' à '.$m->acheteur_nom.' = '.Format::fcfa($m->montant_fcfa),
                'url' => route('ventes.fiche', $m),
            ],
            default => null,
        };
    }

    /** @return array{titre: string, texte: string, url: string|null, refus: bool}|null */
    private function traite(Model $m): ?array
    {
        $avis = $this->avisTraite($m);
        if ($avis === null) {
            return null;
        }
        // Validé (achat, prêt, vente) ou payé (dépense) ; tout le reste est un refus.
        $statut = $m->getAttribute('statut');
        $avis['refus'] = ! in_array($statut instanceof \BackedEnum ? $statut->value : $statut, ['valide', 'payee'], true);

        return $avis;
    }

    /** @return array{titre: string, texte: string, url: string|null}|null */
    private function avisTraite(Model $m): ?array
    {
        $avant = $m->getOriginal('statut');
        $motif = $m->getAttribute('motif_refus');
        $refus = is_string($motif) && $motif !== '' ? " Motif : {$motif}" : '';

        // Annulé par son propre auteur : rien à lui apprendre.
        $annuleur = $m->getAttribute('annule_par');
        if ($annuleur !== null && $annuleur === $m->getAttribute('cree_par')) {
            return null;
        }

        return match (true) {
            $m instanceof Achat && $m->statut === StatutAchat::Annule => ['titre' => "Achat {$m->reference} annulé",
                'texte' => $m->nomFournisseur().'. Motif : '.$m->getAttribute('motif_annulation'), 'url' => route('achats')],
            $m instanceof Vente && $m->statut === StatutVente::Annule => ['titre' => "Vente {$m->reference} annulée",
                'texte' => $m->acheteur_nom.'. Motif : '.$m->getAttribute('motif_annulation'), 'url' => route('ventes.fiche', $m)],
            $m instanceof Pret && $m->statut === StatutPret::Annule => ['titre' => "Prêt {$m->reference} annulé",
                'texte' => $m->producteur->nomComplet().'. Motif : '.$m->getAttribute('motif_annulation'), 'url' => route('prets.fiche', $m)],
            $m instanceof Depense && $m->statut === StatutDepense::Annulee => ['titre' => 'Dépense annulée',
                'texte' => Format::fcfa($m->montant_fcfa).' à '.$m->beneficiaire.'. '.$m->getAttribute('motif_refus'), 'url' => route('depenses')],
            $m instanceof Achat && $avant === StatutAchat::AValider => $m->statut === StatutAchat::Valide
                ? ['titre' => "Achat {$m->reference} validé", 'texte' => Format::kg($m->poids_net_g).' — '.$m->nomFournisseur().'.', 'url' => route('achats')]
                : ['titre' => "Achat {$m->reference} refusé", 'texte' => $m->nomFournisseur().'.'.$refus, 'url' => route('achats')],
            $m instanceof Depense && $avant === StatutDepense::AValider => $m->statut === StatutDepense::Payee
                ? ['titre' => 'Dépense validée et payée', 'texte' => Format::fcfa($m->montant_fcfa).' à '.$m->beneficiaire.'.', 'url' => route('depenses')]
                : ['titre' => 'Dépense refusée', 'texte' => Format::fcfa($m->montant_fcfa).' à '.$m->beneficiaire.'.'.$refus, 'url' => route('depenses')],
            $m instanceof Pret && $avant === StatutPret::Demande && in_array($m->statut, [StatutPret::Valide, StatutPret::Refuse], true) => $m->statut === StatutPret::Valide
                ? ['titre' => "Prêt {$m->reference} validé", 'texte' => Format::fcfa($m->montant_fcfa).' pour '.$m->producteur->nomComplet().'.', 'url' => route('prets.fiche', $m)]
                : ['titre' => "Prêt {$m->reference} refusé", 'texte' => $m->producteur->nomComplet().'.'.$refus, 'url' => route('prets.fiche', $m)],
            $m instanceof Vente && $avant === StatutVente::AValider => $m->statut === StatutVente::Valide
                ? ['titre' => "Vente {$m->reference} validée", 'texte' => Format::kg($m->poids_net_g).' à '.$m->acheteur_nom.'.', 'url' => route('ventes.fiche', $m)]
                : ['titre' => "Vente {$m->reference} refusée", 'texte' => $m->acheteur_nom.'.'.$refus, 'url' => route('ventes.fiche', $m)],
            default => null,
        };
    }
}
