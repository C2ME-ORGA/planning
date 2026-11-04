<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\Accounts\AccountActivationService;
use App\Services\Accounts\AccountProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountActivationController extends Controller
{
    public function __construct(
        private readonly AccountActivationService $activations,
        private readonly AccountProvisioner $accounts,
    ) {}

    public function show(string $token): View|Response
    {
        $activation = $this->activations->findUsable($token);

        if ($activation === null) {
            return response()->view('auth.activation-invalid', [], 410);
        }

        return view('auth.activate', [
            'token' => $token,
            'user' => $activation->user,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse|Response
    {
        $activation = $this->activations->findUsable($token);

        if ($activation === null) {
            return response()->view('auth.activation-invalid', [], 410);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $this->accounts->activate($activation, $validated['password']);

        $loginRoute = $user->role === Role::Admin || $user->role === Role::BureauDirection
            ? 'filament.admin.auth.login'
            : 'login';

        return redirect()
            ->route($loginRoute)
            ->with('status', 'Votre compte est activé. Vous pouvez vous connecter.');
    }
}
