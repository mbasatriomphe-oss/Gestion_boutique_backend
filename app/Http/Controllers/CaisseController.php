<?php

namespace App\Http\Controllers;

use App\Models\caisse;
use App\Models\transactions_caisses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaisseController extends ApiCrudController
{
    protected string $modelClass = caisse::class;

    protected function storeRules(): array
    {
        return [
            'id_devise' => 'required|integer|exists:devises,id',
            'solde' => 'required|numeric',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->storeRules());

        if (caisse::query()->where('id_devise', $validated['id_devise'])->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Une caisse existe déjà pour cette devise.',
            ], 422);
        }

        $caisse = caisse::create($validated);

        return response()->json([
            'status' => 'success',
            'data' => $caisse,
        ], 201);
    }

    public function byDevise(int $idDevise): JsonResponse
    {
        $caisse = caisse::query()
            ->with(['devise', 'transactions'])
            ->where('id_devise', $idDevise)
            ->first();

        if (! $caisse) {
            return response()->json([
                'status' => 'error',
                'message' => 'Caisse introuvable pour cette devise.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $caisse,
        ]);
    }

    public function credit(Request $request, int $id): JsonResponse
    {
        return $this->applyMovement($request, $id, 'entree');
    }

    public function debit(Request $request, int $id): JsonResponse
    {
        return $this->applyMovement($request, $id, 'sortie');
    }

    protected function updateRules(\Illuminate\Database\Eloquent\Model $model): array
    {
        return [
            'id_devise' => 'sometimes|integer|exists:devises,id',
            'solde' => 'sometimes|numeric',
        ];
    }

    private function applyMovement(Request $request, int $id, string $type): JsonResponse
    {
        $validated = $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'reference_type' => 'required|string|max:255',
            'reference_id' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'created_by' => 'nullable|integer|exists:users,id',
        ]);

        return DB::transaction(function () use ($validated, $id, $type) {
            $caisse = caisse::query()->lockForUpdate()->findOrFail($id);
            $soldeAvant = (float) $caisse->solde;
            $montant = (float) $validated['montant'];

            if ($type === 'sortie' && $soldeAvant < $montant) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solde insuffisant dans la caisse.',
                ], 422);
            }

            $soldeApres = $type === 'entree'
                ? $soldeAvant + $montant
                : $soldeAvant - $montant;

            $caisse->update(['solde' => $soldeApres]);

            $transaction = transactions_caisses::create([
                'id_caisse' => $caisse->getKey(),
                'type' => $type,
                'montant' => $montant,
                'reference_type' => $validated['reference_type'],
                'reference_id' => $validated['reference_id'],
                'description' => $validated['description'] ?? null,
                'solde_avant' => $soldeAvant,
                'solde_apres' => $soldeApres,
                'created_by' => $validated['created_by'] ?? auth()->id(),
            ]);

            if ($validated['reference_type'] === 'sortie_caisse') {
                $transaction->update(['reference_id' => $transaction->getKey()]);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'caisse' => $caisse->fresh(),
                    'transaction' => $transaction,
                ],
            ]);
        });
    }
}
