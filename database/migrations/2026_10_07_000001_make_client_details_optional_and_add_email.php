<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('post_nom', 90)->nullable()->change();
            $table->string('prenom', 90)->nullable()->change();
            $table->string('adresse', 63)->nullable()->change();
            $table->string('ville', 50)->nullable()->change();
            $table->string('pays', 50)->nullable()->change();
            $table->string('contact', 50)->nullable()->change();
            $table->string('email')->nullable()->after('contact');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('email');
            $table->string('post_nom', 90)->nullable(false)->change();
            $table->string('prenom', 90)->nullable(false)->change();
            $table->string('adresse', 63)->nullable(false)->change();
            $table->string('ville', 50)->nullable(false)->change();
            $table->string('pays', 50)->nullable(false)->change();
            $table->string('contact', 50)->nullable(false)->change();
        });
    }
};
