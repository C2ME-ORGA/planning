<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Sérialisation canonique et hachage des événements d'audit.
 *
 * Les clés sont triées à chaque niveau pour que MySQL (qui réordonne les clés
 * JSON) ne casse pas la vérification. Le hachage est un HMAC-SHA256 chaîné :
 * chaque événement couvre le hash précédent, ce qui détecte toute
 * modification, insertion ou suppression au milieu du journal.
 *
 * Une clé dédiée, distincte d'APP_KEY, signe les nouveaux événements. Chaque
 * événement porte l'identifiant de sa clé. Les événements antérieurs au lot 2
 * (sans identifiant) restent vérifiables avec APP_KEY, et les anciennes clés
 * d'audit peuvent être déclarées dans `planning.audit.previous_keys` après
 * rotation.
 */
class AuditHasher
{
    private const MIN_KEY_LENGTH = 32;

    /**
     * Signe un nouvel événement avec la clé d'audit courante.
     *
     * @param  array<string, mixed>  $fields
     */
    public function sign(array $fields): string
    {
        if (($fields['key_id'] ?? null) !== $this->currentKeyId()) {
            throw new RuntimeException('L’identifiant de clé d’audit de l’événement ne correspond pas à la configuration.');
        }

        return hash_hmac('sha256', $this->payload($fields), $this->currentKey());
    }

    /**
     * Vérifie la signature d'un événement avec la clé correspondant à son
     * identifiant (clé courante, clé antérieure déclarée, ou APP_KEY pour les
     * événements historiques).
     *
     * @param  array<string, mixed>  $fields
     */
    public function verify(array $fields, string $hash): bool
    {
        return hash_equals(
            hash_hmac('sha256', $this->payload($fields), $this->keyFor($fields['key_id'] ?? null)),
            $hash,
        );
    }

    /**
     * Contrôle la configuration avant toute écriture ou vérification.
     */
    public function assertConfigured(): void
    {
        $this->currentKeyId();
        $this->currentKey();
    }

    public function currentKeyId(): string
    {
        $keyId = (string) config('planning.audit.key_id', '');

        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $keyId) !== 1) {
            throw new RuntimeException('PLANNING_AUDIT_KEY_ID est absent ou invalide (1 à 32 caractères alphanumériques, point, tiret ou souligné).');
        }

        return $keyId;
    }

    public function currentKey(): string
    {
        $key = (string) config('planning.audit.key', '');

        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException('PLANNING_AUDIT_KEY est absent ou trop court (32 caractères minimum).');
        }

        $appKey = (string) config('app.key', '');

        if ($appKey !== '' && hash_equals($appKey, $key)) {
            throw new RuntimeException('PLANNING_AUDIT_KEY doit être distincte d’APP_KEY.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function payload(array $fields): string
    {
        $parts = [
            (string) $fields['sequence'],
            $this->formatDate($fields['occurred_at']),
            (string) ($fields['actor_id'] ?? ''),
            (string) ($fields['actor_role'] ?? ''),
            (string) $fields['action'],
            (string) ($fields['subject_type'] ?? ''),
            (string) ($fields['subject_id'] ?? ''),
            (string) ($fields['reason'] ?? ''),
            $this->encode($fields['changes'] ?? null),
            $this->encode($fields['context'] ?? null),
            (string) ($fields['previous_hash'] ?? ''),
        ];

        // Les événements historiques (key_id null) ont été signés sans ce
        // segment : ne l'ajouter que pour les événements identifiés.
        if (($fields['key_id'] ?? null) !== null) {
            $parts[] = (string) $fields['key_id'];
        }

        return implode('|', $parts);
    }

    public function encode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function formatDate(mixed $value): string
    {
        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    public function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function fieldsFor(AuditEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'occurred_at' => $event->occurred_at,
            'actor_id' => $event->actor_id,
            'actor_role' => $event->actor_role,
            'action' => $event->action->value,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'reason' => $event->reason,
            'changes' => $event->changes,
            'context' => $event->context,
            'previous_hash' => $event->previous_hash,
            'key_id' => $event->key_id,
        ];
    }

    private function keyFor(?string $keyId): string
    {
        // Événements créés avant l'introduction de la clé d'audit dédiée.
        if ($keyId === null || $keyId === '') {
            $appKey = (string) config('app.key', '');

            if ($appKey === '') {
                throw new RuntimeException('APP_KEY est requis pour vérifier les événements d’audit historiques.');
            }

            return $appKey;
        }

        if ($keyId === $this->currentKeyId()) {
            return $this->currentKey();
        }

        $previousKeys = config('planning.audit.previous_keys', []);
        $previousKey = is_array($previousKeys) ? ($previousKeys[$keyId] ?? null) : null;

        if (! is_string($previousKey) || strlen($previousKey) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException("Aucune clé d’audit valide n’est configurée pour l’identifiant « {$keyId} ».");
        }

        return $previousKey;
    }
}
