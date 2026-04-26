<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mabar_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('mabar_sessions')->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->json('tags')->nullable(); // ['fun', 'clutch', 'toxic']
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'from_user_id', 'to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mabar_ratings');
    }
};
