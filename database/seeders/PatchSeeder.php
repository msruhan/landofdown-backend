<?php

namespace Database\Seeders;

use App\Models\Patch;
use Illuminate\Database\Seeder;

class PatchSeeder extends Seeder
{
    public function run(): void
    {
        $patches = [
            [
                'version' => '1.8.44',
                'name' => 'Project NEXT – Lesley Revamp',
                'release_date' => now()->subDays(90)->format('Y-m-d'),
                'notes' => 'Lesley revamp, Chip release, jungle economy tweak.',
            ],
            [
                'version' => '1.8.52',
                'name' => 'Tank Meta Shift',
                'release_date' => now()->subDays(60)->format('Y-m-d'),
                'notes' => 'Tank items buff, Benedetta nerf, new marksman emblem tree.',
            ],
            [
                'version' => '1.8.60',
                'name' => 'Mage Era',
                'release_date' => now()->subDays(30)->format('Y-m-d'),
                'notes' => 'Glowing Wand rework, Cecilion buff, roamer gold rules updated.',
            ],
            [
                'version' => '1.8.66',
                'name' => 'Current Patch',
                'release_date' => now()->subDays(15)->format('Y-m-d'),
                'notes' => 'Assassins buff, fighter sustain nerf, new Chou skill animation.',
            ],
        ];

        foreach ($patches as $patch) {
            Patch::firstOrCreate(['version' => $patch['version']], $patch);
        }
    }
}
