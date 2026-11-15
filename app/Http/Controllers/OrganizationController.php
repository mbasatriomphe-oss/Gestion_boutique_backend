<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;

class OrganizationController extends ApiCrudController
{
    protected string $modelClass = Organization::class;
    protected array $searchable = ['name', 'city', 'address', 'manager', 'email'];

    protected function storeRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:store,warehouse,office,branch',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zipCode' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'manager' => 'nullable|string|max:255',
            'employees' => 'required|integer|min:0',
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string|max:10000',
        ];
    }

    protected function updateRules(Model $model): array
    {
        return $this->storeRules();
    }
}
