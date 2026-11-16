<?php

namespace Tests\Feature;

use App\Models\ligne_approvisionnements;
use App\Models\ligne_retours;
use App\Services\TiDbTriggerFallback;
use App\Support\DatabaseTriggers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TiDbCompatibilityTest extends TestCase
{
    public function test_tidb_skips_trigger_sql_but_keeps_views_in_mixed_migration(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getConfig')->with('host')->andReturn('gateway01.eu-central-1.prod.aws.tidbcloud.com');
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('statement')->twice()->with(Mockery::on(fn ($sql) => str_contains($sql, 'CREATE OR REPLACE VIEW')))->andReturn(true);
        // No unprepared SQL is permitted on the mocked TiDB connection.
        (require database_path('migrations/2026_05_15_000001_fix_fifo_stock_calculation.php'))->up();
        $this->assertTrue(DatabaseTriggers::usesApplicationFallback());
    }

    public function test_regular_mysql_still_executes_trigger_sql(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getConfig')->with('host')->andReturn('localhost');
        $connection->shouldReceive('getServerVersion')->andReturn('8.0.40');
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('unprepared')->once()->with('DROP TRIGGER IF EXISTS example')->andReturn(true);
        $this->assertTrue(DatabaseTriggers::unprepared('DROP TRIGGER IF EXISTS example'));
    }

    private function createCashTables(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('caisses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('id_devise'); $table->decimal('solde', 20, 8); $table->timestamps();
        });
        Schema::create('transactions_caisses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('id_caisse'); $table->string('type');
            $table->decimal('montant', 20, 8); $table->string('reference_type'); $table->unsignedBigInteger('reference_id');
            $table->string('description'); $table->decimal('solde_avant', 20, 8); $table->decimal('solde_apres', 20, 8); $table->timestamps();
        });
        DB::table('caisses')->insert(['id_devise' => 1, 'solde' => 100]);
    }

    public function test_purchase_cash_debit_and_ledger_are_preserved_without_triggers(): void
    {
        $this->createCashTables();
        $line = new ligne_approvisionnements(['id_devise' => 1, 'paye_par_caisse' => true, 'quantite' => '3', 'prix_unitaire' => '0.10']);
        $line->id = 7;
        TiDbTriggerFallback::purchaseCreated($line);
        $this->assertEquals(99.7, DB::table('caisses')->value('solde'));
        $this->assertDatabaseHas('transactions_caisses', ['reference_type' => 'approvisionnement', 'reference_id' => 7, 'montant' => 0.3]);
        $line->paye_par_caisse = false;
        TiDbTriggerFallback::purchaseCreated($line);
        $this->assertDatabaseCount('transactions_caisses', 1);
    }

    public function test_return_refunds_cash_and_restores_stock_once(): void
    {
        $this->createCashTables();
        Schema::create('lots', function (Blueprint $table) {
            $table->id(); $table->decimal('quantite_initial', 20, 8); $table->timestamps();
        });
        Schema::create('mouvements_stock_fifos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('id_lot'); $table->unsignedBigInteger('id_ligne_retour')->nullable();
            $table->string('type_mouvement'); $table->decimal('quantite', 20, 8);
            $table->decimal('quantite_restante_avant', 20, 8)->nullable(); $table->decimal('quantite_restante_apres', 20, 8)->nullable();
            $table->date('date_mouvement')->nullable(); $table->timestamps();
        });
        DB::table('lots')->insert(['id' => 1, 'quantite_initial' => 10]);
        DB::table('mouvements_stock_fifos')->insert(['id_lot' => 1, 'type_mouvement' => 'sortie', 'quantite' => 4]);
        $line = new ligne_retours(['id_lot' => 1, 'id_retour' => 9, 'id_devise' => 1, 'prix_remboursement' => 2, 'quantite_retournee' => 2, 'etat_produit' => 'bon', 'reintegre_stock' => true]);
        $line->id = 5;
        TiDbTriggerFallback::returnCreated($line);
        $this->assertEquals(96, DB::table('caisses')->value('solde'));
        $this->assertEquals(12, DB::table('lots')->value('quantite_initial'));
        $this->assertDatabaseHas('mouvements_stock_fifos', ['id_ligne_retour' => 5, 'quantite_restante_avant' => 6, 'quantite_restante_apres' => 8]);
        $this->assertDatabaseHas('transactions_caisses', ['reference_type' => 'retour', 'reference_id' => 9]);
    }
}
