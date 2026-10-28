<div class="space-y-6">
    <section aria-labelledby="date-heading" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
        <h2 id="date-heading" class="sr-only">Choix de la date</h2>

        <div class="flex items-center justify-between gap-3">
            <button
                type="button"
                wire:click="previousDay"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-stone-300 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2"
                aria-label="Jour précédent"
            >
                <span aria-hidden="true">←</span>
            </button>

            <p class="text-center">
                <span class="block text-base font-semibold capitalize">
                    {{ ucfirst($board['date']->locale('fr')->translatedFormat('l j F Y')) }}
                </span>
                <span class="text-xs text-stone-500">Matin et après-midi</span>
            </p>

            <button
                type="button"
                wire:click="nextDay"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-stone-300 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2"
                aria-label="Jour suivant"
            >
                <span aria-hidden="true">→</span>
            </button>
        </div>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="planning-date" class="mb-1 block text-sm font-medium text-stone-700">Choisir une date</label>
                <input
                    id="planning-date"
                    type="date"
                    wire:model.live="date"
                    class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base text-stone-900 focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200"
                >
                @error('date')
                    <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="button"
                wire:click="today"
                class="inline-flex min-h-11 items-center justify-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2"
            >
                Aujourd’hui
            </button>
        </div>
    </section>

    @if ($board['team_warnings'] > 0 || $board['employee_warnings'] > 0)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">
            <p class="font-semibold">Chevauchements à vérifier</p>
            <p class="mt-1">
                {{ $board['team_warnings'] }} conflit(s) d’équipe et
                {{ $board['employee_warnings'] }} conflit(s) de salarié.
                Ces avertissements ne bloquent pas le planning et ne modifient aucune affectation.
            </p>
        </div>
    @endif

    @foreach (['morning' => 'Matin', 'afternoon' => 'Après-midi'] as $slot => $slotLabel)
        <section aria-labelledby="slot-{{ $slot }}" class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 id="slot-{{ $slot }}" class="text-base font-semibold">{{ $slotLabel }}</h2>
                <span class="rounded-full bg-stone-200 px-2.5 py-1 text-xs font-medium text-stone-700">
                    {{ count($board[$slot]) }} affectation(s)
                </span>
            </div>

            @if ($board[$slot] === [])
                <p class="rounded-xl border border-dashed border-stone-300 bg-white p-4 text-sm text-stone-500">
                    Aucune affectation prévue le {{ $slotLabel === 'Matin' ? 'matin' : 'après-midi' }}.
                </p>
            @else
                <ul class="space-y-3" role="list">
                    @foreach ($board[$slot] as $entry)
                        @php
                            $assignment = $entry['assignment'];
                            $cardId = 'assignment-'.$assignment->id;
                        @endphp

                        <li class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-base font-semibold text-stone-950">
                                        {{ $assignment->site->name }}
                                    </h3>
                                    <p class="mt-1 text-sm text-stone-600">
                                        Équipe <span class="font-medium text-stone-800">{{ $assignment->team->name }}</span>
                                    </p>
                                </div>

                                @if ($entry['archived'])
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
                                        Chantier archivé
                                    </span>
                                @endif
                            </div>

                            @if ($entry['archived'])
                                <p class="mt-2 text-xs text-amber-800">
                                    Consultation historique uniquement : aucune création ni modification d’affectation n’est possible.
                                </p>
                            @endif

                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">Membres prévus</dt>
                                    <dd class="font-semibold text-stone-900">{{ $entry['members_count'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-stone-500">Exclusions</dt>
                                    <dd class="font-semibold text-stone-900">
                                        {{ $entry['excluded_count'] }}
                                        <span class="block text-xs font-normal text-stone-500">ni absence ni temps réel</span>
                                    </dd>
                                </div>
                            </dl>

                            @if ($entry['warnings'] !== [])
                                <div class="mt-4">
                                    <h4 class="text-sm font-semibold text-stone-900">Chevauchements</h4>
                                    <ul class="mt-2 space-y-2" role="list">
                                        @foreach ($entry['warnings'] as $warning)
                                            <li class="rounded-lg border p-3 text-sm {{ ($warning['type'] ?? '') === 'team' ? 'border-sky-200 bg-sky-50 text-sky-950' : 'border-rose-200 bg-rose-50 text-rose-950' }}">
                                                <span class="mb-1 block text-xs font-semibold uppercase tracking-wide">
                                                    {{ ($warning['type'] ?? '') === 'team' ? 'Conflit d’équipe' : 'Conflit de salarié' }}
                                                </span>
                                                {{ $warning['message'] ?? '' }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($entry['history'] !== [])
                                <details class="mt-4 rounded-lg bg-stone-50 p-3">
                                    <summary class="min-h-11 cursor-pointer text-sm font-semibold text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                                        Historique ({{ count($entry['history']) }})
                                    </summary>

                                    <ol class="mt-3 space-y-3" role="list">
                                        @foreach ($entry['history'] as $event)
                                            <li class="border-s-2 border-stone-300 ps-3 text-sm">
                                                <p class="font-medium text-stone-900">{{ $event['label'] }}</p>
                                                <p class="text-xs text-stone-500">
                                                    {{ $event['occurred_at']->format('d/m/Y H:i') }}
                                                    · {{ $event['actor_label'] }}
                                                </p>

                                                @if ($event['details'] !== [])
                                                    <ul class="mt-1 list-inside list-disc text-xs text-stone-700">
                                                        @foreach ($event['details'] as $detail)
                                                            <li>{{ $detail }}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif

                                                @if ($event['overlaps'] !== [])
                                                    <ul class="mt-1 space-y-1 text-xs text-amber-800">
                                                        @foreach ($event['overlaps'] as $overlap)
                                                            <li>{{ $overlap['label'] }} : {{ $overlap['message'] }}</li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
