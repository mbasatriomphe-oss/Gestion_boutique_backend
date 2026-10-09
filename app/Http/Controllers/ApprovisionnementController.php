<?php

namespace App\Http\Controllers;

use App\Models\approvisionnements;
use App\Models\caisse;
use App\Models\ligne_approvisionnements;
use App\Models\lots;
use App\Models\mouvements_stock_fifos;
use App\Models\produits;
use App\Services\ProductUnitConversionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ApprovisionnementController extends ApiCrudController
{
    protected string $modelClass = approvisionnements::class;
    protected array $searchable = ['code'];

    protected function indexQuery(Request $request): Builder
    {
        $query = approvisionnements::with(['fournisseur', 'user', 'lignes.produit.unite', 'lignes.unite', 'lignes.lots']);

        if ($request->filled('id_fournisseur')) {
            $query->where('id_fournisseur', $request->integer('id_fournisseur'));
        }

        if ($request->filled('id_user')) {
            $query->where('id_user', $request->integer('id_user'));
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->date('date'));
        }

        return $query;
    }

    protected function storeRules(): array
    {
        return [
            'code' => 'sometimes|string|max:50|unique:approvisionnements,code',
            'date' => 'required|date',
            'id_user' => 'required|integer|exists:users,id',
            'id_fournisseur' => 'required|integer|exists:fournisseurs,id',
            'lignes' => 'required|array|min:1',
            'lignes.*.id_produit' => 'required|integer|exists:produits,id',
            'lignes.*.quantite' => 'required|numeric|gt:0',
            'lignes.*.id_unite' => 'sometimes|integer|exists:unites,id',
            'lignes.*.prix_unitaire' => 'required|numeric|min:0',
            'lignes.*.prix_vente' => 'nullable|numeric|min:0',
            'lignes.*.id_devise' => 'required|integer|exists:devises,id',
            'lignes.*.paye_par_caisse' => 'sometimes|boolean',
        ];
    }

    protected function updateRules(Model $model): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('approvisionnements', 'code')->ignore($model->getKey())],
            'date' => 'sometimes|date',
            'id_user' => 'sometimes|integer|exists:users,id',
            'id_fournisseur' => 'sometimes|integer|exists:fournisseurs,id',
        ];
    }

    public function store(Request $request): 
    JsonResponse
    {
        $rules = $this->storeRules();
        $validated = $request->validate($rules);

        if ($validated['date'] !== now()->toDateString()) {
            return response()->json([
                'status' => 'error',
                'message' => 'La date d\'un approvisionnement doit être celle du jour.',
            ], 422);
        }

        $lineItems = $validated['lignes'];
        $productIds = array_map(static fn (array $line) => (int) $line['id_produit'], $lineItems);

        $debitTotalsByDevise = [];
        foreach ($lineItems as $lineItem) {
            if (! empty($lineItem['paye_par_caisse'])) {
                $deviseId = (int) $lineItem['id_devise'];
                $montant = (float) $lineItem['quantite'] * (float) $lineItem['prix_unitaire'];
                $debitTotalsByDevise[$deviseId] = ($debitTotalsByDevise[$deviseId] ?? 0.0) + $montant;
            }
        }

        if (! empty($debitTotalsByDevise)) {
            $caisses = caisse::query()
                ->whereIn('id_devise', array_keys($debitTotalsByDevise))
                ->get()
                ->keyBy('id_devise');

            foreach ($debitTotalsByDevise as $deviseId => $montantTotal) {
                $caisse = $caisses->get($deviseId);
                if (! $caisse) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Aucune caisse configurée pour la devise id={$deviseId}.",
                    ], 422);
                }

                if ((float) $caisse->solde < $montantTotal) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Solde insuffisant dans la caisse pour la devise id={$deviseId}.",
                    ], 422);
                }
            }
        }

        $products = produits::with(['unitesAchat', 'unitesVente'])->whereIn('id', $productIds)->get()->keyBy('id');
        foreach ($lineItems as &$lineItem) {
            $product = $products->get((int) $lineItem['id_produit']);
            $unitId = (int) ($lineItem['id_unite'] ?? $product->unite_id);
            $conversion = $product->unitesAchat->firstWhere('id_unite', $unitId)
                ?? $product->unitesVente->firstWhere('id_unite', $unitId);

            if (! $conversion && $unitId === (int) $product->unite_id) {
                $factor = 1.0;
            } elseif ($conversion) {
                $factor = (float) $conversion->facteur;
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => "L’unité d’achat choisie n’est pas configurée pour {$product->nom}.",
                ], 422);
            }

            $lineItem['id_unite'] = $unitId;
            $lineItem['facteur_conversion'] = $factor;
            $conversionService = app(ProductUnitConversionService::class);
            $lineItem['quantite_stock'] = $conversionService->quantityInStockUnit($lineItem['quantite'], $factor);
            $lineItem['prix_unitaire_stock'] = $conversionService->pricePerStockUnit($lineItem['prix_unitaire'], $factor);
            $lineItem['prix_vente_stock'] = isset($lineItem['prix_vente'])
                ? $conversionService->pricePerStockUnit($lineItem['prix_vente'], $factor)
                : null;
        }
        unset($lineItem);

        $payload = [
            'code' => $validated['code'] ?? null,
            'date' => $validated['date'],
            'id_user' => $validated['id_user'],
            'id_fournisseur' => $validated['id_fournisseur'],
        ];

        $created = DB::transaction(function () use ($payload, $lineItems) {
            if (empty($payload['code'])) {
                $payload['code'] = $this->generateUniqueCode('approvisionnements', 'code', 'APP');
            }

            /** @var approvisionnements $approvisionnement */
            $approvisionnement = approvisionnements::create($payload);

            $createdLines = [];

            foreach ($lineItems as $lineItem) {
                $line = ligne_approvisionnements::create([
                    'id_approvisionnement' => $approvisionnement->id,
                    'id_produit' => (int) $lineItem['id_produit'],
                    'id_unite' => (int) $lineItem['id_unite'],
                    'quantite' => $lineItem['quantite'],
                    'facteur_conversion' => $lineItem['facteur_conversion'],
                    'quantite_stock' => $lineItem['quantite_stock'],
                    'prix_unitaire' => $lineItem['prix_unitaire'],
                    'prix_unitaire_stock' => $lineItem['prix_unitaire_stock'],
                    'prix_vente' => $lineItem['prix_vente'] ?? null,
                    'prix_vente_stock' => $lineItem['prix_vente_stock'],
                    'id_devise' => (int) $lineItem['id_devise'],
                    'paye_par_caisse' => isset($lineItem['paye_par_caisse']) ? (bool) $lineItem['paye_par_caisse'] : false,
                ]);

                $lot = lots::create([
                    'numero_lot' => $this->generateLotNumber($approvisionnement->date, (int) $lineItem['id_produit']),
                    'id_produit' => (int) $lineItem['id_produit'],
                    'id_approvisionnement' => $approvisionnement->id,
                    'id_ligne_approvisionnement' => $line->id,
                    'quantite_initial' => $lineItem['quantite_stock'],
                    'date_reception' => $approvisionnement->date,
                    'date_expiration' => null,
                    'id_devise' => (int) $lineItem['id_devise'],
                ]);

                mouvements_stock_fifos::create([
                    'id_lot' => $lot->id,
                    'type_mouvement' => 'entree',
                    'quantite' => $lineItem['quantite_stock'],
                    'quantite_restante_avant' => 0,
                    'quantite_restante_apres' => $lineItem['quantite_stock'],
                    'date_mouvement' => $approvisionnement->date,
                ]);

                $createdLines[] = $line->fresh(['produit', 'devise', 'lots']);
            }

            return $approvisionnement->fresh(['fournisseur', 'user', 'lignes.produit.unite', 'lignes.unite', 'lignes.devise', 'lignes.lots']);
        });

        return response()->json([
            'status' => 'success',
            'data' => $created,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $approvisionnement = approvisionnements::findOrFail($id);
        $validated = $request->validate($this->updateRules($approvisionnement));

        if (array_key_exists('date', $validated) && $validated['date'] !== now()->toDateString()) {
            return response()->json([
                'status' => 'error',
                'message' => 'La date d\'un approvisionnement doit être celle du jour.',
            ], 422);
        }

        $approvisionnement->update($validated);

        return response()->json([
            'status' => 'success',
            'data' => $approvisionnement->fresh(['fournisseur', 'user', 'lignes.produit.unite', 'lignes.unite', 'lignes.devise', 'lignes.lots']),
        ]);
    }

    private function generateLotNumber(string $date, int $productId): string
    {
        do {
            $number = sprintf('LOT-%s-%05d-%04d', str_replace('-', '', $date), $productId, random_int(0, 9999));
        } while (lots::query()->where('numero_lot', $number)->exists());

        return $number;
    }
}