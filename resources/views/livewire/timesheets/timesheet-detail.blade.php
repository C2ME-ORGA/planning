<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('workspace.timesheets.index') }}" class="text-sm font-medium text-stone-500 underline-offset-4 hover:text-stone-800 hover:underline">← Retour aux relevés</a>
            <h1 class="mt-2 text-2xl font-semibold">{{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}</h1>
            <p class="mt-1 text-sm text-stone-600">
                Semaine du {{ $timesheet->period_start?->format('d/m/Y') }} au {{ $timesheet->period_end?->format('d/m/Y') }}
                · {{ $summary->formatMinutes($totalMinutes) }}
            </p>
        </div>

        <x-status-badge :status="$timesheet->status" />
    </div>

    <x-workflow-feedback :status-message="$statusMessage" />

    @if ($latestCorrection && $timesheet->status === \App\Enums\TimesheetStatus::ACorriger)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950" role="alert">
            <p class="font-semibold">Correction demandée</p>
            <p class="mt-1">
                Le {{ $latestCorrection['occurred_at']->format('d/m/Y à H:i') }}
                par {{ $latestCorrection['actor'] ?? 'un acteur non identifié' }}.
            </p>
            @if ($latestCorrection['reason'])
                <p class="mt-2"><span class="font-medium">Motif :</span> {{ $latestCorrection['reason'] }}</p>
            @endif
        </div>
    @endif

    <x-duration-warnings :warnings="$durationWarnings" :summary="$summary" />

    <div class="grid gap-6 lg:grid-cols-3">
        <section aria-labelledby="segments-heading" class="space-y-3 lg:col-span-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="segments-heading" class="text-base font-semibold">Segments</h2>
                <p class="text-xs text-stone-500">{{ $timesheet->segments->count() }} segment(s)</p>
            </div>

            @php
                $overlapSegmentIds = collect($overlaps)
                    ->flatMap(static fn (array $overlap): array => [
                        (int) $overlap['first']->getKey(),
                        (int) $overlap['second']->getKey(),
                    ])
                    ->unique()
                    ->all();
            @endphp

            @if ($timesheet->segments->isEmpty())
                <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-6 text-sm text-stone-500">Aucun segment enregistré.</p>
            @else
                <ul class="space-y-2 md:hidden" role="list">
                    @foreach ($timesheet->segments as $segment)
                        @php
                            $start = $segment->start_at ? \App\Support\BusinessTime::toLocal($segment->start_at) : null;
                            $end = $segment->end_at ? \App\Support\BusinessTime::toLocal($segment->end_at) : null;
                        @endphp
                        <li class="rounded-xl border border-stone-200 bg-white p-3 text-sm shadow-sm">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-semibold text-stone-900">{{ $segment->category->label() }}</p>
                                <p class="tabular-nums text-stone-600">{{ $summary->formatMinutes($summary->segmentMinutes($segment)) }}</p>
                            </div>
                            <p class="mt-1 tabular-nums text-xs text-stone-600">
                                {{ $start?->format('d/m/Y H:i') }} → {{ $end?->format('d/m/Y H:i') }}
                            </p>
                            @if (in_array((int) $segment->getKey(), $overlapSegmentIds, true))
                                <span class="mt-2 inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200">Chevauchement</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="hidden overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm md:block">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-sm">
                            <thead class="bg-stone-50">
                                <tr class="text-left text-xs uppercase tracking-wide text-stone-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Catégorie</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Début</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Fin</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Durée</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Alerte</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 bg-white">
                                @foreach ($timesheet->segments as $segment)
                                    @php
                                        $start = $segment->start_at ? \App\Support\BusinessTime::toLocal($segment->start_at) : null;
                                        $end = $segment->end_at ? \App\Support\BusinessTime::toLocal($segment->end_at) : null;
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-stone-900">{{ $segment->category->label() }}</td>
                                        <td class="px-4 py-3 tabular-nums text-stone-600">{{ $start?->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 tabular-nums text-stone-600">{{ $end?->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 tabular-nums">{{ $summary->formatMinutes($summary->segmentMinutes($segment)) }}</td>
                                        <td class="px-4 py-3">
                                            @if (in_array((int) $segment->getKey(), $overlapSegmentIds, true))
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200">Chevauchement</span>
                                            @else
                                                <span class="text-xs text-stone-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($canEdit && $isOwner)
                <a href="{{ route('workspace.employee.week', ['week' => $timesheet->period_start?->toDateString()]) }}" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                    Modifier les segments
                </a>
            @endif
        </section>

        <aside class="space-y-4" aria-label="Actions sur le relevé">
            <section class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-semibold">Actions</h2>

                <div class="mt-3 space-y-4">
                    @if ($canSubmit)
                        <button type="button" wire:click="submit" wire:confirm="Transmettre ce relevé pour contrôle ?" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                            Transmettre le relevé
                        </button>
                    @endif

                    @if ($canResume)
                        <button type="button" wire:click="resumeCorrection" wire:confirm="Reprendre la correction de ce relevé ?" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-rose-800 px-4 text-sm font-semibold text-white hover:bg-rose-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-700 focus-visible:ring-offset-2">
                            Reprendre la correction
                        </button>
                    @endif

                    @if ($canReturn)
                        <form wire:submit.prevent="requestCorrection" class="space-y-2 border-t border-stone-100 pt-4">
                            <label for="correction-reason" class="block text-sm font-medium text-stone-700">Retourner pour correction</label>
                            <textarea id="correction-reason" wire:model="correctionReason" rows="3" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Motif obligatoire (10 caractères minimum)"></textarea>
                            @error('correctionReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-rose-300 bg-rose-50 px-4 text-sm font-semibold text-rose-900 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2">
                                Retourner pour correction
                            </button>
                        </form>
                    @endif

                    @if ($canMarkReady)
                        <form wire:submit.prevent="markReadyForValidation" class="space-y-2 border-t border-stone-100 pt-4">
                            <label for="ready-reason" class="block text-sm font-medium text-stone-700">Marquer prêt pour validation</label>
                            <textarea id="ready-reason" wire:model="readyReason" rows="3" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Motif obligatoire (10 caractères minimum)"></textarea>
                            @error('readyReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                                Préparer pour la validation
                            </button>
                        </form>
                    @endif

                    @if ($canValidate)
                        <form wire:submit.prevent="validateTimesheet" class="space-y-2 border-t border-stone-100 pt-4">
                            <p class="text-sm font-medium text-stone-700">Validation finale</p>

                            @if ($selfValidation)
                                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    Vous validez votre propre relevé : un motif d’auto-validation est obligatoire.
                                </p>
                                <textarea wire:model="validationReason" rows="3" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Motif d’auto-validation (10 caractères minimum)"></textarea>
                                @error('validationReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            @endif

                            <button type="submit" wire:confirm="Valider définitivement ce relevé ?" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                                Valider le relevé
                            </button>
                        </form>
                    @endif

                    @if ($canReopen)
                        <form wire:submit.prevent="reopen" class="space-y-2 border-t border-stone-100 pt-4">
                            <label for="reopen-reason" class="block text-sm font-medium text-stone-700">Réouvrir le relevé</label>
                            <textarea id="reopen-reason" wire:model="reopenReason" rows="3" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Motif obligatoire (10 caractères minimum)"></textarea>
                            @error('reopenReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <button type="submit" wire:confirm="Réouvrir ce relevé en brouillon ?" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-semibold text-stone-800 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                                Réouvrir en brouillon
                            </button>
                        </form>
                    @endif

                    @if (! $canSubmit && ! $canResume && ! $canReturn && ! $canMarkReady && ! $canValidate && ! $canReopen)
                        <p class="text-sm text-stone-500">Aucune action disponible pour votre rôle sur ce statut.</p>
                    @endif
                </div>
            </section>
        </aside>
    </div>

    <section aria-labelledby="overlaps-heading" class="space-y-3">
        <h2 id="overlaps-heading" class="text-base font-semibold">Chevauchements</h2>

        @if ($overlaps === [])
            <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-6 text-sm text-stone-500">Aucun chevauchement détecté sur ce relevé.</p>
        @else
            <ul class="space-y-4" role="list">
                @foreach ($overlaps as $overlap)
                    @php
                        $firstStart = $overlap['first']->start_at ? \App\Support\BusinessTime::toLocal($overlap['first']->start_at) : null;
                        $firstEnd = $overlap['first']->end_at ? \App\Support\BusinessTime::toLocal($overlap['first']->end_at) : null;
                        $secondStart = $overlap['second']->start_at ? \App\Support\BusinessTime::toLocal($overlap['second']->start_at) : null;
                        $secondEnd = $overlap['second']->end_at ? \App\Support\BusinessTime::toLocal($overlap['second']->end_at) : null;
                        $stateClass = match ($overlap['state']) {
                            'accepted' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
                            'rejected' => 'bg-rose-50 text-rose-800 ring-rose-200',
                            'stale' => 'bg-stone-100 text-stone-700 ring-stone-300',
                            default => 'bg-amber-50 text-amber-900 ring-amber-200',
                        };
                        $stateLabel = match ($overlap['state']) {
                            'accepted' => 'Revue acceptée',
                            'rejected' => 'Revue rejetée',
                            'stale' => 'Revue obsolète',
                            default => 'À examiner',
                        };
                    @endphp

                    <li class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold text-stone-900">
                                    {{ $overlap['first']->category->label() }} ·
                                    {{ $firstStart?->format('d/m/Y H:i') }} → {{ $firstEnd?->format('H:i') }}
                                </p>
                                <p class="text-stone-600">
                                    chevauche {{ $overlap['second']->category->label() }} ·
                                    {{ $secondStart?->format('d/m/Y H:i') }} → {{ $secondEnd?->format('H:i') }}
                                </p>
                                <p class="text-xs text-stone-500">Paire {{ $overlap['pair_key'] }}</p>
                            </div>

                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $stateClass }}">{{ $stateLabel }}</span>
                        </div>

                        @if ($overlap['stale'])
                            <p class="mt-3 rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-700">
                                Une décision antérieure existe mais n’est plus applicable : un nouveau cycle de correction ou une modification de segment a rendu la revue obsolète.
                                @if ($overlap['review'])
                                    Dernière décision enregistrée : {{ $overlap['review']->decision->label() }}.
                                @endif
                            </p>
                        @endif

                        @if ($canViewOverlapHistory)
                            @if ($overlap['decisions'] !== [])
                                <div class="mt-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">Décisions enregistrées</h3>
                                    <ul class="mt-2 space-y-2" role="list">
                                        @foreach ($overlap['decisions'] as $decision)
                                            <li class="rounded-lg bg-stone-50 px-3 py-2 text-xs text-stone-700">
                                                <p class="font-medium text-stone-900">{{ $decision['decision'] ?? 'Décision' }}</p>
                                                <p>{{ $decision['occurred_at']->format('d/m/Y H:i') }} · {{ $decision['actor'] ?? 'Acteur non identifié' }}</p>
                                                @if ($decision['reason'])
                                                    <p class="mt-1">Motif : {{ $decision['reason'] }}</p>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($canReview)
                                <div class="mt-4 space-y-2 border-t border-stone-100 pt-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">
                                        {{ $overlap['needs_review'] ? 'Examiner ce chevauchement' : 'Remplacer la décision courante' }}
                                    </p>
                                    <div>
                                        <label for="overlap-decision-{{ $loop->index }}" class="mb-1 block text-sm font-medium text-stone-700">Décision</label>
                                        <select id="overlap-decision-{{ $loop->index }}" wire:model.live="overlapDecision" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                            @foreach ($decisionOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="overlap-reason-{{ $loop->index }}" class="mb-1 block text-sm font-medium text-stone-700">Motif obligatoire</label>
                                        <textarea id="overlap-reason-{{ $loop->index }}" wire:model="overlapReason" rows="2" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="10 caractères minimum"></textarea>
                                        @error('overlapReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                                    </div>

                                    <button type="button" wire:click="reviewOverlap('{{ $overlap['pair_key'] }}')" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-violet-700 px-4 text-sm font-semibold text-white hover:bg-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2">
                                        Enregistrer la décision
                                    </button>
                                </div>
                            @else
                                <p class="mt-3 text-xs text-stone-500">
                                    La revue n’est possible que sur un relevé à contrôler ou prêt pour validation. Les décisions affichées restent consultables.
                                </p>
                            @endif
                        @else
                            <p class="mt-3 text-xs text-stone-500">
                                L’examen des chevauchements est réservé au Bureau/Direction.
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="history-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
        <h2 id="history-heading" class="text-base font-semibold">Historique du relevé</h2>
        <p class="mt-1 text-xs text-stone-500">Chronologie issue du journal d’audit, en lecture seule.</p>

        <div class="mt-4">
            <x-timesheet-timeline :events="$events" />
        </div>
    </section>
</div>
