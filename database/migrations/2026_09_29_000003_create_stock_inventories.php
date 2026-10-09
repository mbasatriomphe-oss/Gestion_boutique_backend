<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_user')->constrained('users')->restrictOnDelete();
            $table->date('date_inventaire');
            $table->unsignedInteger('nombre_produits');
            $table->unsignedInteger('nombre_ecarts')->default(0);
            $table->timestamps();
        });

        Schema::create('lignes_inventaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_inventaire')->constrained('inventaires')->cascadeOnDelete();
            $table->foreignId('id_produit')->constrained('produits')->restrictOnDelete();
            $table->decimal('quantite_attendue', 20, 8);
            $table->decimal('quantite_comptee', 20, 8);
            $table->decimal('ecart', 20, 8);
            $table->json('quantites_formats');
            $table->timestamps();
            $table->index(['id_produit', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_inventaires');
        Schema::dropIfExists('inventaires');
    }
};