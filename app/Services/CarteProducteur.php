<?php

namespace App\Services;

use App\Enums\ActionJournal;
use App\Models\Producteur;
use App\Support\CodeQr;
use App\Support\Fichiers;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Carte producteur (cahier des charges §10) : identification en une seconde au point
 * de collecte, pas de doublon ni de prête-nom.
 *
 * Le QR ne contient que le code de la carte, aucune donnée personnelle. La carte ne
 * porte ni téléphone ni numéro de pièce : perdue, elle expose le moins possible.
 * Chaque impression est journalisée (qui réimprime des cartes, et pour qui).
 */
class CarteProducteur
{
    public static function telecharger(Producteur $producteur): Response
    {
        $producteur->loadMissing('village.zone');

        $photo = null;
        if ($producteur->photo !== null && Storage::disk(Fichiers::disque())->exists($producteur->photo)) {
            $photo = 'data:'.Storage::disk(Fichiers::disque())->mimeType($producteur->photo).';base64,'
                .base64_encode((string) Storage::disk(Fichiers::disque())->get($producteur->photo));
        }

        $pdf = Pdf::loadView('producteurs.carte', [
            'producteur' => $producteur,
            'photo' => $photo,
            'qr' => CodeQr::pngDataUri($producteur->code),
        ])->setPaper('a4')
            // Sans sous-ensemble, la police entière est embarquée : 1,1 Mo pour une carte.
            ->setOption('isFontSubsettingEnabled', true);

        Journal::enregistrer(ActionJournal::ImpressionCarte, $producteur);

        return $pdf->stream('carte-'.$producteur->code.'.pdf');
    }
}
