<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger après insertion d'un retour
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER reintegrer_stock_after_retour
            AFTER INSERT ON ligne_retours
            FOR EACH ROW
            BEGIN
                DECLARE quantite_restante INT;
                DECLARE lot_origin_id INT;
                
                SET quantite_restante = NEW.quantite_retournee;
                SET lot_origin_id = NEW.id_lot;
                
                -- Si le produit est en bon état, réintégrer au même lot
                IF NEW.etat_produit = "bon" AND NEW.reintegre_stock = TRUE THEN
                    -- Mettre à jour le lot original
                    UPDATE lots 
                    SET quantite_initial = quantite_initial + NEW.quantite_retournee
                    WHERE id = lot_origin_id;
                    
                    -- Enregistrer le mouvement de retour
                    INSERT INTO mouvements_stock_fifos (
                        id_lot, id_ligne_retour, type_mouvement, 
                        quantite, quantite_restante_avant, 
                        quantite_restante_apres, date_mouvement, created_at
                    ) VALUES (
                        lot_origin_id, NEW.id, "retour",
                        NEW.quantite_retournee, 
                        (SELECT quantite_initial - COALESCE((
                            SELECT SUM(quantite) 
                            FROM mouvements_stock_fifos 
                            WHERE id_lot = lot_origin_id 
                            AND type_mouvement = "sortie"
                        ), 0) FROM lots WHERE id = lot_origin_id),
                        (SELECT quantite_initial - COALESCE((
                            SELECT SUM(quantite) 
                            FROM mouvements_stock_fifos 
                            WHERE id_lot = lot_origin_id 
                            AND type_mouvement = "sortie"
                        ), 0) FROM lots WHERE id = lot_origin_id) + NEW.quantite_retournee,
                        CURDATE(), NOW()
                    );
                END IF;
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS reintegrer_stock_after_retour');
    }
};
