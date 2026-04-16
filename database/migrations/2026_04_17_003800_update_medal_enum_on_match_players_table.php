<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `match_players` MODIFY `medal` ENUM('mvp_win', 'mvp_lose', 'gold', 'silver', 'bronze') NULL");
        DB::statement("UPDATE `match_players` SET `medal` = 'mvp_win' WHERE `medal` = 'mvp'");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `match_players` SET `medal` = 'mvp' WHERE `medal` IN ('mvp_win', 'mvp_lose')");
        DB::statement("ALTER TABLE `match_players` MODIFY `medal` ENUM('mvp', 'gold', 'silver', 'bronze') NULL");
    }
};

