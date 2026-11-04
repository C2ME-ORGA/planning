@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\TimesheetStatus::Brouillon => 'bg-stone-100 text-stone-700 ring-stone-200',
        \App\Enums\TimesheetStatus::AControler => 'bg-sky-50 text-sky-800 ring-sky-200',
        \App\Enums\TimesheetStatus::ACorriger => 'bg-rose-50 text-rose-800 ring-rose-200',
        \App\Enums\TimesheetStatus::PretePourValidation => 'bg-amber-50 text-amber-900 ring-amber-200',
        \App\Enums\TimesheetStatus::Validee => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.$classes]) }}>
    {{ $status->label() }}
</span>
