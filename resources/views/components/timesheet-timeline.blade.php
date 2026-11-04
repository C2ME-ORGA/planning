@props(['events'])

@if (count($events) === 0)
    <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-6 text-sm text-stone-500">
        Aucun événement enregistré pour ce relevé.
    </p>
@else
    <ol class="space-y-4" role="list">
        @foreach ($events as $event)
            <li class="border-s-2 border-stone-200 ps-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm font-semibold text-stone-900">{{ $event['label'] }}</p>
                    <p class="text-xs text-stone-500">{{ $event['occurred_at']->format('d/m/Y H:i') }}</p>
                </div>

                <p class="mt-0.5 text-xs text-stone-600">
                    {{ $event['actor'] ?? 'Acteur non identifié' }}
                    @if ($event['actor_role'])
                        · {{ $event['actor_role'] }}
                    @elseif ($event['actor'])
                        · Rôle non disponible
                    @endif
                </p>

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
@endif
