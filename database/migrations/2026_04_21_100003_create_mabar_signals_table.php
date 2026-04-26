<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mabar_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('active_until');
            $table->string('mood_tag')->nullable(); // chill | sweaty | tryhard | learning | event
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('active_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mabar_signals');
    }
};
