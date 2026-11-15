<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\unites as Unites;

class UniteSeeder extends Seeder
{
    public function run(): void
    {
        $unites = [
            ['nom' => 'Pièce', 'symbole' => 'pc'],
            ['nom' => 'Kilogramme', 'symbole' => 'kg'],
            ['nom' => 'Gramme', 'symbole' => 'g'],
            ['nom' => 'Litre', 'symbole' => 'L'],
            ['nom' => 'Millilitre', 'symbole' => 'mL'],
            ['nom' => 'Mètre', 'symbole' => 'm'],
            ['nom' => 'Centimètre', 'symbole' => 'cm'],
            ['nom' => 'Douzaine', 'symbole' => 'dz'],
            ['nom' => 'Carton', 'symbole' => 'ctn'],
            ['nom' => 'Paquet', 'symbole' => 'pqt'],
        ];

        foreach ($unites as $unite) {
            Unites::updateOrCreate(
                ['nom' => $unite['nom']],
                $unite
            );
        }
    }
}
