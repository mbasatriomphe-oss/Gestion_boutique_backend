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
    public function sessions(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        $currentId = $currentToken instanceof \Laravel\Sanctum\PersonalAccessToken ? $currentToken->getKey() : null;
        $tokens = $request->user()->tokens()->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->get([
            'id', 'name', 'last_used_at', 'created_at', 'expires_at',
        ])->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
            'expires_at' => $token->expires_at,
            'current' => (string) $token->id === (string) $currentId,
        ]);

        return response()->json(['status' => 'success', 'data' => $tokens]);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->whereKey($id)->firstOrFail()->delete();

        return response()->json(['status' => 'success', 'message' => 'Session révoquée.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['status' => 'success', 'message' => 'Toutes les sessions ont été fermées.']);
    }

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
