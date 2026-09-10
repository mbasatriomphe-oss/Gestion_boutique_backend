<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->unsignedBigInteger('devise_vente_id')->nullable()->after('id_client');
            $table->decimal('montant_total', 20, 8)->default(0)->after('devise_vente_id');
            $table->decimal('montant_paye', 20, 8)->default(0)->after('montant_total');
            $table->decimal('reste_a_payer', 20, 8)->default(0)->after('montant_paye');
            $table->string('statut_paiement', 20)->default('payee')->after('reste_a_payer');

            $table->foreign('devise_vente_id')->references('id')->on('devises')->nullOnDelete();
            $table->index(['id_client', 'reste_a_payer']);
            $table->index(['statut_paiement']);
        });
    }

    public function down(): void
    {
        $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'ventes')
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        foreach (['ventes_id_client_foreign', 'ventes_devise_vente_id_foreign'] as $constraint) {
            if (in_array($constraint, $constraints, true)) {
                DB::statement("ALTER TABLE `ventes` DROP FOREIGN KEY `{$constraint}`");
            }
        }

        $indexes = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'ventes')
            ->pluck('INDEX_NAME')
            ->unique()
            ->all();

        foreach (['ventes_id_client_reste_a_payer_index', 'ventes_statut_paiement_index'] as $index) {
            if (in_array($index, $indexes, true)) {
                DB::statement("ALTER TABLE `ventes` DROP INDEX `{$index}`");
            }
        }

        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn(['devise_vente_id', 'montant_total', 'montant_paye', 'reste_a_payer', 'statut_paiement']);
        });

        if (!DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'ventes')
            ->where('INDEX_NAME', 'ventes_id_client_foreign')
            ->exists()) {
            Schema::table('ventes', function (Blueprint $table) {
                $table->index('id_client', 'ventes_id_client_foreign');
            });
        }

        if (!DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'ventes')
            ->where('CONSTRAINT_NAME', 'ventes_id_client_foreign')
            ->exists()) {
            Schema::table('ventes', function (Blueprint $table) {
                $table->foreign('id_client')->references('id')->on('clients');
            });
        }
    }
};