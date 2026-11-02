<?php

namespace App\Services\Accounts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Révocation immédiate des sessions et jetons persistants d'un compte.
 *
 * Le pilote de session de production est `database` ; les lignes sont donc
 * supprimées immédiatement. Le jeton "remember me" est également renouvelé.
 * Les tests utilisent le pilote `array`, mais la table `sessions` reste
 * vérifiable.
 */
class SessionRevoker
{
    public function revoke(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $table = (string) config('session.table', 'sessions');

            $deleted = DB::table($table)->where('user_id', $user->getKey())->delete();

            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

            return $deleted;
        });
    }

    public function revokeAll(): int
    {
        $table = (string) config('session.table', 'sessions');

        return DB::transaction(function () use ($table): int {
            $deleted = DB::table($table)->delete();

            // Parcours borné : les utilisateurs sont traités par lots de 500.
            DB::table('users')
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, static function ($users): void {
                    foreach ($users as $user) {
                        DB::table('users')->where('id', $user->id)->update([
                            'remember_token' => Str::random(60),
                            'updated_at' => now(),
                        ]);
                    }
                });

            return $deleted;
        });
    }
}
