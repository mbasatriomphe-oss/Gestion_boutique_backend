<?php

namespace App\Http\Controllers;

use App\Models\produits;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Services\ProductUnitConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProduitController extends ApiCrudController
{
    protected string $modelClass = produits::class;
    protected array $searchable = ['code', 'nom', 'description'];

    protected function indexQuery(Request $request): Builder
    {
        $query = produits::with(['unite', 'categorie', 'unitesAchat', 'unitesVente']);

        if ($request->filled('categorie_id')) {
            $query->where('categorie_id', $request->integer('categorie_id'));
        }

        if ($request->filled('unite_id')) {
            $query->where('unite_id', $request->integer('unite_id'));
        }

        return $query;
    }

    protected function storeRules(): array
    {
        return [
            'code' => 'sometimes|string|max:50|unique:produits,code',
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string',
            'photo' => 'nullable|string|max:255',
            'photo_file' => 'nullable|file|image|max:4096',
            'unite_id' => 'required|integer|exists:unites,id',
            'unite_achat_id' => 'sometimes|required|integer|exists:unites,id',
            'facteur_achat' => 'sometimes|required|numeric|gt:0',
            'prix_achat' => 'nullable|numeric|min:0',
            'devise_achat_id' => 'nullable|integer|exists:devises,id',
            'unites_vente' => 'sometimes|array|min:1',
            'unites_vente.*.id_unite' => 'required|integer|exists:unites,id',
            'unites_vente.*.facteur' => 'required|numeric|gt:0',
            'unites_vente.*.prix' => 'nullable|numeric|min:0',
            'unites_vente.*.id_devise' => 'nullable|integer|exists:devises,id',
            'stock_initial' => 'sometimes|array',
            'stock_initial.*.id_unite' => 'required|integer|exists:unites,id',
            'stock_initial.*.quantite' => 'required|numeric|min:0',
            'seuil_alerte' => 'nullable|numeric|min:0',
            'categorie_id' => 'required|integer|exists:categories,id',
        ];
    }

    protected function updateRules(Model $model): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('produits', 'code')->ignore($model->getKey())],
            'nom' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'photo' => 'nullable|string|max:255',
            'photo_file' => 'nullable|file|image|max:4096',
            'unite_id' => 'sometimes|integer|exists:unites,id',
            'unite_achat_id' => 'sometimes|required|integer|exists:unites,id',
            'facteur_achat' => 'sometimes|required|numeric|gt:0',
            'prix_achat' => 'sometimes|nullable|numeric|min:0',
            'devise_achat_id' => 'sometimes|nullable|integer|exists:devises,id',
            'unites_vente' => 'sometimes|array|min:1',
            'unites_vente.*.id_unite' => 'required|integer|exists:unites,id',
            'unites_vente.*.facteur' => 'required|numeric|gt:0',
            'unites_vente.*.prix' => 'nullable|numeric|min:0',
            'unites_vente.*.id_devise' => 'nullable|integer|exists:devises,id',
            'seuil_alerte' => 'sometimes|nullable|numeric|min:0',
            'categorie_id' => 'sometimes|integer|exists:categories,id',
        ];
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->storeRules());
        $validated['seuil_alerte'] = $validated['seuil_alerte'] ?? 0;
        $data = $this->prepareStoreData($validated, $request);
        unset($data['unite_achat_id'], $data['facteur_achat'], $data['prix_achat'], $data['devise_achat_id'], $data['unites_vente'], $data['stock_initial']);

        $product = DB::transaction(function () use ($validated, $data) {
            $product = produits::create($data);
            $this->syncUnitConversions($product, $validated, true);
            $this->createOpeningStock($product, $validated['stock_initial'] ?? []);

            return $product->fresh(['unite', 'categorie', 'unitesAchat', 'unitesVente']);
        });

        $this->notifyCrudAction($request, 'created', $product, 'Création réussie');

        return response()->json(['status' => 'success', 'data' => $product], 201);
    }

    public function update(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $product = produits::findOrFail($id);
        $validated = $request->validate($this->updateRules($product));

        if (isset($validated['unite_id']) && (int) $validated['unite_id'] !== (int) $product->unite_id && $product->lots()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'L’unité de référence ne peut pas être modifiée après la création du stock.',
            ], 422);
        }

        $data = $this->prepareUpdateData($validated, $product, $request);
        unset($data['unite_achat_id'], $data['facteur_achat'], $data['prix_achat'], $data['devise_achat_id'], $data['unites_vente']);

        $product = DB::transaction(function () use ($product, $validated, $data) {
            $product->update($data);
            $this->syncUnitConversions($product, $validated, false);

            return $product->fresh(['unite', 'categorie', 'unitesAchat', 'unitesVente']);
        });

        $this->notifyCrudAction($request, 'updated', $product, 'Modification réussie');

        return response()->json(['status' => 'success', 'data' => $product]);
    }

    private function syncUnitConversions(produits $product, array $validated, bool $creating): void
    {
        if (! $creating && ! array_intersect(['unite_achat_id', 'facteur_achat', 'prix_achat', 'devise_achat_id', 'unites_vente'], array_keys($validated))) {
            return;
        }

        $purchase = $creating
            ? ['id_unite' => $validated['unite_achat_id'] ?? $product->unite_id, 'facteur' => $validated['facteur_achat'] ?? 1]
            : null;
        $sales = $validated['unites_vente'] ?? null;

        if (! $creating && $purchase === null && $sales === null) {
            return;
        }

        $existingPurchase = $product->unitesAchat()->first();
        $existingSales = $product->unitesVente()->get();
        $purchase = $purchase ?? [
            'id_unite' => $validated['unite_achat_id'] ?? $existingPurchase?->id_unite ?? $product->unite_id,
            'facteur' => $validated['facteur_achat'] ?? $existingPurchase?->facteur ?? 1,
            'prix' => $validated['prix_achat'] ?? $existingPurchase?->prix,
            'id_devise' => $validated['devise_achat_id'] ?? $existingPurchase?->id_devise,
        ];
        $purchase['prix'] = $validated['prix_achat'] ?? $purchase['prix'] ?? $existingPurchase?->prix;
        $purchase['id_devise'] = $validated['devise_achat_id'] ?? $purchase['id_devise'] ?? $existingPurchase?->id_devise;
        $sales = $sales ?? $existingSales->map(fn ($unit) => [
            'id_unite' => $unit->id_unite,
            'facteur' => $unit->facteur,
            'prix' => $unit->prix,
            'id_devise' => $unit->id_devise,
        ])->all();
        if ($sales === []) {
            $sales = [['id_unite' => $product->unite_id, 'facteur' => 1]];
        }

        $saleUnitIds = array_map(static fn (array $unit) => (int) $unit['id_unite'], $sales);
        if (count($saleUnitIds) !== count(array_unique($saleUnitIds))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'unites_vente' => 'Chaque unité de vente doit être unique.',
            ]);
        }

        $baseSaleUnit = collect($sales)->first(fn (array $unit) => (int) $unit['id_unite'] === (int) $product->unite_id);
        if (! $baseSaleUnit || (float) $baseSaleUnit['facteur'] !== 1.0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'unites_vente' => 'L’unité de référence doit rester disponible à la vente avec un facteur de 1.',
            ]);
        }

        foreach ($sales as $unit) {
            if ((int) $unit['id_unite'] === (int) $purchase['id_unite'] && abs((float) $unit['facteur'] - (float) $purchase['facteur']) > 0.00000001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'unites_vente' => 'Le facteur d’une même unité doit être identique à l’achat et à la vente.',
                ]);
            }
        }

        $product->unites()->delete();
        $product->unites()->create([
            'id_unite' => (int) $purchase['id_unite'],
            'type' => 'achat',
            'facteur' => $purchase['facteur'],
            'prix' => $purchase['prix'] ?? null,
            'id_devise' => $purchase['id_devise'] ?? null,
        ]);

        foreach ($sales as $unit) {
            $product->unites()->create([
                'id_unite' => (int) $unit['id_unite'],
                'type' => 'vente',
                'facteur' => $unit['facteur'],
                'prix' => $unit['prix'] ?? null,
                'id_devise' => $unit['id_devise'] ?? null,
            ]);
        }
    }

    private function createOpeningStock(produits $product, array $stockLines): void
    {
        $stockQuantity = BigDecimal::of('0');
        $conversionService = app(ProductUnitConversionService::class);
        $purchaseUnit = $product->unitesAchat()->first();

        foreach ($stockLines as $line) {
            $unitId = (int) $line['id_unite'];
            $conversion = $product->unitesAchat()->where('id_unite', $unitId)->first()
                ?? $product->unitesVente()->where('id_unite', $unitId)->first();
            $factor = $conversion?->facteur ?? ((int) $product->unite_id === $unitId ? '1' : null);

            if ($factor === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'stock_initial' => 'Une unité de stock initial n’est pas configurée pour ce produit.',
                ]);
            }

            if ((float) $line['quantite'] > 0) {
                $stockQuantity = $stockQuantity->plus(BigDecimal::of($conversionService->quantityInStockUnit($line['quantite'], $factor)));
            }
        }

        if (! $stockQuantity->isGreaterThan(BigDecimal::of('0'))) {
            return;
        }

        $currencyId = $purchaseUnit?->id_devise ?? DB::table('devises')->orderBy('id')->value('id');
        if (! $currencyId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'devise_achat_id' => 'Configure au moins une devise avant d’enregistrer le stock initial.',
            ]);
        }

        $stockQuantity = $stockQuantity->toScale(8, RoundingMode::HALF_UP);
        $purchaseCost = $purchaseUnit?->prix !== null
            ? $conversionService->pricePerStockUnit($purchaseUnit->prix, $purchaseUnit->facteur)
            : null;
        do {
            $lotNumber = sprintf('INIT-%s-%05d-%04d', now()->format('YmdHis'), $product->id, random_int(0, 9999));
        } while (lots::query()->where('numero_lot', $lotNumber)->exists());

        $lot = lots::create([
            'numero_lot' => $lotNumber,
            'id_produit' => $product->id,
            'id_approvisionnement' => null,
            'id_ligne_approvisionnement' => null,
            'quantite_initial' => $stockQuantity->__toString(),
            'prix_unitaire_stock' => $purchaseCost,
            'date_reception' => now()->toDateString(),
            'date_expiration' => null,
            'id_devise' => $currencyId,
        ]);

        mouvements_stock_fifos::create([
            'id_lot' => $lot->id,
            'type_mouvement' => 'entree',
            'quantite' => $stockQuantity->__toString(),
            'quantite_restante_avant' => '0',
            'quantite_restante_apres' => $stockQuantity->__toString(),
            'date_mouvement' => now()->toDateString(),
        ]);
    }

    protected function prepareStoreData(array $validated, Request $request): array
    {
        $validated['photo'] = $this->storePhoto($request, $validated['photo'] ?? null);

        if (empty($validated['code'])) {
            $validated['code'] = $this->generateUniqueCode('produits', 'code', 'PRO');
        }

        unset($validated['photo_file']);

        return $validated;
    }

    protected function prepareUpdateData(array $validated, Model $model, Request $request): array
    {
        $validated['photo'] = $this->storePhoto($request, $validated['photo'] ?? $model->getAttribute('photo'));
        unset($validated['photo_file']);

        return $validated;
    }

    private function storePhoto(Request $request, ?string $currentValue): ?string
    {
        $photoFile = $request->file('photo_file');

        if ($photoFile instanceof UploadedFile) {
            return $photoFile->store('produits', 'public');
        }

        return $currentValue !== null ? trim($currentValue) : null;
    }
}