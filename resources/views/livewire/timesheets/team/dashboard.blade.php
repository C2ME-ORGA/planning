<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Mon équipe</h1>
            <p class="mt-1 text-sm text-stone-600">
                Suivi des relevés des salariés rattachés à vos équipes sur la période concernée.
            </p>
        </div>

        <p class="rounded-full bg-white px-3 py-1 text-xs font-medium text-stone-600 ring-1 ring-inset ring-stone-200">
            {{ $employeeCount }} salarié(s) dans le périmètre
        </p>
    </div>

    <section aria-labelledby="team-counters-heading" class="space-y-3">
        <h2 id="team-counters-heading" class="text-base font-semibold">Files de traitement</h2>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-controler']) }}" class="rounded-xl border border-sky-200 bg-sky-50 p-4 shadow-sm hover:border-sky-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-sky-800">À contrôler</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-sky-950">{{ $counts['to_control'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-corriger']) }}" class="rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm hover:border-rose-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-rose-800">En correction</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-rose-950">{{ $counts['correction'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'pretes']) }}" class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-amber-800">Prêts pour validation</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-amber-950">{{ $counts['ready'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'validees']) }}" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm hover:border-emerald-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800">Validés</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-emerald-950">{{ $counts['validated'] }}</p>
            </a>
        </div>
    </section>

    <section aria-labelledby="team-recent-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="team-recent-heading" class="text-base font-semibold">Derniers relevés du périmètre</h2>
            <a href="{{ route('workspace.timesheets.index') }}" class="text-sm font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">Tout consulter</a>
        </div>

        @if ($timesheets->isEmpty())
            <p class="mt-4 rounded-xl border border-dashed border-stone-300 px-4 py-6 text-sm text-stone-500">
                Aucun relevé dans votre périmètre pour le moment. Le périmètre dépend des affectations d’équipe et des responsabilités historisées.
            </p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-stone-500">
                            <th scope="col" class="py-2 pe-4 font-medium">Salarié</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Semaine</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Durée</th>
                            <th scope="col" class="py-2 pe-4 font-medium">Statut</th>
                            <th scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($timesheets as $timesheet)
                            <tr>
                                <td class="py-3 pe-4 font-medium">{{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}</td>
                                <td class="py-3 pe-4 text-stone-600">{{ $timesheet->period_start?->format('d/m/Y') }}</td>
                                <td class="py-3 pe-4 tabular-nums">{{ $summary->formatMinutes($summary->totalMinutes($timesheet)) }}</td>
                                <td class="py-3 pe-4"><x-status-badge :status="$timesheet->status" /></td>
                                <td class="py-3 text-right">
                                    <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">Consulter</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
