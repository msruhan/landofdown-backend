<?php

namespace Database\Seeders;

use App\Models\Hero;
use App\Models\Role;
use Illuminate\Database\Seeder;

class HeroSeeder extends Seeder
{
    public function run(): void
    {
        $heroMap = [
            'gold' => ['Lesley', 'Bruno', 'Brody', 'Beatrix', 'Melissa', 'Wanwan'],
            'exp' => ['Yu Zhong', 'Esmeralda', 'Thamuz', 'Guinevere', 'Paquito', 'Cici'],
            'mid' => ['Yve', 'Valentina', 'Xavier', 'Pharsa', 'Kagura', 'Lunox'],
            'jungle' => ['Ling', 'Fanny', 'Lancelot', 'Hayabusa', 'Aamon', 'Joy'],
            'roam' => ['Khufra', 'Atlas', 'Chou', 'Tigreal', 'Franco', 'Akai'],
        ];

        foreach ($heroMap as $roleName => $heroes) {
            $role = Role::where('name', $roleName)->first();

            foreach ($heroes as $heroName) {
                Hero::firstOrCreate(
                    ['name' => $heroName],
                    ['role_id' => $role?->id]
                );
            }
        }
    }
}
