<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_unites', function (Blueprint $table) {
            $table->decimal('prix', 20, 8)->nullable()->after('facteur');
            $table->foreignId('id_devise')->nullable()->after('prix')->constrained('devises')->nullOnDelete();
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('seuil_alerte', 20, 8)->default(0)->after('categorie_id');
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->unsignedBigInteger('id_approvisionnement')->nullable()->change();
            $table->unsignedBigInteger('id_ligne_approvisionnement')->nullable()->change();
            $table->decimal('prix_unitaire_stock', 20, 8)->nullable()->after('quantite_initial');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn('prix_unitaire_stock');
            $table->unsignedBigInteger('id_approvisionnement')->nullable(false)->change();
            $table->unsignedBigInteger('id_ligne_approvisionnement')->nullable(false)->change();
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('seuil_alerte');
        });

        Schema::table('produit_unites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_devise');
            $table->dropColumn('prix');
        });
    }
};