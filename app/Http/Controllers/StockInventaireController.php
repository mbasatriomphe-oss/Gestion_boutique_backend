<?php

namespace App\Http\Controllers;

use App\Models\inventaires;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Models\produits;
use App\Services\ProductUnitConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockInventaireController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*.id_produit' => 'required|integer|distinct|exists:produits,id',
            'lignes.*.formats' => 'required|array|min:1',
            'lignes.*.formats.*.id_unite' => 'required|integer|exists:unites,id',
            'lignes.*.formats.*.quantite' => 'required|numeric|min:0',
        ]);

        $conversionService = app(ProductUnitConversionService::class);

        $inventory = DB::transaction(function () use ($validated, $conversionService) {
            $productIds = array_map(static fn (array $line) => (int) $line['id_produit'], $validated['lignes']);
            $products = produits::query()
                ->with(['unitesAchat', 'unitesVente'])
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $stockRows = DB::table('v_stock_disponible')->whereIn('id', $productIds)->get()->keyBy('id');

            $inventaire = inventaires::create([
                'id_user' => (int) auth()->id(),
                'date_inventaire' => now()->toDateString(),
                'nombre_produits' => count($validated['lignes']),
                'nombre_ecarts' => 0,
            ]);
            $differenceCount = 0;

            foreach ($validated['lignes'] as $line) {
                $product = $products->get((int) $line['id_produit']);
                $counted = BigDecimal::of('0');
                $unitIds = [];
                $formats = [];

                foreach ($line['formats'] as $format) {
                    $unitId = (int) $format['id_unite'];
                    if (in_array($unitId, $unitIds, true)) {
                        throw ValidationException::withMessages([
                            'lignes' => "L’unité de comptage est répétée pour {$product->nom}.",
                        ]);
                    }
                    $unitIds[] = $unitId;

                    $conversion = $product->unitesAchat->firstWhere('id_unite', $unitId)
                        ?? $product->unitesVente->firstWhere('id_unite', $unitId);
                    $factor = $conversion?->facteur ?? ((int) $product->unite_id === $unitId ? '1' : null);

                    if ($factor === null) {
                        throw ValidationException::withMessages([
                            'lignes' => "L’unité de comptage n’est pas configurée pour {$product->nom}.",
                        ]);
                    }

                    $quantity = (string) $format['quantite'];
                    $counted = $counted->plus(BigDecimal::of($conversionService->quantityInStockUnit($quantity, $factor)));
                    $formats[] = ['id_unite' => $unitId, 'quantite' => $quantity];
                }

                $counted = $counted->toScale(8, RoundingMode::HALF_UP);
                $expected = BigDecimal::of((string) ($stockRows->get($product->id)?->stock_actuel ?? '0'))->toScale(8, RoundingMode::HALF_UP);
                $difference = $counted->minus($expected)->toScale(8, RoundingMode::HALF_UP);

                DB::table('lignes_inventaires')->insert([
                    'id_inventaire' => $inventaire->id,
                    'id_produit' => $product->id,
                    'quantite_attendue' => $expected->__toString(),
                    'quantite_comptee' => $counted->__toString(),
                    'ecart' => $difference->__toString(),
                    'quantites_formats' => json_encode($formats, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($difference->isEqualTo(BigDecimal::of('0'))) {
                    continue;
                }

                $differenceCount++;
                if ($difference->isGreaterThan(BigDecimal::of('0'))) {
                    $this->addInventoryStock($product, $difference, $conversionService);
                } else {
                    $this->removeInventoryStock($product, $difference->abs());
                }
            }

            $inventaire->update(['nombre_ecarts' => $differenceCount]);

            return $inventaire->fresh(['lignes.produit']);
        });

        return response()->json([
            'status' => 'success',
            'data' => $inventory,
        ], 201);
    }

    private function addInventoryStock(produits $product, BigDecimal $quantity, ProductUnitConversionService $conversionService): void
    {
        $purchaseUnit = $product->unitesAchat->first();
        $latestLot = lots::query()->where('id_produit', $product->id)->orderByDesc('id')->first();
        $currencyId = $purchaseUnit?->id_devise ?? $latestLot?->id_devise ?? DB::table('devises')->orderBy('id')->value('id');

        if (! $currencyId) {
            throw ValidationException::withMessages([
                'lignes' => "Aucune devise n’est configurée pour valoriser l’ajustement de {$product->nom}.",
            ]);
        }

        $unitCost = $purchaseUnit?->prix !== null
            ? $conversionService->pricePerStockUnit($purchaseUnit->prix, $purchaseUnit->facteur)
            : $latestLot?->prix_unitaire_stock;
        $lotNumber = sprintf('INV-%s-%05d-%04d', now()->format('YmdHis'), $product->id, random_int(0, 9999));
        $lot = lots::create([
            'numero_lot' => $lotNumber,
            'id_produit' => $product->id,
            'id_approvisionnement' => null,
            'id_ligne_approvisionnement' => null,
            'quantite_initial' => $quantity->__toString(),
            'prix_unitaire_stock' => $unitCost,
            'date_reception' => now()->toDateString(),
            'date_expiration' => null,
            'id_devise' => $currencyId,
        ]);

        mouvements_stock_fifos::create([
            'id_lot' => $lot->id,
            'type_mouvement' => 'entree',
            'quantite' => $quantity->__toString(),
            'quantite_restante_avant' => '0',
            'quantite_restante_apres' => $quantity->__toString(),
            'date_mouvement' => now()->toDateString(),
        ]);
    }

    private function removeInventoryStock(produits $product, BigDecimal $quantity): void
    {
        $remaining = $quantity;
        $lots = lots::query()
            ->where('id_produit', $product->id)
            ->orderBy('date_reception')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            $issued = BigDecimal::of((string) $lot->mouvementsStock()->where('type_mouvement', 'sortie')->sum('quantite'));
            $available = BigDecimal::of((string) $lot->quantite_initial)->minus($issued);
            if (! $available->isGreaterThan(BigDecimal::of('0'))) {
                continue;
            }

            $taken = $available->isLessThan($remaining) ? $available : $remaining;
            $after = $available->minus($taken);
            mouvements_stock_fifos::create([
                'id_lot' => $lot->id,
                'type_mouvement' => 'sortie',
                'quantite' => $taken->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'quantite_restante_avant' => $available->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'quantite_restante_apres' => $after->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'date_mouvement' => now()->toDateString(),
            ]);

            $remaining = $remaining->minus($taken);
            if (! $remaining->isGreaterThan(BigDecimal::of('0'))) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'lignes' => "Le stock a changé pendant l’inventaire de {$product->nom}. Recharge le stock et recommence.",
        ]);
    }
}