<?php

namespace App\Support;

/**
 * Disque des fichiers de l'application (justificatifs, reçus, accords écrits, photos) :
 * `local` sur un poste, `r2` (Cloudflare) en production — Vercel n'écrit pas sur son disque.
 * Réglé par FICHIERS_DISK (config/filesystems.php).
 */
final class Fichiers
{
    public static function disque(): string
    {
        return (string) config('filesystems.fichiers', 'local');
    }
}
