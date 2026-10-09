<?php

namespace App\Http\Controllers;

use App\Models\lots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LotController extends ApiCrudController
{
    protected string $modelClass = lots::class;

    protected function indexQuery(Request $request): Builder
    {
        $query = lots::with(['produit.unite', 'approvisionnement.fournisseur', 'ligneApprovisionnement.unite', 'devise']);

        if ($request->filled('id_produit')) {
            $query->where('id_produit', $request->integer('id_produit'));
        }

        if ($request->filled('id_approvisionnement')) {
            $query->where('id_approvisionnement', $request->integer('id_approvisionnement'));
        }

        return $query;
    }

    protected function storeRules(): array
    {
        return [
            'numero_lot' => 'sometimes|string|max:50|unique:lots,numero_lot',
            'id_produit' => 'required|integer|exists:produits,id',
            'id_approvisionnement' => 'required|integer|exists:approvisionnements,id',
            'id_ligne_approvisionnement' => 'required|integer|exists:ligne_approvisionnements,id',
            'quantite_initial' => 'required|numeric|min:0',
            'date_reception' => 'required|date',
            'date_expiration' => 'nullable|date',
            'id_devise' => 'required|integer|exists:devises,id',
        ];
    }

    protected function updateRules(Model $model): array
    {
        return [
            'numero_lot' => ['sometimes', 'string', 'max:50', Rule::unique('lots', 'numero_lot')->ignore($model->getKey())],
            'id_produit' => 'sometimes|integer|exists:produits,id',
            'id_approvisionnement' => 'sometimes|integer|exists:approvisionnements,id',
            'id_ligne_approvisionnement' => 'sometimes|integer|exists:ligne_approvisionnements,id',
            'quantite_initial' => 'sometimes|numeric|min:0',
            'date_reception' => 'sometimes|date',
            'date_expiration' => 'nullable|date',
            'id_devise' => 'sometimes|integer|exists:devises,id',
        ];
    }

    protected function prepareStoreData(array $validated, Request $request): array
    {
        if (empty($validated['numero_lot'])) {
            $validated['numero_lot'] = $this->generateUniqueCode('lots', 'numero_lot', 'LOT');
        }

        return $validated;
    }
}