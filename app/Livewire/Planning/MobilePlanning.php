<?php

namespace App\Livewire\Planning;

use App\Models\PlanningAssignment;
use App\Models\User;
use App\Services\Planning\PlanningDayBoard;
use App\Support\DatePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Consultation mobile du planning d'une journée.
 *
 * Le parcours est strictement en lecture : aucune écriture, aucun pointage et
 * aucune heure réelle n'est produite. L'autorisation est vérifiée côté serveur
 * à chaque requête Livewire, pas seulement à l'affichage initial.
 */
#[Layout('layouts.planning-mobile')]
class MobilePlanning extends Component
{
    public string $date;

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function booted(): void
    {
        Gate::authorize('viewAny', PlanningAssignment::class);
    }

    public function updatedDate(string $value): void
    {
        try {
            DatePeriod::parse($value);
        } catch (RuntimeException $exception) {
            $this->date = now()->toDateString();
            $this->addError('date', $exception->getMessage());
        }
    }

    public function previousDay(): void
    {
        $this->shiftDay(-1);
    }

    public function nextDay(): void
    {
        $this->shiftDay(1);
    }

    public function today(): void
    {
        $this->date = now()->toDateString();
        $this->resetErrorBag('date');
    }

    public function render(): View
    {
        /** @var User $actor */
        $actor = auth()->user();

        $date = CarbonImmutable::parse($this->date);
        $board = app(PlanningDayBoard::class)->forDate($date, $actor);

        return view('livewire.planning.mobile-planning', [
            'board' => $board,
        ]);
    }

    private function shiftDay(int $days): void
    {
        $date = DatePeriod::parse($this->date)->addDays($days);
        $this->date = $date->toDateString();
        $this->resetErrorBag('date');
    }
}
