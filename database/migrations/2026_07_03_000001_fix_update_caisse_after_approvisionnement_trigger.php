<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_approvisionnement');

        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER update_caisse_after_approvisionnement
            AFTER INSERT ON ligne_approvisionnements
            FOR EACH ROW
            BEGIN
                DECLARE montant_total DECIMAL(20,8);

                SET montant_total = NEW.quantite * NEW.prix_unitaire;

                IF NEW.paye_par_caisse = 1 THEN
                    -- Vérifier si le solde est suffisant pour la devise de l\'approvisionnement
                    IF (SELECT solde FROM caisses WHERE id_devise = NEW.id_devise) < montant_total THEN
                        SIGNAL SQLSTATE "45000"
                        SET MESSAGE_TEXT = "Solde insuffisant pour cet approvisionnement";
                    END IF;

                    -- Mettre à jour le solde de la caisse
                    UPDATE caisses
                    SET solde = solde - montant_total,
                        updated_at = NOW()
                    WHERE id_devise = NEW.id_devise;

                    -- Enregistrer la transaction de caisse
                    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = "transactions_caisses") THEN
                        INSERT INTO transactions_caisses (
                            id_caisse, type, montant, reference_type,
                            reference_id, description, solde_avant, solde_apres, created_at
                        )
                        SELECT
                            c.id, "sortie", montant_total, "approvisionnement",
                            NEW.id, CONCAT("Achat #", NEW.id_approvisionnement),
                            c.solde + montant_total, c.solde, NOW()
                        FROM caisses c
                        WHERE c.id_devise = NEW.id_devise;
                    END IF;
                END IF;
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_approvisionnement');
    }
};
