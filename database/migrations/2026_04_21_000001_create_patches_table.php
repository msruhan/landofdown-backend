<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();
            $table->string('name')->nullable();
            $table->date('release_date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patches');
    }
};
