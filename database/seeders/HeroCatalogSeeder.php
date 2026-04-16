<?php

namespace Database\Seeders;

use App\Models\Hero;
use App\Models\Role;
use Illuminate\Database\Seeder;

class HeroCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['Saber', 'Assassin'],
            ['Karina', 'Assassin'],
            ['Fanny', 'Assassin'],
            ['Hayabusa', 'Assassin'],
            ['Natalia', 'Assassin'],
            ['Lancelot', 'Assassin'],
            ['Helcurt', 'Assassin'],
            ['Gusion', 'Assassin'],
            ['Hanzo', 'Assassin'],
            ['Ling', 'Assassin'],
            ['Aamon', 'Assassin'],
            ['Joy', 'Assassin'],
            ['Nolan', 'Assassin'],
            ['Benedetta', 'Assassin/Fighter'],
            ['Harley', 'Assassin/Mage'],
            ['Selena', 'Assassin/Mage'],
            ['Yi Shun Shin', 'Assassin/Marksman'],
            ['Suyou', 'Assassin/Fighter'],
            ['Bane', 'Fighter/Mage'],
            ['Julian', 'Fighter/Mage'],
            ['Nana', 'Mage'],
            ['Eudora', 'Mage'],
            ['Gord', 'Mage'],
            ['Kagura', 'Mage'],
            ['Cyclops', 'Mage'],
            ['Aurora', 'Mage'],
            ['Vexana', 'Mage'],
            ['Odette', 'Mage'],
            ['Zhask', 'Mage'],
            ['Pharsa', 'Mage'],
            ['Valir', 'Mage'],
            ["Chang'e", 'Mage'],
            ['Vale', 'Mage'],
            ['Lunox', 'Mage'],
            ['Harith', 'Mage'],
            ['Lylia', 'Mage'],
            ['Cecilion', 'Mage'],
            ['Luo Yi', 'Mage'],
            ['Yve', 'Mage'],
            ['Valentina', 'Mage'],
            ['Xavier', 'Mage'],
            ['Novaria', 'Mage'],
            ['Kadita', 'Mage/Assassin'],
            ['Alice', 'Mage/Tank'],
            ['Esmeralda', 'Mage/Tank'],
            ['Zhuxin', 'Mage'],
            ['Balmond', 'Fighter'],
            ['Freya', 'Fighter'],
            ['Chou', 'Fighter'],
            ['Sun', 'Fighter'],
            ['Alpha', 'Fighter'],
            ['Lapu Lapu', 'Fighter'],
            ['Argus', 'Fighter'],
            ['Jawhead', 'Fighter'],
            ['Martis', 'Fighter'],
            ['Aldous', 'Fighter'],
            ['Leomord', 'Fighter'],
            ['Thamuz', 'Fighter'],
            ['Minsitthar', 'Fighter'],
            ['Badang', 'Fighter'],
            ['Guinevere', 'Fighter'],
            ['Terizla', 'Fighter'],
            ['XBorg', 'Fighter'],
            ['Dyrroth', 'Fighter'],
            ['Silvanna', 'Fighter'],
            ['Yu Zhong', 'Fighter'],
            ['Khaleed', 'Fighter'],
            ['Phoveus', 'Fighter'],
            ['Aulus', 'Fighter'],
            ['Cici', 'Fighter'],
            ['Alucard', 'Fighter/Assassin'],
            ['Zilong', 'Fighter/Assassin'],
            ['Paquito', 'Fighter/Assassin'],
            ['Yin', 'Fighter/Assassin'],
            ['Arlott', 'Fighter/Assassin'],
            ['Roger', 'Fighter/Marksman'],
            ['Ruby', 'Fighter/Tank'],
            ['Hilda', 'Fighter/Tank'],
            ['Masha', 'Fighter/Tank'],
            ['Fredrinn', 'Fighter/Tank'],
            ['Lukas', 'Fighter'],
            ['Miya', 'Marksman'],
            ['Bruno', 'Marksman'],
            ['Clint', 'Marksman'],
            ['Layla', 'Marksman'],
            ['Moskov', 'Marksman'],
            ['Irithel', 'Marksman'],
            ['Hanabi', 'Marksman'],
            ['Claude', 'Marksman'],
            ['Granger', 'Marksman'],
            ['WanWan', 'Marksman'],
            ['Popol and Kupa', 'Marksman'],
            ['Brody', 'Marksman'],
            ['Beatrix', 'Marksman'],
            ['Natan', 'Marksman'],
            ['Melissa', 'Marksman'],
            ['Ixia', 'Marksman'],
            ['Lesley', 'Marksman/Assassin'],
            ['Kimmy', 'Marksman/Mage'],
            ['Rafaela', 'Support'],
            ['Estes', 'Support'],
            ['Diggie', 'Support'],
            ['Angela', 'Support'],
            ['Floryn', 'Support'],
            ['Mathilda', 'Support/Assassin'],
            ['Kaja', 'Support/Fighter'],
            ['Faramis', 'Support/Mage'],
            ['Lolita', 'Support/Tank'],
            ['Carmilla', 'Support/Tank'],
            ['Chip', 'Support/Tank'],
            ['Tigreal', 'Tank'],
            ['Akai', 'Tank'],
            ['Franco', 'Tank'],
            ['Hylos', 'Tank'],
            ['Uranus', 'Tank'],
            ['Belerick', 'Tank'],
            ['Khufra', 'Tank'],
            ['Baxia', 'Tank'],
            ['Atlas', 'Tank'],
            ['Gloo', 'Tank'],
            ['Gatot Kaca', 'Tank/Fighter'],
            ['Grock', 'Tank/Fighter'],
            ['Barats', 'Tank/Fighter'],
            ['Edith', 'Tank/Marksman'],
            ['Minotaur', 'Tank/Support'],
            ['Johnson', 'Tank/Support'],
        ];

        $roleByLane = Role::query()->pluck('id', 'name');

        foreach ($catalog as [$name, $heroRole]) {
            $lane = $this->guessLaneFromRole($heroRole);
            $roleId = $lane ? ($roleByLane[$lane] ?? null) : null;

            Hero::updateOrCreate(
                ['name' => $name],
                [
                    'hero_role' => $heroRole,
                    'lane' => $lane,
                    'role_id' => $roleId,
                ]
            );
        }
    }

    private function guessLaneFromRole(string $heroRole): ?string
    {
        $normalized = strtolower($heroRole);

        if (str_contains($normalized, 'assassin')) {
            return 'jungle';
        }
        if (str_contains($normalized, 'marksman')) {
            return 'gold';
        }
        if (str_contains($normalized, 'mage')) {
            return 'mid';
        }
        if (str_contains($normalized, 'support') || str_contains($normalized, 'tank')) {
            return 'roam';
        }
        if (str_contains($normalized, 'fighter')) {
            return 'exp';
        }

        return null;
    }
}

