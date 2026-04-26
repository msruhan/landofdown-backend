<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('avatar_url')->nullable()->after('username');
        });

        // Promote the first existing user (seeded admin) to admin automatically.
        DB::table('users')->orderBy('id')->limit(1)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['is_admin', 'username', 'avatar_url']);
        });
    }
};
