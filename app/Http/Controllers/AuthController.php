<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur (user normal)
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'post_nom' => 'nullable|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'nom' => $validated['nom'],
            'post_nom' => $validated['post_nom'] ?? null,
            'prenom' => $validated['prenom'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user', // Par défaut, rôle user
        ]);

        // Créer un token Sanctum pour l'utilisateur
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Inscription réussie',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer'
        ], 201);
    }

    /**
     * Création d'un compte admin (à protéger ou à désactiver après usage)
     */
    public function registerAdmin(Request $request)
    {
        // Option 1: Protéger par un mot de passe secret
        $request->validate([
            'secret' => 'required|string|in:' . env('ADMIN_SECRET_KEY', 'admin123'),
        ]);

        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'post_nom' => 'nullable|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'nom' => $validated['nom'],
            'post_nom' => $validated['post_nom'] ?? null,
            'prenom' => $validated['prenom'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Compte admin créé avec succès',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer'
        ], 201);
    }

    /**
     * Connexion
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Email ou mot de passe incorrect'
                ], 401);
            }

            $user = User::where('email', $request->email)->firstOrFail();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Connexion réussie',
                'user' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'email' => $user->email,
                    'role' => $user->role
                ],
                'token' => $token,
                'token_type' => 'Bearer'
            ]);
        } catch (QueryException $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Base de données momentanément indisponible. Vérifiez la connexion Render vers Aiven.',
            ], 503);
        }
    }

    /**
     * Connexion vendeur (via table vendeurs : email + password)
     */
    public function loginVendeur(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $vendeur = \App\Models\vendeurs::where('email', $request->email)->first();

        if (!$vendeur || !Hash::check($request->password, $vendeur->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Email ou mot de passe vendeur incorrect',
            ], 401);
        }

        $token = $vendeur->createToken('vendeur_auth_token')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Connexion vendeur réussie',
            'user' => [
                'id'        => $vendeur->id,
                'nom'       => $vendeur->nom,
                'prenom'    => $vendeur->prenom,
                'email'     => $vendeur->email,
                'code'      => $vendeur->code,
                'telephone' => $vendeur->telephone,
                'role'      => 'vendeur',
            ],
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Déconnexion réussie'
        ]);
    }
}