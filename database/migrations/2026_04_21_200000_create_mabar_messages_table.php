<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mabar_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('mabar_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind')->default('text'); // text | system | quick | gif
            $table->text('body');
            $table->json('reactions')->nullable(); // {"🔥":[uid,...],"👍":[uid]}
            $table->foreignId('reply_to_id')->nullable()->constrained('mabar_messages')->nullOnDelete();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        Schema::table('mabar_slots', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
        });

        Schema::table('mabar_sessions', function (Blueprint $table) {
            $table->foreignId('pinned_message_id')->nullable()->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('mabar_sessions', function (Blueprint $table) {
            $table->dropColumn('pinned_message_id');
        });
        Schema::table('mabar_slots', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
        Schema::dropIfExists('mabar_messages');
    }
};
