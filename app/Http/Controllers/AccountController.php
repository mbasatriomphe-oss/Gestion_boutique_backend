<?php

namespace App\Http\Controllers;

use App\Models\vendeurs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $account = $request->user();
        $isSeller = $account instanceof vendeurs || ($account->role ?? null) === 'vendeur';

        $validated = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:' . ($isSeller ? 100 : 255)],
            'prenom' => ['sometimes', 'required', 'string', 'max:' . ($isSeller ? 100 : 255)],
            'post_nom' => ['sometimes', 'nullable', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'required', 'confirmed', Password::defaults()],
        ]);

        if (isset($validated['password']) && ! Hash::check($validated['current_password'], $account->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $profileFields = $isSeller
            ? ['nom', 'prenom']
            : ['nom', 'post_nom', 'prenom'];

        foreach ($profileFields as $field) {
            if (array_key_exists($field, $validated)) {
                $account->{$field} = $validated[$field];
            }
        }

        if (isset($validated['password'])) {
            $account->password = Hash::make($validated['password']);
        }

        $account->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Votre compte a été mis à jour.',
            'data' => $account->fresh(),
        ]);
    }
}
