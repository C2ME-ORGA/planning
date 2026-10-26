<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activation du compte — Planning C2ME</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f9fafb; color: #111827; font-family: ui-sans-serif, system-ui, sans-serif; padding: 1.5rem; }
        main { width: 100%; max-width: 28rem; background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 2rem; box-shadow: 0 8px 24px rgb(0 0 0 / .06); }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: #4b5563; font-size: .9375rem; line-height: 1.55; }
        label { display: block; font-weight: 600; font-size: .875rem; margin: 1rem 0 .35rem; }
        input { width: 100%; box-sizing: border-box; padding: .6rem .7rem; border: 1px solid #d1d5db; border-radius: .5rem; font-size: 1rem; }
        button { width: 100%; margin-top: 1.25rem; padding: .7rem 1rem; border: 0; border-radius: .5rem; background: #b45309; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #92400e; }
        .errors { margin-top: 1rem; color: #b91c1c; font-size: .875rem; }
        .errors ul { margin: 0; padding-left: 1.1rem; }
        .hint { font-size: .8125rem; color: #6b7280; }
    </style>
</head>
<body>
<main>
    <h1>Activer votre compte</h1>
    <p>Bonjour {{ $user->name }}, définissez le mot de passe de votre compte <strong>{{ $user->email }}</strong>.</p>

    <form method="POST" action="{{ route('account.activation.store', ['token' => $token]) }}">
        @csrf

        <label for="password">Nouveau mot de passe</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>

        <label for="password_confirmation">Confirmer le mot de passe</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <p class="hint">12 caractères minimum, avec majuscules, minuscules, chiffres et symboles.</p>

        @if ($errors->any())
            <div class="errors" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <button type="submit">Activer mon compte</button>
    </form>
</main>
</body>
</html>
