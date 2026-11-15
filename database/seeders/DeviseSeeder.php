<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\devise as Devise;

class DeviseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $devises = [
            [
                'code' => 'USD',
                'nom' => 'Dollar Américain',
                'symbole' => '$',
                'created_at' => now(),
                'updated_at' => now(),
            ],
           
            [
                'code' => 'CDF',
                'nom' => 'Franc Congolais',
                'symbole' => 'FC',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($devises as $devise) {
            Devise::updateOrCreate(
                ['code' => $devise['code']],
                $devise
            );
        }
    }
}
