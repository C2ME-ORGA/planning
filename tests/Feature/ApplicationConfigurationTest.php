<?php

it('uses the French business configuration', function () {
    expect(config('app.name'))->toBe('Planning C2ME')
        ->and(config('app.locale'))->toBe('fr')
        ->and(config('app.timezone'))->toBe('Europe/Paris')
        ->and(config('session.lifetime'))->toBe(15);
});
