<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold">Journal des relevés</h1>
        <p class="mt-1 text-sm text-stone-600">Consultation transversale du journal d’audit des feuilles de temps. Lecture seule : aucun événement ne peut être modifié ni supprimé.</p>
    </div>

    <section aria-labelledby="journal-filters-heading" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
        <h2 id="journal-filters-heading" class="text-sm font-semibold text-stone-900">Filtres</h2>

        <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" wire:submit.prevent>
            <div>
                <label for="journal-from" class="mb-1 block text-xs font-medium text-stone-700">Du</label>
                <input id="journal-from" type="date" wire:model.live="dateFrom" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                @error('dateFrom') <p class="mt-1 text-xs text-rose-700" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="journal-to" class="mb-1 block text-xs font-medium text-stone-700">Au</label>
                <input id="journal-to" type="date" wire:model.live="dateTo" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                @error('dateTo') <p class="mt-1 text-xs text-rose-700" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="journal-employee" class="mb-1 block text-xs font-medium text-stone-700">Salarié</label>
                <select id="journal-employee" wire:model.live="employeeId" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                    <option value="">Tous</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->getKey() }}">{{ $employee->fullName() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="journal-timesheet" class="mb-1 block text-xs font-medium text-stone-700">Numéro de relevé</label>
                <input id="journal-timesheet" type="number" min="1" wire:model.live="timesheetId" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Ex. 123">
            </div>

            <div>
                <label for="journal-actor" class="mb-1 block text-xs font-medium text-stone-700">Acteur</label>
                <select id="journal-actor" wire:model.live="actorId" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                    <option value="">Tous</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->getKey() }}">{{ $actor->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="journal-action" class="mb-1 block text-xs font-medium text-stone-700">Type d’événement</label>
                <select id="journal-action" wire:model.live="action" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                    <option value="">Tous</option>
                    @foreach ($actionOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="resetFilters" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                    Réinitialiser
                </button>
            </div>
        </form>
    </section>

    @if ($entries === [])
        <div class="rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center">
            <p class="text-sm font-semibold text-stone-800">Aucun événement ne correspond à ces filtres.</p>
            <p class="mt-1 text-sm text-stone-500">Élargissez la période ou retirez un filtre.</p>
        </div>
    @else
        <section aria-labelledby="journal-events-heading" class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <h2 id="journal-events-heading" class="sr-only">Événements du journal</h2>

            <ol class="divide-y divide-stone-100" role="list">
                @foreach ($entries as $event)
                    @php
                        $subject = $timesheets->get((int) ($event['subject_id'] ?? 0));
                    @endphp
                    <li class="p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-stone-900">{{ $event['label'] }}</p>
                                <p class="text-xs text-stone-500">
                                    {{ $event['occurred_at']->format('d/m/Y H:i') }}
                                    · {{ $event['actor'] ?? 'Acteur non identifié' }}
                                    @if ($event['actor_role'])
                                        ({{ $event['actor_role'] }})
                                    @elseif ($event['actor'])
                                        (rôle non disponible)
                                    @endif
                                </p>
                            </div>

                            @if ($subject)
                                <a href="{{ route('workspace.timesheets.show', $subject) }}" class="text-xs font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">
                                    {{ $subject->employee?->fullName() ?? 'Salarié inconnu' }} · semaine du {{ $subject->period_start?->format('d/m/Y') }}
                                </a>
                            @endif
                        </div>

                        @if ($event['reason'])
                            <p class="mt-2 rounded-lg bg-stone-50 px-3 py-2 text-sm text-stone-800">
                                <span class="font-medium">Motif :</span> {{ $event['reason'] }}
                            </p>
                        @endif

                        @if ($event['details'] !== [])
                            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-stone-600">
                                @foreach ($event['details'] as $detail)
                                    <li>{{ $detail }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>

            <div class="border-t border-stone-200 px-4 py-3">
                {{ $entriesPaginator->links() }}
            </div>
        </section>
    @endif
</div>
