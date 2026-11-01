<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Ma semaine</h1>
            <p class="mt-1 text-sm text-stone-600">
                Du {{ $days[0]->locale('fr')->translatedFormat('j F Y') }} au {{ $days[6]->locale('fr')->translatedFormat('j F Y') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousWeek" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-stone-300 bg-white text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2" aria-label="Semaine précédente">←</button>
            <button type="button" wire:click="currentWeek" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">Cette semaine</button>
            <button type="button" wire:click="nextWeek" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-stone-300 bg-white text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2" aria-label="Semaine suivante">→</button>
        </div>
    </div>

    <x-workflow-feedback :status-message="$statusMessage" />

    @if (! $employee)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">
            <p class="font-semibold">Compte non rattaché à une fiche salarié</p>
            <p class="mt-1">La saisie des heures est indisponible. Contactez le Bureau/Direction pour vérifier le rattachement de votre compte.</p>
        </div>
    @else
        <section aria-labelledby="week-status-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="week-status-heading" class="text-base font-semibold">État du relevé</h2>
                    <p class="mt-1 text-sm text-stone-600">{{ $employee->fullName() }}</p>
                </div>

                <div class="text-right">
                    @if ($timesheet)
                        <x-status-badge :status="$timesheet->status" />
                        <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $summary->formatMinutes($totalMinutes) }}</p>
                        <p class="text-xs text-stone-500">enregistrées</p>
                    @else
                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-200">Non ouverte</span>
                    @endif
                </div>
            </div>

            @if ($latestCorrection && $timesheet?->status === \App\Enums\TimesheetStatus::ACorriger)
                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950" role="alert">
                    <p class="font-semibold">Correction demandée</p>
                    <p class="mt-1">
                        Le {{ $latestCorrection['occurred_at']->format('d/m/Y à H:i') }}
                        par {{ $latestCorrection['actor'] ?? 'un acteur non identifié' }}.
                    </p>
                    @if ($latestCorrection['reason'])
                        <p class="mt-2"><span class="font-medium">Motif :</span> {{ $latestCorrection['reason'] }}</p>
                    @endif

                    @if ($canResume)
                        <button type="button" wire:click="resumeCorrection" wire:confirm="Reprendre la correction de ce relevé ?" class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-rose-800 px-4 text-sm font-semibold text-white hover:bg-rose-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-700 focus-visible:ring-offset-2">
                            Reprendre la correction
                        </button>
                    @endif
                </div>
            @endif

            @if ($timesheet && ! $timesheet->status->isEditable() && $timesheet->status !== \App\Enums\TimesheetStatus::ACorriger)
                <p class="mt-4 rounded-lg bg-stone-50 px-4 py-3 text-sm text-stone-700">
                    Ce relevé est verrouillé en lecture seule ({{ $timesheet->status->label() }}). Aucun segment ne peut être ajouté, modifié ou supprimé.
                </p>
            @endif

            @if ($canOpen)
                <button type="button" wire:click="openWeek" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                    Ouvrir ma semaine
                </button>
            @endif

            @if ($timesheet)
                <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="mt-4 inline-block text-sm font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">
                    Voir la fiche complète et l’historique
                </a>
            @endif
        </section>

        @if ($timesheet)
            <section aria-labelledby="week-grid-heading" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="week-grid-heading" class="text-base font-semibold">Planning de la semaine</h2>
                    <p class="text-xs text-stone-500">Les durées sont calculées à la minute, sans arrondi.</p>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-7">
                    @foreach ($days as $day)
                        @php
                            $dateKey = $day->format('Y-m-d');
                            $daySegments = $segmentsByDay[$dateKey] ?? collect();
                            $dayMinutes = $minutesByDay[$dateKey] ?? 0;
                            $isToday = $day->isSameDay(\App\Support\BusinessTime::toLocal(\Carbon\CarbonImmutable::now()));
                        @endphp

                        <article class="rounded-xl border bg-white p-3 shadow-sm {{ $isToday ? 'border-amber-400' : 'border-stone-200' }}">
                            <header class="flex items-start justify-between gap-2 border-b border-stone-100 pb-2">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide {{ $isToday ? 'text-amber-800' : 'text-stone-500' }}">
                                        {{ $day->locale('fr')->translatedFormat('D') }}
                                    </p>
                                    <p class="text-lg font-semibold leading-tight">{{ $day->format('d') }}</p>
                                    <p class="text-xs text-stone-500">{{ $day->locale('fr')->translatedFormat('M') }}</p>
                                </div>
                                <p class="rounded-full bg-stone-100 px-2 py-1 text-xs font-semibold tabular-nums text-stone-700">{{ $summary->formatMinutes($dayMinutes) }}</p>
                            </header>

                            <div class="mt-2 space-y-2">
                                @forelse ($daySegments as $segment)
                                    @php
                                        $start = $segment->start_at ? \App\Support\BusinessTime::toLocal($segment->start_at) : null;
                                        $end = $segment->end_at ? \App\Support\BusinessTime::toLocal($segment->end_at) : null;
                                        $categoryClass = match ($segment->category) {
                                            \App\Enums\TimeCategory::TravailEffectif => 'border-emerald-400 bg-emerald-50 text-emerald-950',
                                            \App\Enums\TimeCategory::Trajet => 'border-sky-400 bg-sky-50 text-sky-950',
                                            \App\Enums\TimeCategory::Pause => 'border-stone-400 bg-stone-50 text-stone-800',
                                            \App\Enums\TimeCategory::Formation => 'border-violet-400 bg-violet-50 text-violet-950',
                                            \App\Enums\TimeCategory::Autre => 'border-amber-400 bg-amber-50 text-amber-950',
                                        };
                                    @endphp

                                    <div class="rounded-lg border-s-4 px-2 py-2 text-xs {{ $categoryClass }}">
                                        <p class="font-semibold">{{ $segment->category->label() }}</p>
                                        <p class="mt-1 tabular-nums">
                                            {{ $start?->format('H:i') }} → {{ $end?->format('H:i') }}
                                            @if ($start && $end && ! $start->isSameDay($end))
                                                <span class="block text-[11px] opacity-80">fin le {{ $end->format('d/m') }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-[11px] font-medium">{{ $summary->formatMinutes($summary->segmentMinutes($segment)) }}</p>

                                        @if ($canEdit)
                                            <div class="mt-2 flex gap-1">
                                                <button type="button" wire:click="editSegment({{ $segment->getKey() }})" class="rounded-md border border-current px-2 py-1 text-[11px] font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600" aria-label="Modifier le segment">Modifier</button>
                                                <button type="button" wire:click="deleteSegment({{ $segment->getKey() }})" wire:confirm="Supprimer ce segment ?" class="rounded-md border border-current px-2 py-1 text-[11px] font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" aria-label="Supprimer le segment">Suppr.</button>
                                            </div>
                                        @endif
                                    </div>
                                @empty
                                    <p class="rounded-lg border border-dashed border-stone-200 px-2 py-3 text-center text-xs text-stone-500">Aucun segment</p>
                                @endforelse

                                @if ($canEdit)
                                    <button type="button" wire:click="selectDay('{{ $dateKey }}')" class="w-full rounded-lg border border-dashed border-stone-300 px-2 py-2 text-xs font-semibold text-stone-600 hover:border-amber-400 hover:text-amber-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                                        + Ajouter
                                    </button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            @if ($canEdit)
                <section aria-labelledby="segment-form-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                    <h2 id="segment-form-heading" class="text-base font-semibold">
                        {{ $editingSegmentId ? 'Modifier le segment' : 'Ajouter un segment' }}
                    </h2>
                    <p class="mt-1 text-sm text-stone-600">
                        Les horaires ne sont jamais ajustés automatiquement. Pour un segment qui traverse minuit, indiquez une date de fin différente.
                    </p>

                    <form wire:submit="saveSegment" class="mt-4 space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <label for="start-date" class="mb-1 block text-sm font-medium text-stone-700">Date de début</label>
                                <input id="start-date" type="date" wire:model="startDate" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                @error('startDate') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="start-time" class="mb-1 block text-sm font-medium text-stone-700">Heure de début</label>
                                <input id="start-time" type="time" wire:model="startTime" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                @error('startTime') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="end-date" class="mb-1 block text-sm font-medium text-stone-700">Date de fin</label>
                                <input id="end-date" type="date" wire:model="endDate" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                @error('endDate') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="end-time" class="mb-1 block text-sm font-medium text-stone-700">Heure de fin</label>
                                <input id="end-time" type="time" wire:model="endTime" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                @error('endTime') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="segment-category" class="mb-1 block text-sm font-medium text-stone-700">Catégorie</label>
                                <select id="segment-category" wire:model="category" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                    @foreach (\App\Enums\TimeCategory::options() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('category') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>

                            <div class="text-sm text-stone-600">
                                <p class="font-medium text-stone-700">Avertissements</p>
                                <p class="mt-1">Un chevauchement est signalé mais ne bloque pas l’enregistrement. Aucun horaire existant n’est déplacé.</p>
                            </div>
                        </div>

                        <details class="rounded-lg bg-stone-50 p-3">
                            <summary class="min-h-11 cursor-pointer text-sm font-semibold text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                                Cas particulier : changement d’heure
                            </summary>
                            <p class="mt-2 text-xs text-stone-600">À l’heure d’hiver, une heure locale peut être ambiguë. Indiquez l’offset uniquement dans ce cas.</p>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="start-offset" class="mb-1 block text-xs font-medium text-stone-700">Offset de début (minutes)</label>
                                    <select id="start-offset" wire:model="startOffsetMinutes" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                        <option value="">Automatique</option>
                                        <option value="60">+60 (heure d’hiver)</option>
                                        <option value="120">+120 (heure d’été)</option>
                                    </select>
                                    @error('startOffsetMinutes') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="end-offset" class="mb-1 block text-xs font-medium text-stone-700">Offset de fin (minutes)</label>
                                    <select id="end-offset" wire:model="endOffsetMinutes" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                        <option value="">Automatique</option>
                                        <option value="60">+60 (heure d’hiver)</option>
                                        <option value="120">+120 (heure d’été)</option>
                                    </select>
                                    @error('endOffsetMinutes') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </details>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                                {{ $editingSegmentId ? 'Enregistrer la modification' : 'Ajouter le segment' }}
                            </button>

                            @if ($editingSegmentId)
                                <button type="button" wire:click="cancelEdit" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                                    Annuler
                                </button>
                            @endif
                        </div>
                    </form>
                </section>
            @endif

            <section aria-labelledby="submit-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 id="submit-heading" class="text-base font-semibold">Transmission</h2>
                <p class="mt-1 text-sm text-stone-600">
                    {{ $summary->formatMinutes($totalMinutes) }} enregistrées sur la semaine.
                    La transmission envoie le relevé au contrôle de votre responsable.
                </p>

                @if ($canSubmit)
                    <button type="button" wire:click="submit" wire:confirm="Transmettre ce relevé pour contrôle ?" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                        Transmettre le relevé
                    </button>
                @else
                    <p class="mt-3 text-sm text-stone-500">
                        @if ($timesheet->status === \App\Enums\TimesheetStatus::Brouillon)
                            La transmission nécessite au moins un segment et un compte actif lié à votre fiche.
                        @else
                            Ce relevé est au statut « {{ $timesheet->status->label() }} » : aucune nouvelle transmission n’est possible depuis cet état.
                        @endif
                    </p>
                @endif
            </section>

            @if ($overlaps !== [])
                <section aria-labelledby="overlaps-heading" class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950">
                    <h2 id="overlaps-heading" class="text-base font-semibold">Chevauchements signalés</h2>
                    <p class="mt-1">Ces chevauchements ne bloquent pas la saisie ni la transmission. Aucun horaire n’a été modifié automatiquement.</p>

                    <ul class="mt-3 space-y-2" role="list">
                        @foreach ($overlaps as $overlap)
                            @php
                                $firstStart = $overlap['first']->start_at ? \App\Support\BusinessTime::toLocal($overlap['first']->start_at) : null;
                                $firstEnd = $overlap['first']->end_at ? \App\Support\BusinessTime::toLocal($overlap['first']->end_at) : null;
                                $secondStart = $overlap['second']->start_at ? \App\Support\BusinessTime::toLocal($overlap['second']->start_at) : null;
                                $secondEnd = $overlap['second']->end_at ? \App\Support\BusinessTime::toLocal($overlap['second']->end_at) : null;
                            @endphp
                            <li class="rounded-lg bg-white/70 px-3 py-2">
                                <p class="font-medium">{{ $overlap['first']->category->label() }} le {{ $firstStart?->format('d/m/Y') }} de {{ $firstStart?->format('H:i') }} à {{ $firstEnd?->format('H:i') }}</p>
                                <p class="text-xs">chevauche {{ $overlap['second']->category->label() }} le {{ $secondStart?->format('d/m/Y') }} de {{ $secondStart?->format('H:i') }} à {{ $secondEnd?->format('H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
    @endif
</div>
