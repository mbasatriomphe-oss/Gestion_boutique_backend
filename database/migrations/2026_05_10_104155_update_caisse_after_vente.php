<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigger après insertion d'une vente pour créditer la caisse
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER update_caisse_after_vente
            AFTER INSERT ON ligne_ventes
            FOR EACH ROW
            BEGIN
                DECLARE montant_total DECIMAL(20,8);
                DECLARE devise_vente INT;
                
                SET montant_total = NEW.quantite * NEW.prix_vente;
                SET devise_vente = NEW.id_devise;
                
                -- Mettre à jour le solde de la caisse
                UPDATE caisses 
                SET solde = solde + montant_total,
                    updated_at = NOW()
                WHERE id_devise = devise_vente;
                
                -- Enregistrer la transaction (si table existe)
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
        
        // Trigger après insertion d'un approvisionnement pour débiter la caisse seulement si le paiement passe par la caisse
        // Le trigger d'approvisionnement utilisant la colonne paye_par_caisse est créé plus tard,
        // après que la colonne soit ajoutée par la migration 2026_07_03_000000_add_paye_par_caisse_to_ligne_approvisionnements.php.
        
        // Trigger après insertion d'un retour pour débiter la caisse
        \App\Support\DatabaseTriggers::unprepared('
            CREATE TRIGGER update_caisse_after_retour
            AFTER INSERT ON ligne_retours
            FOR EACH ROW
            BEGIN
                DECLARE montant_remboursement DECIMAL(20,8);
                
                SET montant_remboursement = NEW.prix_remboursement * NEW.quantite_retournee;
                
                -- Mettre à jour le solde de la caisse (débit pour remboursement)
                UPDATE caisses 
                SET solde = solde - montant_remboursement,
                    updated_at = NOW()
                WHERE id_devise = NEW.id_devise;
                
                -- Enregistrer la transaction
                IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = "transactions_caisses") THEN
                    INSERT INTO transactions_caisses (
                        id_caisse, type, montant, reference_type, 
                        reference_id, description, solde_avant, solde_apres, created_at
                    ) 
                    SELECT 
                        c.id, "sortie", montant_remboursement, "retour",
                        NEW.id_retour, CONCAT("Remboursement retour #", NEW.id_retour),
                        c.solde + montant_remboursement, c.solde, NOW()
                    FROM caisses c
                    WHERE c.id_devise = NEW.id_devise;
                END IF;
            END
        ');
    }

    public function down(): void
    {
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_vente');
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_approvisionnement');
        \App\Support\DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS update_caisse_after_retour');
    }
};