<?php

namespace App\Http\Controllers;

use App\Models\vendeurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class VendeurController extends ApiCrudController
{
    protected string $modelClass = vendeurs::class;

    protected function indexQuery(Request $request): Builder
    {
        return vendeurs::withCount('ventes');
    }

    protected function storeRules(): array
    {
        return [
            'nom'       => 'required|string|max:100',
            'prenom'    => 'required|string|max:100',
            'email'     => 'required|email|max:255|unique:vendeurs,email',
            'password'  => 'required|string|min:6',
            'telephone' => 'nullable|string|max:20',
            'adresse'   => 'nullable|string|max:255',
        ];
    }

    protected function updateRules(Model $model): array
    {
        return [
            'nom'       => 'sometimes|string|max:100',
            'prenom'    => 'sometimes|string|max:100',
            'email'     => ['sometimes', 'email', 'max:255', Rule::unique('vendeurs', 'email')->ignore($model->getKey())],
            'password'  => 'sometimes|string|min:6|confirmed',
            'password_confirmation' => 'required_with:password|string|min:6',
            'telephone' => 'nullable|string|max:20',
            'adresse'   => 'nullable|string|max:255',
        ];
    }

    protected function prepareStoreData(array $validated, Request $request): array
    {
        $validated['password'] = Hash::make($validated['password']);
        $validated['code']     = $this->generateCode();

        return $validated;
    }

    protected function prepareUpdateData(array $validated, Model $model, Request $request): array
    {
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        return $validated;
    }

    private function generateCode(): string
    {
        $last = vendeurs::orderByDesc('id')->value('id') ?? 0;

        return 'V' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
    }
}
