<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;

/**
 * Partage du résultat net d'une campagne entre les investisseurs et LY AGRICOLE :
 * contrat de campagne, articles 12 (bénéfice), 13 (pertes) et 14 (exemples, repris en
 * tests). Service PUR : il ne lit aucune table, il calcule à partir de ce qu'on lui
 * donne. D'où vient le résultat net (art. 10 et 11) est l'affaire de ResultatCampagne.
 *
 * Tout en FCFA entiers (D4), jamais de float. Le contrat ne dit pas comment arrondir :
 * - la part globale des investisseurs est arrondie au franc le plus proche (moitié vers
 *   le haut) et celle de LY est le RESTE : aucun franc n'est créé ni perdu ;
 * - entre investisseurs, la part se répartit au prorata de leur montant investi par la
 *   méthode du plus fort reste : la somme des parts est exactement la part globale.
 *   Égalité de reste : l'investisseur au plus petit identifiant passe en premier.
 * (Question ouverte n° 32 : à valider par le responsable projet.)
 */
final class PartageResultat
{
    /** Art. 12.1 : 40 % du bénéfice à l'ensemble des investisseurs, 60 % à LY. */
    public const PART_INVESTISSEURS_POUR_CENT = 40;

    public const BENEFICE = 'benefice';

    public const PERTE = 'perte';

    public const NUL = 'nul';

    /**
     * @param  int  $resultat  résultat net de la campagne (négatif = perte)
     * @param  array<int|string, int>  $investis  clé de l'investisseur (id du compte, ou « nom:… » sans compte) => montant effectivement investi (> 0)
     * @param  int  $apportLy  apport propre de LY sur les fonds de la campagne (art. 7), ≥ 0
     * @param  bool  $fauteLy  art. 13.4 : perte due à une faute de gestion, une fraude ou une utilisation non
     *                         conforme des fonds ⇒ supportée par LY seule. Décision à prendre par la direction, jamais déduite.
     * @return array{
     *     sens: string,
     *     collecte: int,
     *     apport_ly: int,
     *     fonds: int,
     *     part_investisseurs: int,
     *     part_ly: int,
     *     non_impute: int,
     *     faute_ly: bool,
     *     lignes: array<int|string, array{investi: int, part: int, somme_due: int}>
     * } `part` : quote-part de bénéfice (sens « benefice ») ou part de perte (sens « perte »), toujours ≥ 0.
     *   `non_impute` : partie de la perte qui dépasse ce que les investisseurs ont investi, qu'on ne peut pas leur
     *   faire supporter (aucun investisseur ne doit de l'argent) ; le contrat ne dit pas qui la supporte.
     *
     * @throws OperationRefusee
     */
    public static function calculer(int $resultat, array $investis, int $apportLy, bool $fauteLy = false): array
    {
        if ($apportLy < 0) {
            throw new OperationRefusee('L\'apport de LY ne peut pas être négatif.');
        }
        foreach ($investis as $id => $montant) {
            if ($montant <= 0) {
                throw new OperationRefusee("Le montant investi par l'investisseur #{$id} doit être supérieur à zéro.");
            }
        }
        $collecte = array_sum($investis);
        if ($collecte <= 0) {
            throw new OperationRefusee('Aucun apport d\'investisseur : rien à partager (art. 12).');
        }
        ksort($investis);
        $fonds = $collecte + $apportLy;

        if ($resultat === 0) {
            return self::rendre(self::NUL, $collecte, $apportLy, 0, 0, 0, $fauteLy, array_map(fn (int $i) => 0, $investis), $investis);
        }

        if ($resultat > 0) {
            // Art. 12.1 : 40 % à l'enveloppe des investisseurs, le reste (60 %) à LY.
            $enveloppe = self::pourcentage($resultat, self::PART_INVESTISSEURS_POUR_CENT);
            $parts = self::repartir($enveloppe, $investis);

            return self::rendre(self::BENEFICE, $collecte, $apportLy, $enveloppe, $resultat - $enveloppe, 0, false, $parts, $investis);
        }

        $perte = -$resultat;
        if ($fauteLy) {
            // Art. 13.4 : LY supporte seule, les investisseurs reprennent tout leur capital.
            return self::rendre(self::PERTE, $collecte, $apportLy, 0, $perte, 0, true, array_map(fn (int $i) => 0, $investis), $investis);
        }

        // Art. 13.2 : perte × (collecté ÷ fonds de la campagne) pour les investisseurs,
        // le reste sur l'apport propre de LY.
        $partInvestisseurs = self::proportion($perte, $collecte, $fonds);
        $parts = self::repartir($partInvestisseurs, $investis);

        // Un investisseur ne perd pas plus que ce qu'il a investi (art. 13.1 : « tout ou partie »).
        $nonImpute = 0;
        foreach ($parts as $id => $part) {
            if ($part > $investis[$id]) {
                $nonImpute += $part - $investis[$id];
                $parts[$id] = $investis[$id];
            }
        }

        return self::rendre(self::PERTE, $collecte, $apportLy, $partInvestisseurs - $nonImpute, $perte - $partInvestisseurs, $nonImpute, false, $parts, $investis);
    }

