<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('game_matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->enum('team', ['team_a', 'team_b']);
            $table->foreignId('hero_id')->constrained('heroes');
            $table->foreignId('role_id')->constrained('roles');
            $table->integer('kills')->default(0);
            $table->integer('deaths')->default(0);
            $table->integer('assists')->default(0);
            $table->decimal('rating', 3, 1)->nullable();
            $table->enum('medal', ['mvp', 'gold', 'silver', 'bronze'])->nullable();
            $table->enum('result', ['win', 'lose']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_players');
    }
};
