<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Taux;
use App\Models\ventes;

class RapportController extends Controller
{
    public function credits(): JsonResponse
    {
        $sales = ventes::query()
            ->with(['client', 'vendeur', 'deviseVente'])
            ->where('reste_a_payer', '>', 0)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(function (ventes $sale) {
                if (! $sale->type_paiement) {
                    $sale->type_paiement = (float) $sale->montant_paye > 0 ? 'avance' : 'credit';
                }

                return $sale;
            });

        $totalsByCurrency = $sales
            ->groupBy(fn (ventes $sale) => $sale->devise_vente_id ?? 'inconnue')
            ->map(function ($currencySales) {
                $currency = $currencySales->first()->deviseVente;

                return [
                    'devise' => $currency,
                    'nombre_ventes' => $currencySales->count(),
                    'total_restant' => $currencySales->sum(fn (ventes $sale) => (float) $sale->reste_a_payer),
                ];
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'ventes' => $sales,
                'nombre_clients' => $sales->pluck('id_client')->unique()->count(),
                'nombre_ventes' => $sales->count(),
                'totaux_par_devise' => $totalsByCurrency,
            ],
        ]);
    }

    public function recapJournalier(): JsonResponse
    {
        $recap = DB::table('v_recap_journalier')->first();

        return response()->json([
            'status' => 'success',
            'data' => $recap,
        ]);
    }

    public function etatCaisses(): JsonResponse
    {
        $caisses = DB::table('v_etat_caisses')->orderBy('devise_code')->get();

        return response()->json([
            'status' => 'success',
            'data' => $caisses,
        ]);
    }

    public function chiffreAffaires(Request $request): JsonResponse
    {
        $query = DB::table('v_chiffre_affaires');

        // Accept both French param names (date_debut/date_fin) and generic start/end
        $start = $request->query('date_debut') ?? $request->query('start');
        $end = $request->query('date_fin') ?? $request->query('end');

        if ($start) {
            $query->whereDate('date_vente', '>=', $start);
        }

        if ($end) {
            $query->whereDate('date_vente', '<=', $end);
        }

        if ($request->filled('devise_code')) {
            $query->where('devise_code', $request->string('devise_code'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderByDesc('date_vente')->get(),
        ]);
    }

    /**
     * Retourne le bénéfice total par jour sur une période donnée.
     * Accepte les paramètres `date_debut`/`start` et `date_fin`/`end`.
     */
    public function beneficePeriod(Request $request): JsonResponse
    {
        $start = $request->query('date_debut') ?? $request->query('start');
        $end = $request->query('date_fin') ?? $request->query('end');

        // Optional target currency: accept devise_id or devise_code
        $targetDeviseId = null;
        if ($request->filled('devise_id')) {
            $targetDeviseId = (int) $request->query('devise_id');
        } elseif ($request->filled('devise_code')) {
            $targetDeviseId = DB::table('devises')->where('code', $request->string('devise_code'))->value('id');
        }

        // Precompute average purchase price per product
        $avgPurchaseRows = DB::table('ligne_approvisionnements as la')
            ->join('lots as l', 'l.id_ligne_approvisionnement', '=', 'la.id')
            ->select('l.id_produit', DB::raw('AVG(COALESCE(la.prix_unitaire_stock, la.prix_unitaire / NULLIF(la.facteur_conversion, 0))) as avg_purchase'))
            ->groupBy('l.id_produit')
            ->get()
            ->keyBy('id_produit')
            ->map(function ($r) { return (float) $r->avg_purchase; })
            ->toArray();

        $linesQ = DB::table('ligne_ventes as lv')
            ->join('ventes as v', 'v.id', '=', 'lv.id_vente')
            ->leftJoin('devises as d', 'd.id', '=', 'lv.id_devise')
            ->select('lv.id_produit', 'lv.quantite', 'lv.facteur_conversion', 'lv.prix_vente', 'lv.id_devise', 'v.date as vente_date', 'd.code as devise_code', DB::raw('v.id as vente_id'), DB::raw('DATE(v.date) as date_vente'));

        if ($start) $linesQ->whereDate('v.date', '>=', $start);
        if ($end) $linesQ->whereDate('v.date', '<=', $end);

        $lines = $linesQ->get();

        $result = [];

        foreach ($lines as $line) {
            $prodId = $line->id_produit;
            $qty = (float) $line->quantite;
            $salePrice = (float) $line->prix_vente;
            $avgPurchase = isset($avgPurchaseRows[$prodId]) ? (float) $avgPurchaseRows[$prodId] : 0.0;
            $factor = max((float) ($line->facteur_conversion ?? 1), 0.00000001);
            $stockQuantity = $qty * $factor;
            $profit = ($salePrice / $factor - $avgPurchase) * $stockQuantity;

            // handle conversion to target currency if requested
            if ($targetDeviseId) {
                $srcDevId = (int) $line->id_devise;
                $date = (string) $line->vente_date;
                $rate = $this->resolveRate($srcDevId, $targetDeviseId, $date);
                if ($rate === null) {
                    // skip or treat as zero
                    $convertedProfit = 0.0;
                } else {
                    $convertedProfit = $profit * $rate;
                }
                $devCode = DB::table('devises')->where('id', $targetDeviseId)->value('code');
            } else {
                $convertedProfit = $profit;
                $devCode = $line->devise_code;
            }

            $dateKey = $line->date_vente;
            if (! isset($result[$dateKey])) $result[$dateKey] = ['date_vente' => $dateKey, 'devise_code' => $devCode, 'benefice_total' => 0.0];
            $result[$dateKey]['benefice_total'] += $convertedProfit;
        }

        // return sorted by date desc
        $rows = array_values($result);
        usort($rows, fn($a, $b) => strcmp($b['date_vente'], $a['date_vente']));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * Retourne le bénéfice par produit sur une période donnée.
     * Paramètres: `date_debut`/`start`, `date_fin`/`end`, `limit`.
     */
    public function beneficeProduit(Request $request): JsonResponse
    {
        $start = $request->query('date_debut') ?? $request->query('start');
        $end = $request->query('date_fin') ?? $request->query('end');
        $limit = max(1, min((int) $request->query('limit', 50), 500));

        $targetDeviseId = null;
        if ($request->filled('devise_id')) {
            $targetDeviseId = (int) $request->query('devise_id');
        } elseif ($request->filled('devise_code')) {
            $targetDeviseId = DB::table('devises')->where('code', $request->string('devise_code'))->value('id');
        }

        // Precompute average purchase price per product
        $avgPurchaseRows = DB::table('ligne_approvisionnements as la')
            ->join('lots as l', 'l.id_ligne_approvisionnement', '=', 'la.id')
            ->select('l.id_produit', DB::raw('AVG(COALESCE(la.prix_unitaire_stock, la.prix_unitaire / NULLIF(la.facteur_conversion, 0))) as avg_purchase'))
            ->groupBy('l.id_produit')
            ->get()
            ->keyBy('id_produit')
            ->map(function ($r) { return (float) $r->avg_purchase; })
            ->toArray();

        $linesQ = DB::table('ligne_ventes as lv')
            ->join('ventes as v', 'v.id', '=', 'lv.id_vente')
            ->join('produits as p', 'p.id', '=', 'lv.id_produit')
            ->leftJoin('devises as d', 'd.id', '=', 'lv.id_devise')
            ->select('lv.id_produit', 'p.code as produit_code', 'p.nom as produit_nom', 'lv.quantite', 'lv.facteur_conversion', 'lv.prix_vente', 'lv.id_devise', 'v.date as vente_date', 'd.code as devise_code');

        if ($start) $linesQ->whereDate('v.date', '>=', $start);
        if ($end) $linesQ->whereDate('v.date', '<=', $end);

        $lines = $linesQ->get();

        $byProduct = [];

        foreach ($lines as $line) {
            $prodId = $line->id_produit;
            $qty = (float) $line->quantite;
            $salePrice = (float) $line->prix_vente;
            $avgPurchase = isset($avgPurchaseRows[$prodId]) ? (float) $avgPurchaseRows[$prodId] : 0.0;
            $factor = max((float) ($line->facteur_conversion ?? 1), 0.00000001);
            $stockQuantity = $qty * $factor;
            $profit = ($salePrice / $factor - $avgPurchase) * $stockQuantity;

            if ($targetDeviseId) {
                $srcDevId = (int) $line->id_devise;
                $date = (string) $line->vente_date;
                $rate = $this->resolveRate($srcDevId, $targetDeviseId, $date);
                $convertedProfit = $rate === null ? 0.0 : $profit * $rate;
                $devCode = DB::table('devises')->where('id', $targetDeviseId)->value('code');
            } else {
                $convertedProfit = $profit;
                $devCode = $line->devise_code;
            }

            if (! isset($byProduct[$prodId])) {
                $byProduct[$prodId] = [
                    'id_produit' => $prodId,
                    'produit_code' => $line->produit_code,
                    'produit_nom' => $line->produit_nom,
                    'devise_code' => $devCode,
                    'quantite_vendue' => 0,
                    'chiffre_affaires' => 0.0,
                    'benefice_total' => 0.0,
                ];
            }

            $byProduct[$prodId]['quantite_vendue'] += $stockQuantity;
            $byProduct[$prodId]['chiffre_affaires'] += $qty * $salePrice;
            $byProduct[$prodId]['benefice_total'] += $convertedProfit;
        }

        $rows = array_values($byProduct);
        usort($rows, fn($a, $b) => $b['benefice_total'] <=> $a['benefice_total']);
        $rows = array_slice($rows, 0, $limit);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * Resolve conversion rate from source currency id to target currency id on a given date.
     * Returns float rate (multiply source amount by this to get target amount) or null if unavailable.
     */
    private function resolveRate(?int $sourceDeviseId, ?int $targetDeviseId, string $date): ?float
    {
        if (! $sourceDeviseId || ! $targetDeviseId) return null;
        if ($sourceDeviseId === $targetDeviseId) return 1.0;

        $direct = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $sourceDeviseId)
            ->where('devise_but', $targetDeviseId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        if ($direct !== null && (float) $direct > 0) {
            return (float) $direct;
        }

        $reverse = Taux::query()
            ->where('statut', 'actif')
            ->where('devise_source', $targetDeviseId)
            ->where('devise_but', $sourceDeviseId)
            ->whereDate('date_effet', '<=', $date)
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->value('valeur');

        if ($reverse === null || (float) $reverse <= 0) {
            return null;
        }

        return 1.0 / (float) $reverse;
    }

    public function topProduits(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 10), 100));

        $produits = DB::table('produits as p')
            ->join('categories as c', 'c.id', '=', 'p.categorie_id')
            ->join('ligne_ventes as lv', 'lv.id_produit', '=', 'p.id')
            ->select('p.id', 'p.code', 'p.nom', 'c.nom as categorie_nom')
            ->selectRaw('COUNT(DISTINCT lv.id_vente) as nombre_ventes')
            ->selectRaw('SUM(lv.quantite * COALESCE(lv.facteur_conversion, 1)) as quantite_vendue')
            ->selectRaw('SUM(lv.quantite * lv.prix_vente) as chiffre_affaires')
            ->groupBy('p.id', 'p.code', 'p.nom', 'c.nom')
            ->orderByDesc('chiffre_affaires')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $produits,
        ]);
    }

    public function lotsExpiration(Request $request): JsonResponse
    {
        $query = DB::table('v_lots_expiration');

        if ($request->filled('statut_expiration')) {
            $query->where('statut_expiration', $request->string('statut_expiration'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderBy('date_expiration')->get(),
        ]);
    }

    public function margeProduit(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->query('limit', 50), 100));
        $sales = DB::table('ligne_ventes')
            ->select('id_produit')
            ->selectRaw('AVG((1.0 * prix_vente) / NULLIF(COALESCE(facteur_conversion, 1), 0)) as prix_vente_moyen')
            ->groupBy('id_produit');
        $purchases = DB::table('ligne_approvisionnements')
            ->select('id_produit')
            ->selectRaw('AVG(COALESCE(prix_unitaire_stock, (1.0 * prix_unitaire) / NULLIF(COALESCE(facteur_conversion, 1), 0))) as prix_achat_moyen')
            ->groupBy('id_produit');

        $marges = DB::table('produits as p')
            ->joinSub($sales, 'sales', 'sales.id_produit', '=', 'p.id')
            ->leftJoinSub($purchases, 'purchases', 'purchases.id_produit', '=', 'p.id')
            ->select('p.id', 'p.code', 'p.nom', 'sales.prix_vente_moyen', 'purchases.prix_achat_moyen')
            ->selectRaw('(sales.prix_vente_moyen - COALESCE(purchases.prix_achat_moyen, 0)) as marge_unitaire')
            ->selectRaw('((sales.prix_vente_moyen - COALESCE(purchases.prix_achat_moyen, 0)) / NULLIF(sales.prix_vente_moyen, 0) * 100) as marge_pourcentage')
            ->orderByDesc('marge_pourcentage')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $marges,
        ]);
    }

    public function mouvementsCaisse(Request $request): JsonResponse
    {
        $query = DB::table('v_mouvements_caisse');

        if ($request->filled('devise_code')) {
            $query->where('devise_code', $request->string('devise_code'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderByDesc('date_mouvement')->get(),
        ]);
    }
}
