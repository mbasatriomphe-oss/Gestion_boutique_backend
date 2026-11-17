<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\vendeurs;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Notification::fake();
        // Real table migrations, excluding the MySQL-specific triggers and views.
        $patterns = ['0001_01_01_000000*', '2026_05_06*', '2026_05_07*', '2026_05_08*', '2026_05_09*',
            '2026_05_10_061706*', '2026_05_10_091845*', '2026_05_10_104854*', '2026_05_10_145214*',
            '2026_05_14*', '2026_06_01*', '2026_07_07_000001*', '2026_10_07*', '2026_11_14*', '2026_11_16*'];
        foreach ($patterns as $pattern) foreach (glob(database_path('migrations/' . $pattern . '.php')) as $file) (require $file)->up();
        Schema::create('produit_unites', function (Blueprint $t) {
            $t->id(); $t->foreignId('id_produit')->constrained('produits'); $t->foreignId('id_unite')->constrained('unites');
            $t->string('type'); $t->decimal('facteur', 20, 8);
            $t->timestamps();
        });
        Schema::table('ligne_ventes', function (Blueprint $t) {
            $t->unsignedBigInteger('id_unite')->nullable(); $t->decimal('facteur_conversion', 20, 8)->default(1); $t->decimal('quantite_stock', 20, 8)->nullable();
        });
        Schema::table('ligne_approvisionnements', function (Blueprint $t) {
            $t->boolean('paye_par_caisse')->default(false);
            $t->unsignedBigInteger('id_unite')->nullable();
            $t->decimal('facteur_conversion', 20, 8)->default(1);
            $t->decimal('quantite_stock', 20, 8)->nullable();
            $t->decimal('prix_unitaire_stock', 20, 8)->nullable();
            $t->decimal('prix_vente_stock', 20, 8)->nullable();
        });
        (require database_path('migrations/2026_09_29_000002_add_product_setup_pricing_and_opening_stock.php'))->up();
        DB::statement("CREATE VIEW v_stock_disponible AS SELECT p.id, p.code, p.nom, COALESCE((SELECT SUM(l.quantite_initial) FROM lots l WHERE l.id_produit = p.id), 0) - COALESCE((SELECT SUM(m.quantite) FROM mouvements_stock_fifos m JOIN lots l ON l.id = m.id_lot WHERE l.id_produit = p.id AND m.type_mouvement = 'sortie'), 0) AS stock_actuel FROM produits p");
    }

    private function admin(): User
    {
        $user = User::create(['nom' => 'Admin test', 'prenom' => 'Test', 'email' => 'admin@example.test', 'password' => 'password', 'role' => 'admin']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function seller(): vendeurs
    {
        $seller = new vendeurs(['nom' => 'Vendeur', 'prenom' => 'Test', 'code' => 'V-20', 'email' => 'seller@example.test', 'password' => Hash::make('password')]);
        $seller->id = 20; $seller->save(); Sanctum::actingAs($seller);
        return $seller;
    }

    public function test_organizations_persist_and_validate_input(): void
    {
        $this->admin();
        $payload = ['name' => 'Dépôt test', 'type' => 'warehouse', 'employees' => 3, 'status' => 'active'];
        $id = $this->postJson('/api/organizations', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/organizations?per_page=all')->assertOk()->assertJsonPath('data.0.name', 'Dépôt test');
        $this->putJson('/api/organizations/' . $id, [...$payload, 'name' => 'Dépôt modifié'])->assertOk();
        $this->assertDatabaseHas('organizations', ['id' => $id, 'name' => 'Dépôt modifié']);
        $this->postJson('/api/organizations', [...$payload, 'employees' => -1])->assertUnprocessable();
        $this->deleteJson('/api/organizations/' . $id)->assertOk();
        $this->assertDatabaseMissing('organizations', ['id' => $id]);
    }

    public function test_seller_role_profile_and_admin_permissions(): void
    {
        $seller = $this->seller();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('role', 'vendeur');
        $this->getJson('/api/organizations')->assertForbidden();
        $this->postJson('/api/produits', [])->assertForbidden();
        $this->putJson('/api/account/profile', ['nom' => 'Nouveau nom', 'prenom' => 'Test'])->assertOk();
        $this->assertDatabaseHas('vendeurs', ['id' => $seller->id, 'nom' => 'Nouveau nom']);
    }

    public function test_seller_can_login_and_use_a_real_bearer_token(): void
    {
        $this->seller();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $token = $this->postJson('/api/login-vendeur', ['email' => 'seller@example.test', 'password' => 'password'])->assertOk()->json('token');
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/user')->assertOk()->assertJsonPath('role', 'vendeur');
        $this->getJson('/api/account/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.current', true);
        $other = User::create(['nom' => 'Autre', 'prenom' => 'Admin', 'email' => 'other@example.test', 'password' => 'password', 'role' => 'admin']);
        $foreignToken = $other->createToken('other-device');
        $this->deleteJson('/api/account/sessions/' . $foreignToken->accessToken->id)->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreignToken->accessToken->id]);
        $this->postJson('/api/account/logout-all')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_customer_list_loads_more_than_fifteen_records(): void
    {
        $this->admin();
        for ($i = 0; $i < 23; $i++) $this->postJson('/api/clients', ['nom' => 'Client ' . $i])->assertCreated();
        $this->getJson('/api/clients?per_page=all')->assertOk()->assertJsonCount(24, 'data');
        $this->getJson('/api/clients?search=Client%2022')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_active_exchange_rate_autoloading(): void
    {
        $this->admin();
        $usd = $this->postJson('/api/devises', ['code' => 'USD', 'nom' => 'Dollar', 'symbole' => '$'])->assertCreated()->json('data.id');
        $cdf = $this->postJson('/api/devises', ['code' => 'CDF', 'nom' => 'Franc', 'symbole' => 'FC'])->assertCreated()->json('data.id');
        $this->postJson('/api/taux', ['devise_source' => $usd, 'devise_but' => $cdf, 'valeur' => 2800, 'date_effet' => '2026-01-01', 'statut' => 'actif', 'avec_inverse' => true])->assertCreated();
        $response = $this->getJson('/api/taux/actif?devise_source=' . $usd . '&devise_but=' . $cdf . '&date=2026-11-14')->assertOk();
        $this->assertEquals(2800, (float) $response->json('data.valeur'));
    }

    public function test_counter_customer_cannot_be_edited_or_deleted(): void
    {
        $this->admin();
        $counter = DB::table('clients')->where('system_key', 'counter')->value('id');
        $this->putJson('/api/clients/'.$counter, ['nom' => 'Autre nom'])->assertUnprocessable();
        $this->deleteJson('/api/clients/'.$counter)->assertUnprocessable();
        $this->assertDatabaseHas('clients', ['id' => $counter, 'nom' => 'Client comptoir']);
    }

    public function test_purchase_details_update_stock_prices_currency_and_cash_atomically(): void
    {
        $admin = $this->admin();
        $usd = $this->postJson('/api/devises', ['code' => 'USD', 'nom' => 'Dollar', 'symbole' => '$'])->json('data.id');
        $cdf = $this->postJson('/api/devises', ['code' => 'CDF', 'nom' => 'Franc', 'symbole' => 'FC'])->json('data.id');
        $unit = $this->postJson('/api/unites', ['nom' => 'Pièce', 'symbole' => 'pc'])->json('data.id');
        $category = $this->postJson('/api/categories', ['nom' => 'Épicerie'])->json('data.id');
        $supplier = $this->postJson('/api/fournisseurs', ['nom' => 'Fournisseur test', 'adresse' => 'Rue test', 'ville' => 'Lubumbashi', 'pays' => 'RDC', 'contact' => '+243000000000'])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/produits', ['nom' => 'Produit réception', 'unite_id' => $unit, 'categorie_id' => $category,
            'unite_achat_id' => $unit, 'prix_achat' => 2, 'devise_achat_id' => $usd,
            'unites_vente' => [['id_unite' => $unit, 'facteur' => 1, 'prix' => 5, 'id_devise' => $usd]],
        ])->assertCreated()->json('data.id');
        DB::table('caisses')->insert([['id_devise' => $usd, 'solde' => 100], ['id_devise' => $cdf, 'solde' => 1000]]);
        $purchase = $this->postJson('/api/approvisionnements', ['date' => now()->toDateString(), 'id_user' => $admin->id, 'id_fournisseur' => $supplier,
            'lignes' => [['id_produit' => $product, 'id_unite' => $unit, 'quantite' => 10, 'prix_unitaire' => 2, 'prix_vente' => 5, 'id_devise' => $usd, 'paye_par_caisse' => true]],
        ])->assertCreated()->json('data');
        $line = $purchase['lignes'][0]['id'];
        $lot = DB::table('lots')->where('id_ligne_approvisionnement', $line)->value('id');
        // SQLite has no purchase cash trigger; start at the real post-purchase balance.
        DB::table('caisses')->where('id_devise', $usd)->update(['solde' => 80]);
        $updates = ['id' => $line, 'quantite' => 12, 'prix_unitaire' => 3, 'prix_vente' => 7, 'id_devise' => $cdf];
        $this->putJson('/api/approvisionnements/'.$purchase['id'], ['lignes' => [$updates]])->assertOk();
        $this->assertDatabaseHas('lots', ['id' => $lot, 'quantite_initial' => 12, 'id_devise' => $cdf, 'prix_unitaire_stock' => 3]);
        $this->assertDatabaseHas('ligne_approvisionnements', ['id' => $line, 'prix_vente_stock' => 7, 'quantite_stock' => 12]);
        $this->assertEquals(100, DB::table('caisses')->where('id_devise', $usd)->value('solde'));
        $this->assertEquals(964, DB::table('caisses')->where('id_devise', $cdf)->value('solde'));
        $this->assertDatabaseHas('mouvements_stock_fifos', ['id_lot' => $lot, 'type_mouvement' => 'entree', 'quantite' => 12]);
        // Re-saving unchanged lines must never debit cash a second time.
        $this->putJson('/api/approvisionnements/'.$purchase['id'], ['lignes' => [$updates]])->assertOk();
        $this->assertEquals(964, DB::table('caisses')->where('id_devise', $cdf)->value('solde'));
        $updates['prix_unitaire'] = 200;
        $this->putJson('/api/approvisionnements/'.$purchase['id'], ['lignes' => [$updates]])->assertUnprocessable();
        $this->assertDatabaseHas('ligne_approvisionnements', ['id' => $line, 'prix_unitaire' => 3]);
        $this->assertEquals(964, DB::table('caisses')->where('id_devise', $cdf)->value('solde'));
        DB::table('mouvements_stock_fifos')->insert(['id_lot' => $lot, 'type_mouvement' => 'sortie', 'quantite' => 1, 'quantite_restante_avant' => 12, 'quantite_restante_apres' => 11, 'date_mouvement' => now()->toDateString()]);
        $this->putJson('/api/approvisionnements/'.$purchase['id'], ['lignes' => [$updates]])->assertUnprocessable();
        $this->putJson('/api/lignes-approvisionnements/'.$line, ['prix_vente' => 9])->assertOk();
        $this->assertDatabaseHas('ligne_approvisionnements', ['id' => $line, 'prix_vente_stock' => 9]);
        $this->putJson('/api/lignes-approvisionnements/'.$line, ['id_devise' => $usd])->assertUnprocessable();
    }

    public function test_pos_reads_only_latest_lot_and_sale_configuration_without_changing_admin_lists(): void
    {
        $admin = $this->admin();
        $usd = $this->postJson('/api/devises', ['code' => 'USD', 'nom' => 'Dollar', 'symbole' => '$'])->assertCreated()->json('data.id');
        $unit = $this->postJson('/api/unites', ['nom' => 'Pièce', 'symbole' => 'pc'])->assertCreated()->json('data.id');
        $category = $this->postJson('/api/categories', ['nom' => 'Épicerie'])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/produits', [
            'nom' => 'Produit POS', 'unite_id' => $unit, 'categorie_id' => $category,
            'unite_achat_id' => $unit, 'prix_achat' => 2, 'devise_achat_id' => $usd,
            'unites_vente' => [['id_unite' => $unit, 'facteur' => 1, 'prix' => 5, 'id_devise' => $usd]],
        ])->assertCreated()->json('data.id');
        $supplier = $this->postJson('/api/fournisseurs', ['nom' => 'Fournisseur POS', 'adresse' => 'Rue test', 'ville' => 'Lubumbashi', 'pays' => 'RDC', 'contact' => '+243000000000'])->assertCreated()->json('data.id');
        $this->postJson('/api/approvisionnements', [
            'date' => now()->toDateString(), 'id_user' => $admin->id, 'id_fournisseur' => $supplier,
            'lignes' => [['id_produit' => $product, 'id_unite' => $unit, 'quantite' => 10, 'prix_unitaire' => 2, 'prix_vente' => 5, 'id_devise' => $usd]],
        ])->assertCreated();
        $oldLot = DB::table('lots')->where('id_produit', $product)->first();
        $duplicate = (array) $oldLot;
        unset($duplicate['id']);
        $duplicate['numero_lot'] = 'LOT-NEW-POS';
        $newId = DB::table('lots')->insertGetId($duplicate);

        $this->seller();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $compact = $this->getJson('/api/lots?per_page=all&latest_per_product=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newId)
            ->assertJsonPath('data.0.id_produit', $product)
            ->assertJsonPath('data.0.devise.code', 'USD')
            ->assertJsonStructure(['data' => [['ligne_approvisionnement' => ['prix_unitaire_stock', 'prix_vente_stock', 'facteur_conversion']]]])
            ->assertJsonMissingPath('data.0.approvisionnement');
        $compactQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $full = $this->getJson('/api/lots?per_page=all')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['approvisionnement', 'produit']]]);
        $fullQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan($fullQueries, $compactQueries);
        $this->assertLessThan(strlen($full->getContent()), strlen($compact->getContent()));
        $this->getJson('/api/produits?per_page=all&pos=1')->assertOk()
            ->assertJsonPath('data.0.unite.symbole', 'pc')
            ->assertJsonPath('data.0.unites_vente.0.devise.code', 'USD')
            ->assertJsonMissingPath('data.0.unites_achat');
        $this->getJson('/api/produits?per_page=all')->assertOk()
            ->assertJsonStructure(['data' => [['unites_achat', 'unites_vente']]]);
        // POS prices do not alter the authoritative stock calculation.
        $this->getJson('/api/stocks/disponible')->assertOk()->assertJsonPath('data.0.stock_actuel', 20);
    }

    public function test_sale_updates_stock_cash_and_rejects_insufficient_stock(): void
    {
        $this->admin();
        $usd = $this->postJson('/api/devises', ['code' => 'USD', 'nom' => 'Dollar', 'symbole' => '$'])->assertCreated()->json('data.id');
        $unit = $this->postJson('/api/unites', ['nom' => 'Pièce', 'symbole' => 'pc'])->assertCreated()->json('data.id');
        $category = $this->postJson('/api/categories', ['nom' => 'Épicerie'])->assertCreated()->json('data.id');
        $client = $this->postJson('/api/clients', ['nom' => 'Client vente'])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/produits', ['nom' => 'Produit test', 'unite_id' => $unit, 'categorie_id' => $category,
            'unite_achat_id' => $unit, 'prix_achat' => 2, 'devise_achat_id' => $usd,
            'unites_vente' => [['id_unite' => $unit, 'facteur' => 1, 'prix' => 5, 'id_devise' => $usd]],
            'stock_initial' => [['id_unite' => $unit, 'quantite' => 10]],
        ])->assertCreated()->json('data.id');
        DB::table('caisses')->insert(['id_devise' => $usd, 'solde' => 0]);
        $seller = $this->seller();
        $payload = ['date' => '2026-11-14', 'id_vendeur' => $seller->id, 'id_client' => $client, 'devise_vente_id' => $usd,
            'type_paiement' => 'total', 'mode_paiement' => 'cash', 'paiements' => [['devise_id' => $usd, 'montant' => 10]],
            'lignes' => [['id_produit' => $product, 'id_unite' => $unit, 'quantite' => 2, 'prix_vente' => 5, 'id_devise' => $usd]],
        ];
        $payload['id_client'] = null;
        $saleId = $this->postJson('/api/ventes', $payload)->assertCreated()->json('data.id');
        $counter = DB::table('clients')->where('system_key', 'counter')->value('id');
        $this->assertDatabaseHas('ventes', ['id' => $saleId, 'id_client' => $counter]);
        $this->assertEquals(8, (float) DB::table('v_stock_disponible')->where('id', $product)->value('stock_actuel'));
        $this->assertEquals(10, (float) DB::table('caisses')->where('id_devise', $usd)->value('solde'));
        $payload['lignes'][0]['quantite'] = 100;
        $this->postJson('/api/ventes', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('ventes', 1);
        $this->assertEquals(10, (float) DB::table('caisses')->where('id_devise', $usd)->value('solde'));
        $this->deleteJson('/api/ventes/' . $saleId)->assertOk();
        $this->assertDatabaseCount('ventes', 0);
        $this->assertEquals(10, (float) DB::table('v_stock_disponible')->where('id', $product)->value('stock_actuel'));
        $this->assertEquals(0, (float) DB::table('caisses')->where('id_devise', $usd)->value('solde'));
        // The same persisted sale supports a later advance and final debt settlement.
        $payload['lignes'][0]['quantite'] = 2;
        $payload['type_paiement'] = 'credit'; $payload['paiements'] = [];
        $this->postJson('/api/ventes', $payload)->assertUnprocessable()->assertJsonValidationErrors('id_client');
        $payload['id_client'] = $counter;
        $this->postJson('/api/ventes', $payload)->assertUnprocessable()->assertJsonValidationErrors('id_client');
        $payload['type_paiement'] = 'avance'; $payload['paiements'] = [['devise_id' => $usd, 'montant' => 4]];
        $payload['id_client'] = null;
        $this->postJson('/api/ventes', $payload)->assertUnprocessable()->assertJsonValidationErrors('id_client');
        $payload['type_paiement'] = 'credit'; $payload['paiements'] = [];
        $payload['id_client'] = $client;
        $creditId = $this->postJson('/api/ventes', $payload)->assertCreated()->json('data.id');
        $this->putJson('/api/ventes/'.$creditId, ['id_client' => $counter])->assertUnprocessable()->assertJsonValidationErrors('id_client');
        $this->postJson('/api/ventes/' . $creditId . '/paiements', ['paiements' => [['devise_id' => $usd, 'montant' => 4]]])->assertOk();
        $this->assertEquals(6, (float) DB::table('ventes')->where('id', $creditId)->value('reste_a_payer'));
        $this->postJson('/api/ventes/' . $creditId . '/paiements', ['paiements' => [['devise_id' => $usd, 'montant' => 6]]])->assertOk();
        $this->assertEquals(0, (float) DB::table('ventes')->where('id', $creditId)->value('reste_a_payer'));
        $this->assertEquals(10, (float) DB::table('caisses')->where('id_devise', $usd)->value('solde'));
        $this->getJson('/api/ventes/' . $creditId)->assertOk()->assertJsonPath('data.lignes.0.produit.nom', 'Produit test');
    }
}
