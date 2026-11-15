<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\categories as Categories;

class CategorieSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['nom' => 'Électronique', 'description' => 'Produits électroniques'],
            ['nom' => 'Habillement', 'description' => 'Vêtements et accessoires'],
            ['nom' => 'Alimentation', 'description' => 'Produits alimentaires'],
            ['nom' => 'Mobilier', 'description' => 'Meubles et décoration'],
            ['nom' => 'Informatique', 'description' => 'Ordinateurs et accessoires'],
            ['nom' => 'Sport', 'description' => 'Articles de sport'],
        ];

        foreach ($categories as $categorie) {
            Categories::updateOrCreate(
                ['nom' => $categorie['nom']],
                $categorie
            );
        }
    }
}
