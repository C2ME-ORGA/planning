<?php

namespace App\Livewire\Timesheets;

use App\Enums\Permission;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Point d'entrée unique de l'espace métier.
 *
 * Redirige vers le tableau de bord correspondant au rôle effectif. Aucun rôle
 * sans capacité sur les relevés n'est redirigé vers une page qu'il ne peut pas
 * consulter.
 */
#[Layout('layouts.workspace')]
class WorkspaceHome extends Component
{
    public function mount(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        Gate::forUser($actor)->authorize('viewAny', Timesheet::class);

        if ($actor->hasPermission(Permission::TimesheetValidate)) {
            $this->redirectRoute('workspace.bureau.dashboard');

            return;
        }

        if ($actor->hasPermission(Permission::TimesheetControl)) {
            $this->redirectRoute('workspace.team.dashboard');

            return;
        }

        $this->redirectRoute('workspace.employee.dashboard');
    }

    public function render(): View
    {
        return view('livewire.timesheets.home');
    }
}
