<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploaded_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->nullable()->constrained('game_matches')->nullOnDelete();
            $table->string('file_path');
            $table->json('parsed_data')->nullable();
            $table->enum('status', ['pending', 'parsed', 'reviewed', 'failed'])->default('pending');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploaded_screenshots');
    }
};
