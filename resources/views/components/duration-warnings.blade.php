@props(['warnings' => [], 'summary'])

@if (count($warnings) > 0)
    <section
        role="status"
        aria-labelledby="duration-warnings-title"
        class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
    >
        <h2 id="duration-warnings-title" class="font-semibold">Avertissement de durée</h2>
        <p class="mt-1">
            Un ou plusieurs segments dépassent 12 heures. Cet avertissement est non bloquant :
            la saisie et la transmission restent possibles.
        </p>
        <ul class="mt-2 list-inside list-disc space-y-1">
            @foreach ($warnings as $warning)
                <li>
                    Segment du
                    {{ \App\Support\BusinessTime::toLocal($warning['start_at'])->format('d/m/Y H:i') }}
                    au
                    {{ \App\Support\BusinessTime::toLocal($warning['end_at'])->format('d/m/Y H:i') }} :
                    {{ $summary->formatMinutes($warning['minutes']) }} (plus de 12 heures).
                </li>
            @endforeach
        </ul>
    </section>
@endif
