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
                <div class="flex items-center gap-2">
                    <p class="text-xs text-stone-500">{{ $timesheet->segments->count() }} segment(s)</p>
                    @if ($canEditBureauSegments)
                        <button type="button" wire:click="startAddingSegment" class="inline-flex min-h-11 items-center rounded-lg bg-violet-700 px-3 text-sm font-semibold text-white hover:bg-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2">
                            Ajouter un segment
                        </button>
                    @endif
                </div>
            </div>

            @if ($canEditBureauSegments && $segmentFormVisible)
                <form wire:submit.prevent="saveSegment" class="rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm" aria-labelledby="bureau-segment-form-title">
                    <h3 id="bureau-segment-form-title" class="font-semibold text-violet-950">
                        {{ $editingSegmentId === null ? 'Ajouter un segment' : 'Modifier le segment' }}
                    </h3>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="bureau-segment-category" class="mb-1 block text-xs font-medium text-stone-700">Catégorie</label>
                            <select id="bureau-segment-category" wire:model="segmentCategory" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                                @foreach (\App\Enums\TimeCategory::options() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('segmentCategory') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bureau-segment-start-date" class="mb-1 block text-xs font-medium text-stone-700">Date de début</label>
                            <input id="bureau-segment-start-date" type="date" wire:model="segmentStartDate" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                            @error('segmentStartDate') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bureau-segment-start-time" class="mb-1 block text-xs font-medium text-stone-700">Heure de début</label>
                            <input id="bureau-segment-start-time" type="time" wire:model="segmentStartTime" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                            @error('segmentStartTime') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bureau-segment-end-date" class="mb-1 block text-xs font-medium text-stone-700">Date de fin</label>
                            <input id="bureau-segment-end-date" type="date" wire:model="segmentEndDate" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                            @error('segmentEndDate') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bureau-segment-end-time" class="mb-1 block text-xs font-medium text-stone-700">Heure de fin</label>
                            <input id="bureau-segment-end-time" type="time" wire:model="segmentEndTime" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                            @error('segmentEndTime') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <details class="mt-3 rounded-lg bg-white/60 p-3">
                        <summary class="min-h-11 cursor-pointer text-xs font-semibold text-stone-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600">Cas particulier : changement d’heure</summary>
                        <p class="mt-2 text-xs text-stone-600">À l’heure d’hiver, une heure locale peut être ambiguë. Indiquez l’offset uniquement dans ce cas.</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="bureau-segment-start-offset" class="mb-1 block text-xs font-medium text-stone-700">Offset de début (minutes)</label>
                                <select id="bureau-segment-start-offset" wire:model="segmentStartOffsetMinutes" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                                    <option value="">Automatique</option>
                                    <option value="60">+60 (heure d’hiver)</option>
                                    <option value="120">+120 (heure d’été)</option>
                                </select>
                                @error('segmentStartOffsetMinutes') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="bureau-segment-end-offset" class="mb-1 block text-xs font-medium text-stone-700">Offset de fin (minutes)</label>
                                <select id="bureau-segment-end-offset" wire:model="segmentEndOffsetMinutes" class="min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 text-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-200">
                                    <option value="">Automatique</option>
                                    <option value="60">+60 (heure d’hiver)</option>
                                    <option value="120">+120 (heure d’été)</option>
                                </select>
                                @error('segmentEndOffsetMinutes') <p class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </details>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-lg bg-violet-700 px-4 text-sm font-semibold text-white hover:bg-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2">
                            Enregistrer la correction
                        </button>
                        <button type="button" wire:click="cancelSegmentEdit" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2">
                            Annuler
                        </button>
                    </div>
                </form>
            @endif

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
                            @if ($canEditBureauSegments)
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="editSegment({{ $segment->getKey() }})" class="inline-flex min-h-11 items-center rounded-lg border border-violet-300 bg-violet-50 px-3 text-xs font-semibold text-violet-900 hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600">
                                        Modifier
                                    </button>
                                    <button type="button" wire:click="deleteSegment({{ $segment->getKey() }})" wire:confirm="Supprimer ce segment ?" class="inline-flex min-h-11 items-center rounded-lg border border-rose-300 bg-rose-50 px-3 text-xs font-semibold text-rose-900 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600">
                                        Supprimer
                                    </button>
                                </div>
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
                                    @if ($canEditBureauSegments)
                                        <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                                    @endif
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
                                        @if ($canEditBureauSegments)
                                            <td class="px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="editSegment({{ $segment->getKey() }})" class="inline-flex min-h-11 items-center rounded-lg border border-violet-300 bg-violet-50 px-3 text-xs font-semibold text-violet-900 hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600">
                                                        Modifier
                                                    </button>
                                                    <button type="button" wire:click="deleteSegment({{ $segment->getKey() }})" wire:confirm="Supprimer ce segment ?" class="inline-flex min-h-11 items-center rounded-lg border border-rose-300 bg-rose-50 px-3 text-xs font-semibold text-rose-900 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600">
                                                        Supprimer
                                                    </button>
                                                </div>
                                            </td>
                                        @endif
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

                    @if ($canSendToValidation)
                        <form wire:submit.prevent="sendToValidation" class="space-y-2 border-t border-stone-100 pt-4">
                            <label for="direct-reason" class="block text-sm font-medium text-stone-700">Transmettre directement à la validation</label>
                            <p class="text-xs text-stone-500">
                                Correction Bureau/Direction : le relevé passe en « Prête pour validation » sans transmission préalable du salarié. Le validateur final doit rester indépendant.
                            </p>
                            <textarea id="direct-reason" wire:model="directReason" rows="3" class="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200" placeholder="Motif obligatoire (10 caractères minimum)"></textarea>
                            @error('directReason') <p class="text-sm text-rose-700" role="alert">{{ $message }}</p> @enderror
                            <button type="submit" wire:confirm="Transmettre ce relevé corrigé à la validation finale ?" class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-violet-700 px-4 text-sm font-semibold text-white hover:bg-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 focus-visible:ring-offset-2">
                                Transmettre à la validation
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

                    @if (! $canSubmit && ! $canResume && ! $canReturn && ! $canMarkReady && ! $canSendToValidation && ! $canValidate && ! $canReopen)
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
