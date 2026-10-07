<?php

namespace App\Services\Ia;

use App\Models\PhotoTerrain;
use App\Support\Fichiers;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Appels HTTP au service ia/ par le VPN (docs/INSTALLATION_IA.md). */
class ServiceIaHttp implements ClientIa
{
    public function diagnostiquer(PhotoTerrain $photo, string $culture): array
    {
        $contenu = Storage::disk(Fichiers::disque())->get($photo->chemin);
        if ($contenu === null) {
            throw new RuntimeException('Fichier de la photo introuvable sur le disque.');
        }

        /** @var array{statut: string, classe: string|null, confiance_pour_mille: int, motif: string, modele: string} $r */
        $r = $this->requete()->attach('photo', $contenu, $photo->id.'.jpg')
            ->post($this->url('/diagnostic'), ['culture' => $culture])->throw()->json();

        return $r;
    }

    public function conseil(array $demande): array
    {
        /** @var array{statut: string, texte: string, fiches_citees: list<int>, motifs_rejet: list<string>, modele: string} $r */
        $r = $this->requete()->asJson()->post($this->url('/conseil'), $demande)->throw()->json();

        return $r;
    }

    private function requete(): PendingRequest
    {
        $jeton = config('ia.jeton');
        if (! is_string($jeton)) {
            throw new RuntimeException('Jeton du service IA non configuré (IA_JETON).');
        }

        return Http::withHeaders(['X-Jeton-Ia' => $jeton])->acceptJson()->timeout((int) config('ia.delai_secondes'));
    }

    private function url(string $chemin): string
    {
        $base = config('ia.url');
        if (! is_string($base)) {
            throw new RuntimeException('Adresse du service IA non configurée (IA_URL) : le serveur IA n\'est pas encore installé.');
        }

        return rtrim($base, '/').$chemin;
    }
}
