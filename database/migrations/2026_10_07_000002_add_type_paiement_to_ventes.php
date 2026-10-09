<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->string('type_paiement', 20)->nullable()->after('statut_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropColumn('type_paiement');
        });
    }
};
