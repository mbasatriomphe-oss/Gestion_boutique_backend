<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_stock_after_vente_insert');

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
                        AND type_mouvement = "sortie"
                    ), 0) as disponible
                    FROM lots
                    WHERE id_produit = NEW.id_produit
                    AND (quantite_initial - COALESCE((
                        SELECT SUM(quantite)
                        FROM mouvements_stock_fifos
                        WHERE id_lot = lots.id
                        AND type_mouvement = "sortie"
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

                IF quantite_restante > 0 THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "Stock insuffisant pour cette vente";
                END IF;
            END
        ');

        DB::statement('
            CREATE OR REPLACE VIEW v_stock_disponible AS
            SELECT
                p.id,
                p.code,
                p.nom,
                p.description,
                u.nom as unite_nom,
                u.symbole as unite_symbole,
                COALESCE(SUM(l.quantite_initial - COALESCE((
                    SELECT SUM(quantite)
                    FROM mouvements_stock_fifos mf
                    WHERE mf.id_lot = l.id
                    AND mf.type_mouvement = "sortie"
                ), 0)), 0) as stock_actuel
            FROM produits p
            LEFT JOIN unites u ON u.id = p.unite_id
            LEFT JOIN lots l ON l.id_produit = p.id
            GROUP BY p.id, p.code, p.nom, p.description, u.nom, u.symbole
        ');

        DB::statement('
            CREATE OR REPLACE VIEW v_lots_expiration AS
            SELECT
                l.id,
                l.numero_lot,
                p.nom as produit_nom,
                l.quantite_initial,
                (l.quantite_initial - COALESCE((
                    SELECT SUM(quantite)
                    FROM mouvements_stock_fifos mf
                    WHERE mf.id_lot = l.id
                    AND mf.type_mouvement = "sortie"
                ), 0)) as quantite_restante,
                l.date_reception,
                l.date_expiration,
                DATEDIFF(l.date_expiration, CURDATE()) as jours_restants,
                CASE
                    WHEN l.date_expiration < CURDATE() THEN "Expiré"
                    WHEN DATEDIFF(l.date_expiration, CURDATE()) <= 30 THEN "Expire bientôt"
                    ELSE "Valide"
                END as statut_expiration
            FROM lots l
            INNER JOIN produits p ON p.id = l.id_produit
            HAVING quantite_restante > 0
            ORDER BY l.date_expiration ASC
        ');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_lots_expiration');
        DB::statement('DROP VIEW IF EXISTS v_stock_disponible');
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_stock_after_vente_insert');
    }
};