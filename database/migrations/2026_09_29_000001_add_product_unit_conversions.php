<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_unites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_produit')->constrained('produits')->cascadeOnDelete();
            $table->foreignId('id_unite')->constrained('unites')->restrictOnDelete();
            $table->enum('type', ['achat', 'vente']);
            $table->decimal('facteur', 20, 8);
            $table->timestamps();
            $table->unique(['id_produit', 'id_unite', 'type']);
            $table->index(['id_produit', 'type']);
        });

        Schema::table('ligne_approvisionnements', function (Blueprint $table) {
            $table->foreignId('id_unite')->nullable()->after('id_produit')->constrained('unites')->restrictOnDelete();
            $table->decimal('facteur_conversion', 20, 8)->default(1)->after('quantite');
            $table->decimal('quantite_stock', 20, 8)->nullable()->after('facteur_conversion');
            $table->decimal('prix_unitaire_stock', 20, 8)->nullable()->after('prix_unitaire');
            $table->decimal('prix_vente_stock', 20, 8)->nullable()->after('prix_vente');
            $table->decimal('quantite', 20, 8)->change();
        });

        Schema::table('ligne_ventes', function (Blueprint $table) {
            $table->foreignId('id_unite')->nullable()->after('id_produit')->constrained('unites')->restrictOnDelete();
            $table->decimal('facteur_conversion', 20, 8)->default(1)->after('quantite');
            $table->decimal('quantite_stock', 20, 8)->nullable()->after('facteur_conversion');
            $table->decimal('quantite', 20, 8)->change();
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->decimal('quantite_initial', 20, 8)->change();
        });

        Schema::table('mouvements_stock_fifos', function (Blueprint $table) {
            $table->decimal('quantite', 20, 8)->change();
            $table->decimal('quantite_restante_avant', 20, 8)->change();
            $table->decimal('quantite_restante_apres', 20, 8)->change();
        });

        DB::table('produits')->orderBy('id')->chunkById(100, function ($products): void {
            foreach ($products as $product) {
                foreach (['achat', 'vente'] as $type) {
                    DB::table('produit_unites')->insert([
                        'id_produit' => $product->id,
                        'id_unite' => $product->unite_id,
                        'type' => $type,
                        'facteur' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        DB::table('ligne_approvisionnements')->orderBy('id')->chunkById(100, function ($lines): void {
            foreach ($lines as $line) {
                $unitId = DB::table('produits')->where('id', $line->id_produit)->value('unite_id');
                DB::table('ligne_approvisionnements')->where('id', $line->id)->update([
                    'id_unite' => $unitId,
                    'quantite_stock' => $line->quantite,
                ]);
            }
        });

        DB::table('ligne_ventes')->orderBy('id')->chunkById(100, function ($lines): void {
            foreach ($lines as $line) {
                $unitId = DB::table('produits')->where('id', $line->id_produit)->value('unite_id');
                DB::table('ligne_ventes')->where('id', $line->id)->update([
                    'id_unite' => $unitId,
                    'quantite_stock' => $line->quantite,
                ]);
            }
        });

        DB::unprepared('DROP TRIGGER IF EXISTS update_stock_after_vente_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS create_lot_after_approvisionnement');
    }

    public function down(): void
    {
        Schema::table('mouvements_stock_fifos', function (Blueprint $table) {
            $table->integer('quantite')->change();
            $table->integer('quantite_restante_avant')->change();
            $table->integer('quantite_restante_apres')->change();
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->integer('quantite_initial')->change();
        });

        Schema::table('ligne_ventes', function (Blueprint $table) {
            $table->integer('quantite')->change();
            $table->dropConstrainedForeignId('id_unite');
            $table->dropColumn(['facteur_conversion', 'quantite_stock', 'prix_unitaire_stock', 'prix_vente_stock']);
        });

        Schema::table('ligne_approvisionnements', function (Blueprint $table) {
            $table->integer('quantite')->change();
            $table->dropConstrainedForeignId('id_unite');
            $table->dropColumn(['facteur_conversion', 'quantite_stock']);
        });

        Schema::dropIfExists('produit_unites');
    }
};