<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UniteController;
use App\Http\Controllers\DeviseController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\VendeurController;
use App\Http\Controllers\ApprovisionnementController;
use App\Http\Controllers\LigneApprovisionnementController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\MaishaPayController;
use App\Http\Controllers\LigneVenteController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\RetourController;
use App\Http\Controllers\LigneRetourController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\MouvementStockFifoController;
use App\Http\Controllers\StockInventaireController;
use App\Http\Controllers\TransactionCaisseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TauxController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ==================== ROUTES PUBLIQUES (sans authentification) ====================

// Inscription et connexion
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login-vendeur', [AuthController::class, 'loginVendeur']);

// Route publique pour créer le premier admin (à désactiver après utilisation)
Route::post('/register-admin', [AuthController::class, 'registerAdmin']);

// Routes publiques de callback MaishaPay
Route::get('/maishapay/redirect/{session}', [MaishaPayController::class, 'redirectToMaishaPay'])->name('maishapay.redirect');
Route::get('/maishapay/success', [MaishaPayController::class, 'success'])->name('maishapay.success');
Route::get('/maishapay/failure', [MaishaPayController::class, 'failure'])->name('maishapay.failure');
Route::get('/maishapay/cancel', [MaishaPayController::class, 'cancel'])->name('maishapay.cancel');
Route::post('/maishapay/webhook', [MaishaPayController::class, 'webhook'])->name('maishapay.webhook');

// ==================== ROUTES PROTÉGÉES PAR AUTHENTIFICATION ====================

