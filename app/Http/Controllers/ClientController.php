<?php

namespace App\Http\Controllers;

use App\Models\clients;
use App\Models\ventes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends ApiCrudController
{
    protected string $modelClass = clients::class;
    protected array $searchable = ['nom', 'post_nom', 'prenom', 'contact', 'email', 'ville'];

    protected function indexQuery(Request $request): Builder
    {
        return clients::withCount('ventes');
    }

    protected function storeRules(): array
    {
        return [
            'nom'      => 'required|string|max:90',
            'post_nom' => 'nullable|string|max:90',
            'prenom'   => 'nullable|string|max:90',
            'adresse'  => 'nullable|string|max:63',
            'ville'    => 'nullable|string|max:50',
            'pays'     => 'nullable|string|max:50',
            'contact'  => 'nullable|string|max:50',
            'email'    => 'nullable|email|max:255',
            'iduser'   => 'nullable|integer|exists:users,id',
        ];
    }

    protected function updateRules(\Illuminate\Database\Eloquent\Model $model): array
    {
        return [
            'nom'      => 'sometimes|string|max:90',
            'post_nom' => 'sometimes|nullable|string|max:90',
            'prenom'   => 'sometimes|nullable|string|max:90',
            'adresse'  => 'sometimes|nullable|string|max:63',
            'ville'    => 'sometimes|nullable|string|max:50',
            'pays'     => 'sometimes|nullable|string|max:50',
            'contact'  => 'sometimes|nullable|string|max:50',
            'email'    => 'sometimes|nullable|email|max:255',
            'iduser'   => 'nullable|integer|exists:users,id',
        ];
    }

    public function debts(int $id): JsonResponse
    {
        $client = clients::findOrFail($id);

        $debts = ventes::query()
            ->with(['deviseVente', 'vendeur'])
            ->where('id_client', $client->id)
            ->where('reste_a_payer', '>', 0)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'client' => $client,
                'ventes' => $debts,
                'total_dette' => $debts->sum(fn (ventes $vente) => (float) $vente->reste_a_payer),
                'nombre_dettes' => $debts->count(),
            ],
        ]);
    }
}