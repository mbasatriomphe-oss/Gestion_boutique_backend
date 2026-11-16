<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer l'admin principal
        User::updateOrCreate(
            ['email' => 'triomphembasa@gmail.com'],
            [
                'nom' => 'Triomphe',
                'post_nom' => 'Mbasa',
                'prenom' => 'Kaparayi',
                'email' => 'triomphembasa@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('admin1234'),
                'role' => 'admin',
                'remember_token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Créer un utilisateur de test
        User::updateOrCreate(
            ['email' => 'user@belden.com'],
            [
                'nom' => 'belden',
                'post_nom' => 'mukigi',
                'prenom' => 'belden',
                'email' => 'beldenmukingi@gmail.com',
                'email_verified_at' => now(),
                'password' => Hash::make('admin1234'),
                'role' => 'user',
                'remember_token' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}