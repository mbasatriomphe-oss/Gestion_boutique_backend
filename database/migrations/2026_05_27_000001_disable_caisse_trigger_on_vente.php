<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_vente');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER update_caisse_after_vente
            AFTER INSERT ON ligne_ventes
            FOR EACH ROW
            BEGIN
                DECLARE montant_total DECIMAL(20,8);
                DECLARE devise_vente INT;

                SET montant_total = NEW.quantite * NEW.prix_vente;
                SET devise_vente = NEW.id_devise;

                UPDATE caisses
                SET solde = solde + montant_total,
                    updated_at = NOW()
                WHERE id_devise = devise_vente;

                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = "transactions_caisses") THEN
                    INSERT INTO transactions_caisses (
                        id_caisse, type, montant, reference_type,
                        reference_id, description, solde_avant, solde_apres, created_at
                    )
                    SELECT
                        c.id, "entree", montant_total, "vente",
                        NEW.id, CONCAT("Vente #", NEW.id),
                        (c.solde - montant_total), c.solde, NOW()
                    FROM caisses c
                    WHERE c.id_devise = devise_vente;
                END IF;
            END
        ');
    }
};