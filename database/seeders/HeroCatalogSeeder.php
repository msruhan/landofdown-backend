<?php

namespace Database\Seeders;

use App\Models\Hero;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class HeroCatalogSeeder extends Seeder
{
    /**
     * Seed 127 heroes from database/data/heroes.json (same data as database/mlbb_stats_dump.sql).
     */
    public function run(): void
    {
        $path = database_path('data/heroes.json');
        if (! File::isReadable($path)) {
            $this->command?->warn('Skipping HeroCatalogSeeder: database/data/heroes.json not found.');

            return;
        }

        /** @var list<array{name: string, hero_role?: string|null, lane?: string|null}> $catalog */
        $catalog = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        $roleByLane = Role::query()->pluck('id', 'name');

        foreach ($catalog as $row) {
            $lane = $row['lane'] ?? null;
            $roleId = $lane ? ($roleByLane[$lane] ?? null) : null;

            Hero::updateOrCreate(
                ['name' => $row['name']],
                [
                    'hero_role' => $row['hero_role'] ?? null,
                    'lane' => $lane,
                    'role_id' => $roleId,
                ]
            );
        }
    }
}
