<?php

namespace App\Http\Controllers;

use App\Models\mouvements_stock_fifos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MouvementStockFifoController extends ApiCrudController
{
    protected string $modelClass = mouvements_stock_fifos::class;

    protected function storeRules(): array
    {
        return [
            'id_lot' => 'required|integer|exists:lots,id',
            'id_ligne_vente' => 'nullable|integer|exists:ligne_ventes,id',
            'id_ligne_retour' => 'nullable|integer|exists:ligne_retours,id',
            'type_mouvement' => 'required|in:entree,sortie,retour',
            'quantite' => 'required|numeric|gt:0',
            'quantite_restante_avant' => 'required|numeric|min:0',
            'quantite_restante_apres' => 'required|numeric|min:0',
            'date_mouvement' => 'required|date',
        ];
    }

    protected function updateRules(\Illuminate\Database\Eloquent\Model $model): array
    {
        return [
            'id_lot' => 'sometimes|integer|exists:lots,id',
            'id_ligne_vente' => 'nullable|integer|exists:ligne_ventes,id',
            'id_ligne_retour' => 'nullable|integer|exists:ligne_retours,id',
            'type_mouvement' => 'sometimes|in:entree,sortie,retour',
            'quantite' => 'sometimes|numeric|gt:0',
            'quantite_restante_avant' => 'sometimes|numeric|min:0',
            'quantite_restante_apres' => 'sometimes|numeric|min:0',
            'date_mouvement' => 'sometimes|date',
        ];
    }

    public function stockParProduit(int $produitId): JsonResponse
    {
        $stock = DB::table('v_stock_disponible')->where('id', $produitId)->first();

        if (! $stock) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucun stock trouvé pour ce produit.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $stock,
        ]);
    }

    public function stockParLot(int $lotId): JsonResponse
    {
        $lot = DB::table('lots')
            ->leftJoin('mouvements_stock_fifos', 'mouvements_stock_fifos.id_lot', '=', 'lots.id')
            ->where('lots.id', $lotId)
            ->select(
                'lots.id',
                'lots.numero_lot',
                'lots.id_produit',
                'lots.quantite_initial',
                DB::raw('lots.quantite_initial - COALESCE(SUM(CASE WHEN mouvements_stock_fifos.type_mouvement = "sortie" THEN mouvements_stock_fifos.quantite ELSE 0 END), 0) as quantite_restante')
            )
            ->groupBy('lots.id', 'lots.numero_lot', 'lots.id_produit', 'lots.quantite_initial')
            ->first();

        if (! $lot) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucun lot trouvé.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $lot,
        ]);
    }

    public function stocksDisponibles(): JsonResponse
    {
        $stocks = DB::table('v_stock_disponible')->orderBy('nom')->get();

        return response()->json([
            'status' => 'success',
            'data' => $stocks,
        ]);
    }

    public function mouvementsParProduit(int $produitId): JsonResponse
    {
        $mouvements = mouvements_stock_fifos::query()
            ->select('mouvements_stock_fifos.*')
            ->join('lots', 'lots.id', '=', 'mouvements_stock_fifos.id_lot')
            ->where('lots.id_produit', $produitId)
            ->orderByDesc('mouvements_stock_fifos.date_mouvement')
            ->orderByDesc('mouvements_stock_fifos.id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $mouvements,
        ]);
    }

    public function mouvementsParLot(int $lotId): JsonResponse
    {
        $mouvements = mouvements_stock_fifos::query()
            ->where('id_lot', $lotId)
            ->orderByDesc('date_mouvement')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $mouvements,
        ]);
    }

    public function mouvementsParVente(int $venteId): JsonResponse
    {
        $mouvements = mouvements_stock_fifos::query()
            ->select(
                'mouvements_stock_fifos.id',
                'mouvements_stock_fifos.id_lot',
                'mouvements_stock_fifos.id_ligne_vente',
                'mouvements_stock_fifos.id_ligne_retour',
                'mouvements_stock_fifos.type_mouvement',
                'mouvements_stock_fifos.quantite',
                'mouvements_stock_fifos.quantite_restante_avant',
                'mouvements_stock_fifos.quantite_restante_apres',
                'mouvements_stock_fifos.date_mouvement',
                'lots.numero_lot',
                'lots.id_produit',
                'produits.nom as produit_nom',
            )
            ->join('ligne_ventes', 'ligne_ventes.id', '=', 'mouvements_stock_fifos.id_ligne_vente')
            ->join('lots', 'lots.id', '=', 'mouvements_stock_fifos.id_lot')
            ->join('produits', 'produits.id', '=', 'lots.id_produit')
            ->where('ligne_ventes.id_vente', $venteId)
            ->orderByDesc('mouvements_stock_fifos.date_mouvement')
            ->orderByDesc('mouvements_stock_fifos.id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $mouvements,
        ]);
    }
}