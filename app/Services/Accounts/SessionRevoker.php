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
        $table = (string) config('session.table', 'sessions');

        $deleted = DB::table($table)->where('user_id', $user->getKey())->delete();

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        return $deleted;
    }

    public function revokeAll(): int
    {
        $table = (string) config('session.table', 'sessions');

        $deleted = DB::table($table)->delete();

        User::query()->each(static function (User $user): void {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        });

        return $deleted;
    }
}
