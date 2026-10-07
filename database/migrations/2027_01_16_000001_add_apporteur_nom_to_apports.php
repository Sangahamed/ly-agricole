<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investisseur sans compte de connexion (2026-10-07) : l'apport porte son nom. Il compte
 * dans les parts et le résultat comme un investisseur à compte. LY = ni compte ni nom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apports', function (Blueprint $table) {
            $table->string('apporteur_nom', 150)->nullable()->after('investisseur_id');
        });
    }

    public function down(): void
    {
        Schema::table('apports', function (Blueprint $table) {
            $table->dropColumn('apporteur_nom');
        });
    }
};
