<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_matches', function (Blueprint $table) {
            $table->foreignId('patch_id')
                ->nullable()
                ->after('winner')
                ->constrained('patches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_matches', function (Blueprint $table) {
            if (Schema::hasColumn('game_matches', 'patch_id')) {
                $table->dropConstrainedForeignId('patch_id');
            }
        });
    }
};
