<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Écriture et vérification du journal d'audit.
 *
 * Règles :
 * - aucune donnée sensible (mot de passe, jeton, secret TOTP, code de secours)
 *   n'est écrite : les valeurs sont masquées avant stockage ;
 * - chaque événement est chaîné au précédent par HMAC-SHA256 ;
 * - l'écriture est transactionnelle et retentée en cas de collision de séquence.
 *
 * Limite documentée : une personne disposant à la fois de la clé APP_KEY et
 * d'un accès en écriture direct à la base peut recalculer la chaîne. La
 * détection couvre les altérations accidentelles ou partielles, pas un
 * adversaire disposant de tous les secrets d'exploitation.
 */
class AuditLogger
{
    private const MAX_STRING_LENGTH = 2000;

    private const MAX_REASON_LENGTH = 500;

    private const MAX_DEPTH = 6;

    private const VERIFY_BATCH_SIZE = 500;

    private const REDACTED = '[masqué]';

    public function __construct(
        private readonly AuditHasher $hasher,
    ) {}

    /**
     * Vérifie la configuration de signature avant une opération sensible.
     *
     * @throws \RuntimeException
     */
    public function assertConfigured(): void
    {
        $this->hasher->assertConfigured();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $reason = null,
        ?User $actor = null,
        array $context = [],
    ): AuditEvent {
        $this->hasher->assertConfigured();

        $actor ??= $this->currentActor();
        $occurredAt = Carbon::now()->startOfSecond();

        $changes = $this->redact($changes);
        $context = $this->redact($context + $this->defaultContext());
        $reason = $reason === null ? null : $this->truncate($reason, self::MAX_REASON_LENGTH);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(function () use ($action, $subject, $changes, $context, $reason, $actor, $occurredAt): AuditEvent {
                    $previous = AuditEvent::query()
                        ->orderByDesc('sequence')
                        ->lockForUpdate()
                        ->first();

                    $fields = [
                        'sequence' => $previous === null ? 1 : $previous->sequence + 1,
                        'occurred_at' => $occurredAt,
                        'actor_id' => $actor?->getKey(),
                        'actor_role' => $actor?->role->value,
                        'action' => $action->value,
                        'subject_type' => $subject === null ? null : $subject::class,
                        'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
                        'reason' => $reason,
                        'changes' => $changes === [] ? null : $changes,
                        'context' => $context === [] ? null : $context,
                        'previous_hash' => $previous?->hash,
                        'key_id' => $this->hasher->currentKeyId(),
                    ];

                    return AuditEvent::query()->create([
                        ...$fields,
                        'hash' => $this->hasher->sign($fields),
                        'created_at' => Carbon::now(),
                    ]);
                }, 3);
            } catch (QueryException $exception) {
                if ($attempt === 5 || ! $this->isSequenceCollision($exception)) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Impossible d’écrire l’événement d’audit.');
    }

    public function verify(): AuditVerification
    {
        try {
            $this->assertConfigured();
        } catch (Throwable $exception) {
            return AuditVerification::configurationError($exception->getMessage());
        }

        // La contrainte unique protège déjà la séquence en écriture. Ce
        // contrôle explicite couvre une base restaurée ou altérée dont
        // l'index unique aurait disparu.
        $duplicate = AuditEvent::query()
            ->select('sequence')
            ->groupBy('sequence')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            $event = AuditEvent::query()->where('sequence', $duplicate->sequence)->first();

            if ($event !== null) {
                return AuditVerification::broken(0, $event, 'Séquence dupliquée.');
            }
        }

        $previousHash = null;
        $previousSequence = 0;
        $checked = 0;
        $lastSequence = null;

        // Pagination par clé sur `sequence`, jamais par `id` : les
        // identifiants SQL ne sont pas garantis corrélés à l'ordre logique.
        // Chaque lot est borné à VERIFY_BATCH_SIZE événements en mémoire.
        do {
            $query = AuditEvent::query()
                ->orderBy('sequence')
                ->limit(self::VERIFY_BATCH_SIZE);

            if ($lastSequence !== null) {
                $query->where('sequence', '>', $lastSequence);
            }

            $events = $query->get();

            foreach ($events as $event) {
                $checked++;
                $sequence = (int) $event->sequence;

                if ($sequence !== $previousSequence + 1) {
                    return AuditVerification::broken($checked - 1, $event, 'Séquence non contiguë.');
                }

                if ($event->previous_hash !== $previousHash) {
                    return AuditVerification::broken($checked - 1, $event, 'Chaîne précédente incohérente.');
                }

                try {
                    $valid = $this->hasher->verify($this->hasher->fieldsFor($event), $event->hash);
                } catch (Throwable $exception) {
                    return AuditVerification::broken($checked - 1, $event, $exception->getMessage());
                }

                if (! $valid) {
                    return AuditVerification::broken($checked - 1, $event, 'Signature de l’événement invalide.');
                }

                $previousHash = $event->hash;
                $previousSequence = $sequence;
                $lastSequence = $sequence;
            }
        } while ($events->count() === self::VERIFY_BATCH_SIZE);

        return AuditVerification::valid($checked);
    }

    public function currentActor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultContext(): array
    {
        if (! app()->bound('request') || app()->runningInConsole()) {
            return ['source' => 'console'];
        }

        $request = request();

        return [
            'source' => 'http',
            'ip' => $request->ip(),
            'user_agent' => $this->truncate((string) $request->userAgent()),
        ];
    }

    private function redact(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if ($depth > self::MAX_DEPTH) {
            return '[profondeur limitée]';
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Model) {
            return ['type' => $value::class, 'id' => $value->getKey()];
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $itemKey => $item) {
                $redacted[$itemKey] = $this->redact(
                    $item,
                    $depth + 1,
                    is_string($itemKey) ? $itemKey : null,
                );
            }

            return $redacted;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->truncate((string) $value);
        }

        return '['.get_debug_type($value).']';
    }

    private function isSensitiveKey(#[SensitiveParameter] string $key): bool
    {
        return preg_match(
            '/pass(word)?|token|secret|recovery|remember|authorization|api[_-]?key|credential|cookie|session[_-]?id|medical|diagnos|absence[_-]?reason|motif[_-]?absence|justificatif/i',
            $key,
        ) === 1;
    }

    private function truncate(string $value, int $limit = self::MAX_STRING_LENGTH): string
    {
        return mb_substr($value, 0, $limit);
    }

    private function isSequenceCollision(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && (string) $exception->getCode() === '23000';
    }
}
