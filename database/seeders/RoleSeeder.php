<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $legacyToCanonical = [
            'Gold Lane' => 'gold',
            'Gold Laner' => 'gold',
            'Mid Lane' => 'mid',
            'Mid Laner' => 'mid',
            'Exp Lane' => 'exp',
            'EXP Lane' => 'exp',
            'EXP Laner' => 'exp',
            'Roamer' => 'roam',
            'Jungler' => 'jungle',
        ];

        foreach ($legacyToCanonical as $legacy => $canonical) {
            $legacyRole = Role::where('name', $legacy)->first();
            if ($legacyRole && !Role::where('name', $canonical)->exists()) {
                $legacyRole->update(['name' => $canonical]);
            }
        }

        $roles = ['mid', 'exp', 'gold', 'roam', 'jungle'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }
}
