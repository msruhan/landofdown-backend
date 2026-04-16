<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();
            $table->date('match_date');
            $table->string('duration')->nullable();
            $table->string('team_a_name')->default('Team A');
            $table->string('team_b_name')->default('Team B');
            $table->enum('winner', ['team_a', 'team_b']);
            $table->text('notes')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_matches');
    }
};
