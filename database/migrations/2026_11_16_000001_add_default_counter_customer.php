<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('system_key')->nullable()->unique();
        });
        DB::table('clients')->insert([
            'nom' => 'Client comptoir', 'system_key' => 'counter',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the customer and its sales history on rollback.
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('system_key'));
    }
};
