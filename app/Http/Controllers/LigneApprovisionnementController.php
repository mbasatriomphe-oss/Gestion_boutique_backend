<?php

namespace App\Http\Controllers;

use App\Models\ligne_approvisionnements;
use App\Models\lots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LigneApprovisionnementController extends ApiCrudController
{
    protected string $modelClass = ligne_approvisionnements::class;

    protected function indexQuery(Request $request): Builder
    {
        $query = ligne_approvisionnements::with(['approvisionnement.fournisseur', 'produit', 'devise', 'lots']);

        if ($request->filled('id_approvisionnement')) {
            $query->where('id_approvisionnement', $request->integer('id_approvisionnement'));
        }

        return $query;
    }

    protected function storeRules(): array
    {
        return [
            'id_approvisionnement' => 'required|integer|exists:approvisionnements,id',
            'id_produit' => 'required|integer|exists:produits,id',
            'quantite' => 'required|numeric|gt:0',
            'prix_unitaire' => 'required|numeric|min:0',
            'prix_vente' => 'nullable|numeric|min:0',
            'id_devise' => 'required|integer|exists:devises,id',
            'paye_par_caisse' => 'sometimes|boolean',
        ];
    }

    protected function updateRules(\Illuminate\Database\Eloquent\Model $model): array
    {
        return [
            'id_approvisionnement' => 'sometimes|integer|exists:approvisionnements,id',
            'id_produit' => 'sometimes|integer|exists:produits,id',
            'quantite' => 'sometimes|numeric|gt:0',
            'prix_unitaire' => 'sometimes|numeric|min:0',
            'prix_vente' => 'nullable|numeric|min:0',
            'id_devise' => 'sometimes|integer|exists:devises,id',
            'paye_par_caisse' => 'sometimes|boolean',
        ];
    }

    /**
     * Surcharge de la méthode update pour gérer la mise à jour de la devise
     * avec mise à jour en cascade des lots associés
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ligne = ligne_approvisionnements::with(['produit', 'devise', 'lots'])->findOrFail($id);
        
        $rules = $this->updateRules($ligne);
        $validated = $request->validate($rules);
        
        // Si la devise est modifiée, on met à jour aussi les lots
        $isDeviseChanged = isset($validated['id_devise']) && $validated['id_devise'] != $ligne->id_devise;
        
        DB::transaction(function () use ($ligne, $validated, $isDeviseChanged) {
            // Mettre à jour la ligne d'approvisionnement
            $ligne->update($validated);
            
            // Si la devise a changée, mettre à jour tous les lots associés
            if ($isDeviseChanged) {
                lots::where('id_ligne_approvisionnement', $ligne->id)
                    ->update([
                        'id_devise' => $validated['id_devise']
                    ]);
            }
        });
        
        return response()->json([
            'status' => 'success',
            'data' => $ligne->fresh(['produit', 'devise', 'lots', 'approvisionnement']),
            'message' => $isDeviseChanged ? 'Devise mise à jour avec succès' : 'Ligne mise à jour avec succès'
        ]);
    }

    /**
     * Méthode pour mettre à jour uniquement la devise d'une ligne
     * Endpoint dédié pour la modification de devise
     */
    public function updateDevise(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'id_devise' => 'required|integer|exists:devises,id',
        ]);

        $ligne = ligne_approvisionnements::with(['produit', 'devise', 'lots'])->findOrFail($id);

        DB::transaction(function () use ($ligne, $validated) {
            // Mettre à jour la ligne d'approvisionnement
            $ligne->update([
                'id_devise' => $validated['id_devise']
            ]);
            
            // Mettre à jour tous les lots associés à cette ligne
            lots::where('id_ligne_approvisionnement', $ligne->id)
                ->update([
                    'id_devise' => $validated['id_devise']
                ]);
        });

        return response()->json([
            'status' => 'success',
            'data' => $ligne->fresh(['produit', 'devise', 'lots']),
            'message' => 'Devise mise à jour avec succès'
        ]);
    }

    /**
     * Méthode pour mettre à jour plusieurs lignes en une seule requête
     * Utile pour modifier la devise de plusieurs produits à la fois
     */
    public function batchUpdateDevise(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'updates' => 'required|array|min:1',
            'updates.*.id' => 'required|integer|exists:ligne_approvisionnements,id',
            'updates.*.id_devise' => 'required|integer|exists:devises,id',
        ]);

        $results = [];
        
        DB::transaction(function () use ($validated, &$results) {
            foreach ($validated['updates'] as $update) {
                $ligne = ligne_approvisionnements::findOrFail($update['id']);
                
                // Mettre à jour la ligne
                $ligne->update([
                    'id_devise' => $update['id_devise']
                ]);
                
                // Mettre à jour les lots associés
                lots::where('id_ligne_approvisionnement', $ligne->id)
                    ->update([
                        'id_devise' => $update['id_devise']
                    ]);
                
                $results[] = $ligne->fresh(['produit', 'devise']);
            }
        });

        return response()->json([
            'status' => 'success',
            'data' => $results,
            'message' => count($results) . ' ligne(s) mise(s) à jour avec succès'
        ]);
    }

    /**
     * Surcharge de la méthode destroy pour supprimer une ligne
     * avec vérification des lots associés
     */
    public function destroy(int $id): JsonResponse
    {
        $ligne = ligne_approvisionnements::with(['lots'])->findOrFail($id);
        
        // Vérifier si des lots existent
        if ($ligne->lots->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Impossible de supprimer cette ligne car des lots lui sont associés.'
            ], 422);
        }
        
        $ligne->delete();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Ligne supprimée avec succès'
        ]);
    }
}