<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mabar_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('type')->default('classic'); // push_rank | classic | brawl | tournament | coaching
            $table->string('vibe')->nullable(); // sweaty | chill | tryhard | learning | event
            $table->string('rank_requirement')->default('any'); // any | legend | mythic | mythic_honor | mythic_glory | mythic_immortal
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('recurrence')->default('none'); // none | weekly
            $table->json('recurrence_days')->nullable();
            $table->unsignedTinyInteger('max_slots')->default(5);
            $table->string('status')->default('open'); // open | full | live | closed | expired | cancelled
            $table->string('voice_platform')->nullable(); // discord | in_game | chat
            $table->string('discord_link')->nullable();
            $table->string('room_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mabar_sessions');
    }
};
