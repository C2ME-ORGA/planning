<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $queueLabel }}</h1>
            <p class="mt-1 text-sm text-stone-600">Le périmètre affiché dépend de votre rôle et de vos responsabilités.</p>
        </div>

        @if ($canReviewOverlaps)
            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'chevauchements']) }}" class="inline-flex min-h-11 items-center rounded-lg border border-violet-300 bg-violet-50 px-4 text-sm font-semibold text-violet-900 hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                Chevauchements à examiner
            </a>
        @endif
    </div>

    <section aria-labelledby="filters-heading" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
        <h2 id="filters-heading" class="text-sm font-semibold text-stone-900">Filtres</h2>

        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5" wire:submit.prevent>
            <div class="lg:col-span-2">
                <label for="timesheet-search" class="mb-1 block text-xs font-medium text-stone-700">Rechercher un salarié</label>
                <input id="timesheet-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Nom ou prénom" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
            </div>

            <div>
                <label for="timesheet-from" class="mb-1 block text-xs font-medium text-stone-700">Semaine du</label>
                <input id="timesheet-from" type="date" wire:model.live="from" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                @error('from') <p class="mt-1 text-xs text-rose-700" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="timesheet-to" class="mb-1 block text-xs font-medium text-stone-700">Au</label>
                <input id="timesheet-to" type="date" wire:model.live="to" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                @error('to') <p class="mt-1 text-xs text-rose-700" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="timesheet-employee" class="mb-1 block text-xs font-medium text-stone-700">Salarié</label>
                <select id="timesheet-employee" wire:model.live="employeeId" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                    <option value="">Tous</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->getKey() }}">{{ $employee->fullName() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="timesheet-status" class="mb-1 block text-xs font-medium text-stone-700">Statut</label>

                @if ($lockedStatus)
                    <div class="flex min-h-11 items-center">
                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-700 ring-1 ring-inset ring-stone-200">{{ $lockedStatus->label() }}</span>
                    </div>
                @else
                    <select id="timesheet-status" wire:model.live="status" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                    Réinitialiser
                </button>
            </div>
        </form>
    </section>

    @if ($timesheets->isEmpty())
        <div class="rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center">
            <p class="text-sm font-semibold text-stone-800">Aucun relevé ne correspond à ces filtres.</p>
            <p class="mt-1 text-sm text-stone-500">Vérifiez la période, le salarié ou le statut sélectionné.</p>
        </div>
    @else
        <section aria-labelledby="timesheets-heading" class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <h2 id="timesheets-heading" class="sr-only">Liste des relevés</h2>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50">
                        <tr class="text-left text-xs uppercase tracking-wide text-stone-500">
                            <th scope="col" class="px-4 py-3 font-medium">Salarié</th>
                            <th scope="col" class="px-4 py-3 font-medium">Semaine</th>
                            <th scope="col" class="px-4 py-3 font-medium">Durée</th>
                            <th scope="col" class="px-4 py-3 font-medium">Statut</th>
                            <th scope="col" class="px-4 py-3 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 bg-white">
                        @foreach ($timesheets as $timesheet)
                            <tr>
                                <td class="px-4 py-3 font-medium text-stone-900">{{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}</td>
                                <td class="px-4 py-3 text-stone-600">
                                    {{ $timesheet->period_start?->format('d/m/Y') }}
                                    <span class="text-xs text-stone-400">→ {{ $timesheet->period_end?->format('d/m/Y') }}</span>
                                </td>
                                <td class="px-4 py-3 tabular-nums">{{ $summary->formatMinutes($summary->totalMinutes($timesheet)) }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$timesheet->status" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">Ouvrir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-stone-100 md:hidden" role="list">
                @foreach ($timesheets as $timesheet)
                    <li class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="text-sm font-semibold text-stone-900 underline-offset-4 hover:underline">
                                    {{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}
                                </a>
                                <p class="mt-0.5 text-xs text-stone-500">Semaine du {{ $timesheet->period_start?->format('d/m/Y') }}</p>
                                <p class="mt-1 text-sm tabular-nums text-stone-700">{{ $summary->formatMinutes($summary->totalMinutes($timesheet)) }}</p>
                            </div>
                            <x-status-badge :status="$timesheet->status" />
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-stone-200 px-4 py-3">
                {{ $timesheets->links() }}
            </div>
        </section>
    @endif
</div>
