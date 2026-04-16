<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heroes', function (Blueprint $table) {
            $table->string('hero_role')->nullable()->after('name');
            $table->enum('lane', ['jungle', 'exp', 'gold', 'mid', 'roam'])->nullable()->after('hero_role');
        });
    }

    public function down(): void
    {
        Schema::table('heroes', function (Blueprint $table) {
            $table->dropColumn(['hero_role', 'lane']);
        });
    }
};

