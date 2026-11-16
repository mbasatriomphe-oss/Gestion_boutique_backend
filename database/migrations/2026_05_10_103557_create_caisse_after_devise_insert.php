<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger AFTER INSERT sur devises
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER create_caisse_after_devise_insert
            AFTER INSERT ON devises
            FOR EACH ROW
            BEGIN
                INSERT INTO caisses (id_devise, solde, created_at, updated_at)
                VALUES (NEW.id, 0.00, NOW(), NOW());
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS create_caisse_after_devise_insert');
    }
};
