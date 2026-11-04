<?php

namespace App\Services\Timesheets;

use App\Enums\TimesheetStatus;
use App\Models\Timesheet;
use App\Models\User;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Validation en masse de relevés d’heures.
 *
 * La sélection est strictement bornée et normalisée avant tout traitement :
 * rôle Bureau/Direction, permission `timesheet.validate`, 1 à 50 relevés
 * distincts, identifiants entiers positifs. Chaque relevé est ensuite confié
 * au service de validation individuel, qui applique sous verrou l’ensemble des
 * règles existantes (statut, séparation des tâches, auto-validation et revue
 * des chevauchements).
 *
 * Aucune transaction globale n’entoure le lot : chaque appel individuel ouvre
 * sa propre transaction. Un échec métier ou technique est isolé, journalisé
 * lorsqu’il est inattendu, et n’empêche pas le traitement des relevés suivants.
 */
final class TimesheetBatchValidationService
{
    public const MAX_BATCH_SIZE = 50;

    public function __construct(
        private readonly TimesheetValidationService $validation,
    ) {}

    /**
     * @param  array<array-key, mixed>  $timesheetIds
     * @return array{
     *     requested: int,
     *     succeeded: int,
     *     failed: int,
     *     partial: bool,
     *     results: array<int, array{
     *         timesheet_id: int,
     *         reference: string|null,
     *         result: 'success'|'failed',
     *         message: string|null
     *     }>
     * }
     */
    public function validate(User $actor, array $timesheetIds): array
    {
        // Refus avant toute lecture ou écriture métier.
        Gate::forUser($actor)->authorize('validateBatch', Timesheet::class);

        $ids = $this->normalizeIds($timesheetIds);
        $results = [];

        foreach ($ids as $id) {
            $results[] = $this->validateOne($id, $actor);
        }

        $succeeded = 0;

        foreach ($results as $result) {
            if ($result['result'] === 'success') {
                $succeeded++;
            }
        }

        $failed = count($results) - $succeeded;

        return [
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'partial' => $succeeded > 0 && $failed > 0,
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *     timesheet_id: int,
     *     reference: string|null,
     *     result: 'success'|'failed',
     *     message: string|null
     * }
     */
    private function validateOne(int $timesheetId, User $actor): array
    {
        $timesheet = Timesheet::query()->find($timesheetId);

        if (! $timesheet instanceof Timesheet) {
            return $this->failure($timesheetId, null, 'Relevé introuvable.');
        }

        if ($timesheet->status !== TimesheetStatus::PretePourValidation) {
            return $this->failure(
                $timesheetId,
                $this->reference($timesheet),
                sprintf(
                    'Le relevé n’est pas prêt pour validation (statut : %s).',
                    $timesheet->status->label(),
                ),
            );
        }

        try {
            $validated = $this->validation->validate($timesheet, $actor);
        } catch (ValidationException $exception) {
            return $this->failure(
                $timesheetId,
                $this->reference($timesheet),
                $this->firstValidationMessage($exception),
            );
        } catch (AuthorizationException) {
            $message = $timesheet->employee?->user_id === $actor->getKey()
                ? 'L’auto-validation d’un relevé exige un motif et n’est pas disponible en validation en masse.'
                : 'La validation de ce relevé est refusée par les règles d’autorisation.';

            return $this->failure($timesheetId, $this->reference($timesheet), $message);
        } catch (Exception $exception) {
            Log::error('Échec technique lors de la validation en masse d’un relevé.', [
                'timesheet_id' => $timesheetId,
                'actor_id' => $actor->getKey(),
                'exception' => $exception,
            ]);

            return $this->failure(
                $timesheetId,
                $this->reference($timesheet),
                'Une erreur technique a empêché la validation de ce relevé.',
            );
        }

        return [
            'timesheet_id' => $timesheetId,
            'reference' => $this->reference($validated),
            'result' => 'success',
            'message' => null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $timesheetIds
     * @return array<int, int>
     */
    private function normalizeIds(array $timesheetIds): array
    {
        if ($timesheetIds === []) {
            throw ValidationException::withMessages([
                'timesheets' => 'Sélectionnez au moins un relevé à valider.',
            ]);
        }

        $unique = [];

        foreach ($timesheetIds as $rawId) {
            $id = $this->normalizeId($rawId);

            if (! in_array($id, $unique, true)) {
                $unique[] = $id;
            }
        }

        if (count($unique) > self::MAX_BATCH_SIZE) {
            throw ValidationException::withMessages([
                'timesheets' => sprintf(
                    'Une validation en masse est limitée à %d relevés.',
                    self::MAX_BATCH_SIZE,
                ),
            ]);
        }

        return $unique;
    }

    private function normalizeId(mixed $rawId): int
    {
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]*$/', $rawId) === 1) {
            $id = (int) $rawId;
        } else {
            throw ValidationException::withMessages([
                'timesheets' => 'Un identifiant de relevé est invalide.',
            ]);
        }

        if ($id < 1) {
            throw ValidationException::withMessages([
                'timesheets' => 'Un identifiant de relevé est invalide.',
            ]);
        }

        return $id;
    }

    private function firstValidationMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (isset($messages[0]) && is_string($messages[0]) && $messages[0] !== '') {
                return $messages[0];
            }
        }

        return 'Le relevé ne peut pas être validé.';
    }

    private function reference(?Timesheet $timesheet): ?string
    {
        return $timesheet?->period_start?->toDateString();
    }

    /**
     * @return array{
     *     timesheet_id: int,
     *     reference: string|null,
     *     result: 'failed',
     *     message: string
     * }
     */
    private function failure(int $timesheetId, ?string $reference, string $message): array
    {
        return [
            'timesheet_id' => $timesheetId,
            'reference' => $reference,
            'result' => 'failed',
            'message' => $message,
        ];
    }
}
