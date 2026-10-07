<?php

/*
 * Seule la partie « envois de fichiers » est réglée ici ; le reste garde les valeurs de
 * Livewire. Sur Vercel, le disque du serveur est en lecture seule et change d'une requête à
 * l'autre : le fichier envoyé doit attendre sur R2 (LIVEWIRE_TMP_DISK=r2), pas sur le disque.
 */
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TMP_DISK') ?: null,
        'rules' => null,
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