Route::middleware(['auth:sanctum'])->group(function () {
    
    // Déconnexion (nécessite d'être connecté)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/account/profile', [\App\Http\Controllers\AccountController::class, 'update']);
    
    // Récupérer l'utilisateur connecté
    Route::get('/user', function(Request $request) {
        return $request->user();
    });

// Public signed routes (no auth) - accessible only with a valid temporary signature
Route::get('/rapports/stock/public', [\App\Http\Controllers\ReportController::class, 'stockHtmlPublic'])->name('rapports.stock.public')->middleware('signed');
Route::get('/rapports/ventes/public', [\App\Http\Controllers\ReportController::class, 'ventesHtmlPublic'])->name('rapports.ventes.public')->middleware('signed');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');

    Route::middleware(['admin.or.vendeur'])->group(function () {
        Route::apiResource('clients', ClientController::class);
        Route::apiResource('ventes', VenteController::class);
        Route::get('/rapports/vendeur/export', [\App\Http\Controllers\VendeurReportController::class, 'export'])->name('rapports.vendeur.export');
        Route::post('/ventes/{id}/paiements', [VenteController::class, 'addPayment']);
        Route::post('/maishapay/session', [MaishaPayController::class, 'createSession']);
        Route::apiResource('retours', RetourController::class);

        Route::get('/produits', [ProduitController::class, 'index'])->name('produits.index');
        Route::get('/produits/{id}', [ProduitController::class, 'show'])->name('produits.show');
        Route::get('/devises', [DeviseController::class, 'index'])->name('devises.index');
        Route::get('/devises/{id}', [DeviseController::class, 'show'])->name('devises.show');
        Route::get('/vendeurs', [VendeurController::class, 'index'])->name('vendeurs.index');
        Route::get('/vendeurs/{id}', [VendeurController::class, 'show'])->name('vendeurs.show');
        Route::get('/lots', [LotController::class, 'index'])->name('lots.index');
        Route::get('/lots/{id}', [LotController::class, 'show'])->name('lots.show');
        Route::get('/stocks/disponible', [MouvementStockFifoController::class, 'stocksDisponibles'])->name('stocks.disponible');
        Route::get('/taux/actif', [TauxController::class, 'actifByDate'])->name('taux.actif');
    });
    
    // Routes unités (lecture pour tous les utilisateurs authentifiés)
    Route::prefix('unites')->group(function () {
        Route::get('/', [UniteController::class, 'index'])->name('unites.index');
        Route::get('/all', [UniteController::class, 'all'])->name('unites.all');
        Route::get('/{id}', [UniteController::class, 'show'])->name('unites.show');
    });
    
    // ==================== ROUTES ADMIN UNIQUEMENT ====================
    
    Route::middleware(['admin'])->group(function () {
        Route::get('/admin/backup/database', [\App\Http\Controllers\DatabaseBackupController::class, 'download'])->name('admin.backup.database');
        Route::get('/admin/backup/database/latest-local', [\App\Http\Controllers\DatabaseBackupController::class, 'downloadLatestLocal'])->name('admin.backup.database.latest-local');
        Route::get('/admin/backup/settings', [\App\Http\Controllers\DatabaseBackupController::class, 'settings'])->name('admin.backup.settings');
        Route::put('/admin/backup/settings', [\App\Http\Controllers\DatabaseBackupController::class, 'updateSettings'])->name('admin.backup.settings.update');
        Route::post('/admin/backup/database/email', [\App\Http\Controllers\DatabaseBackupController::class, 'sendNow'])->name('admin.backup.database.email');
        Route::get('/rapports/credits', [RapportController::class, 'credits'])->name('rapports.credits');
        Route::post('/stocks/inventaire', [StockInventaireController::class, 'store'])->name('stocks.inventaire');
        // Gestion complète des utilisateurs (CRUD)
        Route::apiResource('users', UserController::class);
        Route::apiResource('devises', DeviseController::class)->except(['index', 'show']);
        Route::apiResource('categories', CategorieController::class);
        Route::post('/vendeurs', [VendeurController::class, 'store'])->name('vendeurs.store');
        Route::put('/vendeurs/{id}', [VendeurController::class, 'update'])->name('vendeurs.update');
        Route::delete('/vendeurs/{id}', [VendeurController::class, 'destroy'])->name('vendeurs.destroy');
        Route::apiResource('produits', ProduitController::class)->except(['index', 'show']);
        Route::apiResource('fournisseurs', FournisseurController::class);
        Route::apiResource('approvisionnements', ApprovisionnementController::class);
        
        // Routes pour les lignes d'approvisionnement (CRUD de base)
        // Note: Le nom de la route doit correspondre à l'URL appelée par le frontend
        // Le frontend appelle "/lignes-approvisionnements" (avec un 's')
        Route::apiResource('lignes-approvisionnements', LigneApprovisionnementController::class);
        
        // ========== NOUVELLES ROUTES POUR LA GESTION DES DEVISES ==========
        // Route pour mettre à jour la devise d'une ligne d'approvisionnement
        Route::put('/lignes-approvisionnements/{id}/devise', [LigneApprovisionnementController::class, 'updateDevise']);
        
        // Route pour mettre à jour plusieurs lignes en une seule requête
        Route::post('/lignes-approvisionnements/batch/devise', [LigneApprovisionnementController::class, 'batchUpdateDevise']);
        
        // Routes existantes
        Route::apiResource('ligne-ventes', LigneVenteController::class);
        Route::apiResource('ligne-retours', LigneRetourController::class);
        Route::apiResource('caisses', CaisseController::class);
        Route::get('/caisses/devise/{idDevise}', [CaisseController::class, 'byDevise'])->name('caisses.byDevise');
        Route::post('/caisses/{id}/credit', [CaisseController::class, 'credit'])->name('caisses.credit');
        Route::post('/caisses/{id}/debit', [CaisseController::class, 'debit'])->name('caisses.debit');
        Route::apiResource('transactions-caisses', TransactionCaisseController::class);
        Route::apiResource('mouvements-stock-fifos', MouvementStockFifoController::class);
        Route::get('/stocks/produit/{produitId}', [MouvementStockFifoController::class, 'stockParProduit'])->name('stocks.produit');
        Route::get('/stocks/lot/{lotId}', [MouvementStockFifoController::class, 'stockParLot'])->name('stocks.lot');
        Route::get('/mouvements-stock-fifos/produit/{produitId}', [MouvementStockFifoController::class, 'mouvementsParProduit'])->name('mouvements-stock-fifos.produit');
        Route::get('/mouvements-stock-fifos/lot/{lotId}', [MouvementStockFifoController::class, 'mouvementsParLot'])->name('mouvements-stock-fifos.lot');
        Route::get('/mouvements-stock-fifos/vente/{venteId}', [MouvementStockFifoController::class, 'mouvementsParVente'])->name('mouvements-stock-fifos.vente');
        Route::get('/rapports/recap-journalier', [RapportController::class, 'recapJournalier'])->name('rapports.recap-journalier');
        Route::get('/rapports/etat-caisses', [RapportController::class, 'etatCaisses'])->name('rapports.etat-caisses');
        Route::get('/rapports/chiffre-affaires', [RapportController::class, 'chiffreAffaires'])->name('rapports.chiffre-affaires');
        Route::get('/rapports/benefice-periode', [RapportController::class, 'beneficePeriod'])->name('rapports.benefice-periode');
        Route::get('/rapports/benefice-produit', [RapportController::class, 'beneficeProduit'])->name('rapports.benefice-produit');
        Route::get('/rapports/top-produits', [RapportController::class, 'topProduits'])->name('rapports.top-produits');
        Route::get('/rapports/lots-expiration', [RapportController::class, 'lotsExpiration'])->name('rapports.lots-expiration');
        Route::get('/rapports/marge-produit', [RapportController::class, 'margeProduit'])->name('rapports.marge-produit');
        Route::get('/rapports/mouvements-caisse', [RapportController::class, 'mouvementsCaisse'])->name('rapports.mouvements-caisse');
        Route::get('/rapports/ventes/pdf', [ReportController::class, 'ventesPdf'])->name('rapports.ventes.pdf');
        Route::get('/rapports/stock/pdf', [ReportController::class, 'stockPdf'])->name('rapports.stock.pdf');
        Route::get('/rapports/stock-fifo/pdf', [ReportController::class, 'stockFifoPdf'])->name('rapports.stock-fifo.pdf');
        // HTML fallbacks (do not require PDF library)
        Route::get('/rapports/ventes/html', [ReportController::class, 'ventesHtml'])->name('rapports.ventes.html');
        Route::get('/rapports/stock/html', [ReportController::class, 'stockHtml'])->name('rapports.stock.html');
        Route::get('/rapports/stock-fifo/html', [ReportController::class, 'stockFifoHtml'])->name('rapports.stock-fifo.html');
        // Accept posted HTML and render server-side to PDF
        Route::post('/rapports/html-to-pdf', [ReportController::class, 'htmlToPdf'])->name('rapports.html-to-pdf');
        // Signed URL generator (authenticated) - returns a temporary public URL usable in a new tab
        Route::post('/rapports/stock/signed', [ReportController::class, 'stockSigned'])->name('rapports.stock.signed');
        Route::post('/rapports/ventes/signed', [ReportController::class, 'ventesSigned'])->name('rapports.ventes.signed');
        Route::apiResource('taux', TauxController::class);
        // Ajoutez ces routes dans votre fichier routes/api.php à l'intérieur du middleware admin

        // Paramètres de la boutique
        Route::prefix('parametres')->group(function () {
            Route::get('/boutique', [SettingsController::class, 'getStoreInfo']);
            Route::put('/boutique', [SettingsController::class, 'updateStoreInfo']);
            Route::get('/horaires', [SettingsController::class, 'getStoreHours']);
            Route::put('/horaires', [SettingsController::class, 'updateStoreHours']);
            Route::get('/alertes', [SettingsController::class, 'getAlertSettings']);
            Route::put('/alertes', [SettingsController::class, 'updateAlertSettings']);
            Route::get('/facturation', [SettingsController::class, 'getInvoiceSettings']);
            Route::put('/facturation', [SettingsController::class, 'updateInvoiceSettings']);
            Route::get('/apparence', [SettingsController::class, 'getAppearance']);
            Route::put('/apparence', [SettingsController::class, 'updateAppearance']);
        });
        
        // Routes d'écriture pour les unités (CRUD)
        Route::prefix('unites')->group(function () {
            Route::post('/', [UniteController::class, 'store'])->name('unites.store');
            Route::put('/{id}', [UniteController::class, 'update'])->name('unites.update');
            Route::delete('/{id}', [UniteController::class, 'destroy'])->name('unites.destroy');
        });
    });
});