<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Validation en masse</h1>
            <p class="mt-1 text-sm text-stone-600">Sélectionnez des relevés prêts pour validation. Chaque relevé conserve ses règles individuelles (séparation des tâches, auto-validation motivée, chevauchements).</p>
        </div>

        <p class="rounded-full bg-white px-3 py-1 text-xs font-medium text-stone-600 ring-1 ring-inset ring-stone-200">
            50 relevés maximum par traitement
        </p>
    </div>

    <x-workflow-feedback :status-message="$statusMessage" />

    @if ($results)
        @php
            $tone = $results['failed'] === 0
                ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                : ($results['succeeded'] === 0 ? 'border-rose-200 bg-rose-50 text-rose-950' : 'border-amber-200 bg-amber-50 text-amber-950');
        @endphp

        <section aria-labelledby="batch-results-heading" class="rounded-xl border p-5 shadow-sm {{ $tone }}">
            <h2 id="batch-results-heading" class="text-base font-semibold">Bilan du traitement</h2>
            <p class="mt-1 text-sm">
                {{ $results['requested'] }} demandé(s) · {{ $results['succeeded'] }} validé(s) · {{ $results['failed'] }} en échec.
            </p>

            @if ($results['partial'])
                <p class="mt-2 text-sm font-medium">Traitement partiel : certains relevés n’ont pas pu être validés.</p>
            @endif

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-black/10 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide">
                            <th scope="col" class="py-2 pe-4 font-medium">Relevé</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Résultat</th>
                            <th scope="col" class="py-2 font-medium">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/10">
                        @foreach ($results['results'] as $result)
                            <tr>
                                <td class="py-2 pe-4 font-medium">
                                    @if ($result['reference'])
                                        Semaine du {{ \Carbon\CarbonImmutable::parse($result['reference'])->format('d/m/Y') }}
                                    @else
                                        Relevé n°{{ $result['timesheet_id'] }}
                                    @endif
                                </td>
                                <td class="py-2 pe-4">
                                    @if ($result['result'] === 'success')
                                        <span class="font-semibold">Validé</span>
                                    @else
                                        <span class="font-semibold">Échec</span>
                                    @endif
                                </td>
                                <td class="py-2">{{ $result['message'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section aria-labelledby="batch-list-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="batch-list-heading" class="text-base font-semibold">Relevés prêts pour validation</h2>
            <p class="text-sm text-stone-600">{{ count($selected) }} sélectionné(s)</p>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <label for="batch-search" class="mb-1 block text-xs font-medium text-stone-700">Rechercher un salarié</label>
            <input id="batch-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Nom ou prénom" class="min-h-11 w-full max-w-md rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
        </div>

        @if ($timesheets->isEmpty())
            <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-8 text-center text-sm text-stone-500">
                Aucun relevé prêt pour validation ne correspond à cette recherche.
            </p>
        @else
            <div class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
                <ul class="divide-y divide-stone-100" role="list">
                    @foreach ($timesheets as $timesheet)
                        <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                            <label class="flex min-h-11 flex-1 cursor-pointer items-center gap-3">
                                <input type="checkbox" value="{{ $timesheet->getKey() }}" wire:model.live="selected" class="size-5 rounded border-stone-300 text-amber-700 focus:ring-amber-600">
                                <span>
                                    <span class="block text-sm font-semibold text-stone-900">{{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}</span>
                                    <span class="block text-xs text-stone-500">
                                        Semaine du {{ $timesheet->period_start?->format('d/m/Y') }}
                                        · {{ $summary->formatMinutes($summary->totalMinutes($timesheet)) }}
                                    </span>
                                </span>
                            </label>

                            <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="text-sm font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">Consulter</a>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-stone-200 px-4 py-3">
                    {{ $timesheets->links() }}
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-stone-600">
                    {{ count($selected) }} relevé(s) sélectionné(s). Aucun motif commun n’est demandé.
                </p>

                <button type="button" wire:click="validateSelection" wire:confirm="Valider les relevés sélectionnés ?" class="inline-flex min-h-11 items-center rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                    Valider la sélection
                </button>
            </div>
        @endif
    </section>
</div>
