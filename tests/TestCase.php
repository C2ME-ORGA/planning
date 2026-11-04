<?php

namespace Tests;

use App\Models\User;
use App\Services\Accounts\AuthenticationGeneration;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aligne les sessions de test sur le mécanisme d'authentification réel.
     *
     * En production, ces marqueurs sont écrits par le listener de connexion.
     * `actingAs()` ne déclenche pas cet événement : on les pose explicitement
     * pour que les tests ne dépendent pas d'une exception permissive.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $result = parent::actingAs($user, $guard);

        if ($user instanceof User) {
            $this->session([
                'auth.session_version' => (int) $user->session_version,
                'auth.global_generation' => app(AuthenticationGeneration::class)->current(),
            ]);
        }

        return $result;
    }
}
