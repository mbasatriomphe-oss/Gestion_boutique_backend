<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger après insertion d'une ligne de vente
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER update_stock_after_vente_insert
            AFTER INSERT ON ligne_ventes
            FOR EACH ROW
            BEGIN
                DECLARE quantite_restante INT;
                DECLARE lot_id INT;
                DECLARE quantite_lot INT;
                DECLARE done INT DEFAULT FALSE;
                
                DECLARE cur_lots CURSOR FOR 
                    SELECT id, quantite_initial - COALESCE((
                        SELECT SUM(quantite) 
                        FROM mouvements_stock_fifos 
                        WHERE id_lot = lots.id
                    ), 0) as disponible
                    FROM lots 
                    WHERE id_produit = NEW.id_produit 
                    AND (quantite_initial - COALESCE((
                        SELECT SUM(quantite) 
                        FROM mouvements_stock_fifos 
                        WHERE id_lot = lots.id
                    ), 0)) > 0
                    ORDER BY date_reception ASC;
                
                DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
                
                SET quantite_restante = NEW.quantite;
                
                OPEN cur_lots;
                
                lire_loop: LOOP
                    FETCH cur_lots INTO lot_id, quantite_lot;
                    IF done OR quantite_restante <= 0 THEN
                        LEAVE lire_loop;
                    END IF;
                    
                    IF quantite_lot >= quantite_restante THEN
                        -- Le lot actuel a assez de stock
                        INSERT INTO mouvements_stock_fifos (
                            id_lot, id_ligne_vente, type_mouvement, 
                            quantite, quantite_restante_avant, 
                            quantite_restante_apres, date_mouvement, created_at
                        ) VALUES (
                            lot_id, NEW.id, "sortie",
                            quantite_restante, quantite_lot, 
                            quantite_lot - quantite_restante, CURDATE(), NOW()
                        );
                        SET quantite_restante = 0;
                    ELSE
                        -- Prendre tout le lot
                        INSERT INTO mouvements_stock_fifos (
                            id_lot, id_ligne_vente, type_mouvement, 
                            quantite, quantite_restante_avant, 
                            quantite_restante_apres, date_mouvement, created_at
                        ) VALUES (
                            lot_id, NEW.id, "sortie",
                            quantite_lot, quantite_lot, 0, CURDATE(), NOW()
                        );
                        SET quantite_restante = quantite_restante - quantite_lot;
                    END IF;
                END LOOP;
                
                CLOSE cur_lots;
                
                -- Si stock insuffisant, lever une erreur
                IF quantite_restante > 0 THEN
                    SIGNAL SQLSTATE "45000" 
                    SET MESSAGE_TEXT = "Stock insuffisant pour cette vente";
                END IF;
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_stock_after_vente_insert');
    }
};