    /**
     * @param  array<int|string, int>  $parts
     * @param  array<int|string, int>  $investis
     * @return array{sens: string, collecte: int, apport_ly: int, fonds: int, part_investisseurs: int, part_ly: int, non_impute: int, faute_ly: bool, lignes: array<int|string, array{investi: int, part: int, somme_due: int}>}
     */
    private static function rendre(string $sens, int $collecte, int $apportLy, int $partInvestisseurs, int $partLy, int $nonImpute, bool $fauteLy, array $parts, array $investis): array
    {
        $lignes = [];
        foreach ($investis as $id => $investi) {
            $part = $parts[$id];
            // Art. 12.3 : capital + quote-part ; art. 13.3 : capital − part de perte.
            $lignes[$id] = ['investi' => $investi, 'part' => $part, 'somme_due' => $sens === self::BENEFICE ? $investi + $part : $investi - $part];
        }

        return [
            'sens' => $sens,
            'collecte' => $collecte,
            'apport_ly' => $apportLy,
            'fonds' => $collecte + $apportLy,
            'part_investisseurs' => $partInvestisseurs,
            'part_ly' => $partLy,
            'non_impute' => $nonImpute,
            'faute_ly' => $fauteLy,
            'lignes' => $lignes,
        ];
    }

    /** `$montant` × `$pourcent` ÷ 100, arrondi au franc le plus proche (moitié vers le haut), montant ≥ 0. */
    private static function pourcentage(int $montant, int $pourcent): int
    {
        return self::proportion($montant, $pourcent, 100);
    }

    /** `$montant` × `$numerateur` ÷ `$denominateur`, arrondi au plus proche (moitié vers le haut), tout ≥ 0. */
    private static function proportion(int $montant, int $numerateur, int $denominateur): int
    {
        if ($numerateur > 0 && $montant > intdiv(PHP_INT_MAX - $denominateur, 2 * $numerateur)) {
            throw new OperationRefusee('Montants trop grands pour être partagés sans perte de précision.');
        }

        return intdiv(2 * $montant * $numerateur + $denominateur, 2 * $denominateur);
    }

    /**
     * Répartit `$total` entre les investisseurs au prorata de `$poids`, méthode du plus fort reste.
     *
     * @param  array<int|string, int>  $poids  identifiant => montant investi (> 0), triés par identifiant
     * @return array<int|string, int>
     */
    private static function repartir(int $total, array $poids): array
    {
        $somme = array_sum($poids);
        $parts = [];
        $restes = [];
        foreach ($poids as $id => $poidsInvestisseur) {
            self::garderContreDepassement($total, $poidsInvestisseur);
            $produit = $total * $poidsInvestisseur;
            $parts[$id] = intdiv($produit, $somme);
            $restes[$id] = $produit % $somme;
        }

        $manque = $total - array_sum($parts);
        // Plus fort reste d'abord ; à égalité, plus petit identifiant (l'ordre d'entrée, déjà trié).
        $ordre = array_keys($restes);
        usort($ordre, fn (int $a, int $b) => $restes[$b] <=> $restes[$a] ?: $a <=> $b);
        foreach (array_slice($ordre, 0, $manque) as $id) {
            $parts[$id]++;
        }

        return $parts;
    }

    /** Un produit d'entiers qui dépasserait 2^63 serait faux en silence : on refuse plutôt. */
    private static function garderContreDepassement(int $a, int $b): void
    {
        if ($a > 0 && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new OperationRefusee('Montants trop grands pour être partagés sans perte de précision.');
        }
    }
}
