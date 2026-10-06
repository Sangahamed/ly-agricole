<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulation d'un prêt ou d'une vente par son auteur ou la direction (2026-10-06) : la ligne
 * reste, marquée annulée, avec qui, quand et pourquoi ; ses effets sont contre-passés.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['prets', 'ventes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('annule_par')->nullable()->constrained('users');
                $table->timestamp('annule_at')->nullable();
                $table->string('motif_annulation')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['prets', 'ventes'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('annule_par');
                $table->dropColumn(['annule_at', 'motif_annulation']);
            });
        }
    }
};
