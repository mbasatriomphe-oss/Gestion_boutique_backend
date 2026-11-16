<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger après insertion d'une ligne d'approvisionnement
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER create_lot_after_approvisionnement
            AFTER INSERT ON ligne_approvisionnements
            FOR EACH ROW
            BEGIN
                DECLARE lot_number VARCHAR(50);
                DECLARE appro_date DATE;
                
                -- Récupérer la date d\'approvisionnement
                SELECT date INTO appro_date 
                FROM approvisionnements 
                WHERE id = NEW.id_approvisionnement;
                
                -- Générer numéro de lot unique
                SET lot_number = CONCAT(
                    "LOT-", 
                    DATE_FORMAT(appro_date, "%Y%m%d"),
                    "-",
                    LPAD(NEW.id_produit, 5, "0"),
                    "-",
                    LPAD(FLOOR(RAND() * 10000), 4, "0")
                );
                
                -- Créer le lot
                INSERT INTO lots (
                    numero_lot, id_produit, id_approvisionnement, 
                    id_ligne_approvisionnement, quantite_initial, 
                    date_reception, id_devise, created_at, updated_at
                ) VALUES (
                    lot_number, NEW.id_produit, NEW.id_approvisionnement,
                    NEW.id, NEW.quantite, appro_date, NEW.id_devise, NOW(), NOW()
                );
                
                -- Enregistrer le mouvement FIFO d\'entrée
                INSERT INTO mouvements_stock_fifos (
                    id_lot, type_mouvement, quantite, 
                    quantite_restante_avant, quantite_restante_apres, 
                    date_mouvement, created_at
                ) VALUES (
                    LAST_INSERT_ID(), "entree", NEW.quantite,
                    0, NEW.quantite, appro_date, NOW()
                );
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS create_lot_after_approvisionnement');
    }
};