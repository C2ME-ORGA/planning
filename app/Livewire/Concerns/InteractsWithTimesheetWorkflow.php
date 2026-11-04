<?php

namespace App\Livewire\Concerns;

use App\Exceptions\BusinessRuleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Retour utilisateur commun aux actions de workflow des relevés.
 *
 * Les règles restent dans les services et policies : ce trait ne fait que
 * présenter une erreur métier lisible et transformer un refus d'autorisation
 * en réponse 403, sans exposer de détail technique.
 */
trait InteractsWithTimesheetWorkflow
{
    public ?string $statusMessage = null;

    /**
     * @param  callable(): mixed  $action
     */
    protected function performWorkflow(callable $action, string $successMessage): bool
    {
        $this->statusMessage = null;
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError((string) $field, (string) ($messages[0] ?? 'Opération refusée.'));
            }

            return false;
        } catch (AuthorizationException) {
            abort(403);
        } catch (BusinessRuleException $exception) {
            $this->addError('workflow', $exception->getMessage());

            return false;
        }

        $this->statusMessage = $successMessage;
        $this->resetErrorBag();

        return true;
    }
}
