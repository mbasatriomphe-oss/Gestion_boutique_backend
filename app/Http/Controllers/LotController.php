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
        if ($request->boolean('latest_per_product')) {
            // POS needs the newest pricing per product, never the entire lot history.
            $query = lots::query()
                ->select(['id', 'id_produit', 'id_devise', 'id_ligne_approvisionnement'])
                ->whereIn('id', lots::query()->selectRaw('MAX(id)')->groupBy('id_produit'))
                ->with([
                    'ligneApprovisionnement:id,prix_vente,prix_unitaire,prix_vente_stock,prix_unitaire_stock,facteur_conversion',
                    'devise:id,code,nom,symbole',
                ]);
        } else {
            $query = lots::with(['produit.unite', 'approvisionnement.fournisseur', 'ligneApprovisionnement.unite', 'ligneApprovisionnement.devise', 'devise']);
        }

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
