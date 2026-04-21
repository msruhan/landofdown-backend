<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename demo players from fantasy-style names to MLBB in-game username style.
     */
    public function up(): void
    {
        $map = [
            'ShadowKing' => 'wolay70',
            'DragonSlayer' => 'Leadership',
            'PhoenixRise' => 'Bluerose_magic',
            'StormBreaker' => 'zandal',
            'NightHawk' => 'Laperauss',
            'ThunderBolt' => 'Nuas',
            'IceQueen' => 'Drenvic',
            'FireLord' => 'balabalahard',
            'WindWalker' => 'G.O.Y',
            'DarkKnight' => 'Avalanch',
        ];

        foreach ($map as $from => $to) {
            $exists = DB::table('players')->where('username', $from)->exists();
            if (! $exists) {
                continue;
            }

            $targetTaken = DB::table('players')->where('username', $to)->where('username', '!=', $from)->exists();
            if ($targetTaken) {
                continue;
            }

            DB::table('players')->where('username', $from)->update([
                'username' => $to,
                'avatar_url' => 'https://api.dicebear.com/7.x/adventurer/svg?seed='.rawurlencode($to),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $map = [
            'wolay70' => 'ShadowKing',
            'Leadership' => 'DragonSlayer',
            'Bluerose_magic' => 'PhoenixRise',
            'zandal' => 'StormBreaker',
            'Laperauss' => 'NightHawk',
            'Nuas' => 'ThunderBolt',
            'Drenvic' => 'IceQueen',
            'balabalahard' => 'FireLord',
            'G.O.Y' => 'WindWalker',
            'Avalanch' => 'DarkKnight',
        ];

        foreach ($map as $from => $to) {
            $exists = DB::table('players')->where('username', $from)->exists();
            if (! $exists) {
                continue;
            }

            $targetTaken = DB::table('players')->where('username', $to)->where('username', '!=', $from)->exists();
            if ($targetTaken) {
                continue;
            }

            DB::table('players')->where('username', $from)->update([
                'username' => $to,
                'avatar_url' => 'https://api.dicebear.com/7.x/adventurer/svg?seed='.rawurlencode($to),
                'updated_at' => now(),
            ]);
        }
    }
};
