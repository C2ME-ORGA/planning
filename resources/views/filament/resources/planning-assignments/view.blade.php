<x-filament-panels::page>
    @php
        $assignment = $this->record;
        $warnings = $this->warnings();
        $history = $this->history();
    @endphp

    @if ($assignment->site->isArchived())
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-600 dark:bg-amber-950 dark:text-amber-100" role="status">
            <strong>Chantier archivé.</strong>
            Cette affectation reste consultable, mais aucune modification n’est autorisée.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="Affectation">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Chantier</dt>
                    <dd class="text-gray-950 dark:text-white">
                        {{ $assignment->site->name }}
                        @if ($assignment->site->isArchived())
                            <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-100">Archivé</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Équipe</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $assignment->team->name }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Date</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $assignment->work_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Demi-journée</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $assignment->half_day->label() }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">Exclusions</dt>
                    <dd class="text-gray-950 dark:text-white">
                        {{ $assignment->excludedEmployeeIds() === [] ? 'Aucune' : count($assignment->excludedEmployeeIds()).' salarié(s)' }}
                    </dd>
                </div>
            </dl>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Le planning est prévisionnel : une exclusion ne crée ni absence ni temps réellement travaillé.
            </p>
        </x-filament::section>

        <x-filament::section heading="Chevauchements">
            @if ($warnings === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">Aucun chevauchement détecté.</p>
            @else
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                    Ces avertissements ne bloquent pas l’enregistrement et ne modifient aucune autre affectation.
                </p>

                <ul class="space-y-3" role="list">
                    @foreach ($warnings as $warning)
                        <li class="rounded-lg border p-3 text-sm {{ ($warning['type'] ?? '') === 'team' ? 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-100' : 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100' }}">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide">
                                {{ ($warning['type'] ?? '') === 'team' ? 'Conflit d’équipe' : 'Conflit de salarié' }}
                            </span>
                            {{ $warning['message'] ?? '' }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="Historique">
        @if ($history === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">Aucun événement d’historique pour cette affectation.</p>
        @else
            <ol class="space-y-4" role="list">
                @foreach ($history as $entry)
                    <li class="border-s-2 border-gray-200 ps-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                            <span class="font-semibold text-gray-950 dark:text-white">{{ $entry['label'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $entry['occurred_at']->format('d/m/Y H:i') }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                par {{ $entry['actor_label'] }}@if ($entry['actor_role']) ({{ $entry['actor_role'] }})@endif
                            </span>
                        </div>

                        @if ($entry['details'] !== [])
                            <ul class="mt-1 list-inside list-disc text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($entry['details'] as $detail)
                                    <li>{{ $detail }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($entry['overlaps'] !== [])
                            <ul class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-200">
                                @foreach ($entry['overlaps'] as $overlap)
                                    <li>
                                        <span class="font-semibold">{{ $overlap['label'] }} :</span>
                                        {{ $overlap['message'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-panels::page>
