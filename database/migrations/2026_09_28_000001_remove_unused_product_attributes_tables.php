<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('variantes_produits');
        Schema::dropIfExists('valeurs_produit_dynamiques');
        Schema::dropIfExists('attributs_templates');
        Schema::dropIfExists('attributs');
    }

    public function down(): void
    {
        Schema::create('attributs', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('attributs_templates', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('valeurs_produit_dynamiques', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        Schema::create('variantes_produits', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};