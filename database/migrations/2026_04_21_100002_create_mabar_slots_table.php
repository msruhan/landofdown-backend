<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mabar_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('mabar_sessions')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_index');
            $table->string('role_preference')->default('any'); // any | tank | jungle | roam | mid | exp | gold
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('open'); // open | pending | confirmed | left
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'slot_index']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mabar_slots');
    }
};
