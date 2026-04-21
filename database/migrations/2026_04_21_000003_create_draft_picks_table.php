<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_picks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('game_matches')->cascadeOnDelete();
            $table->enum('team', ['team_a', 'team_b']);
            $table->enum('action', ['pick', 'ban'])->default('pick');
            $table->unsignedTinyInteger('order_index');
            $table->foreignId('hero_id')->nullable()->constrained('heroes')->nullOnDelete();
            $table->timestamps();

            $table->index(['match_id', 'team']);
            $table->index(['hero_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_picks');
    }
};
