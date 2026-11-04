@props(['statusMessage' => null])

@if ($statusMessage)
    <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        {{ $statusMessage }}
    </div>
@endif

@if ($errors->any())
    <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
        <p class="font-semibold">L’action n’a pas pu être effectuée.</p>
        <ul class="mt-2 list-inside list-disc space-y-1">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
