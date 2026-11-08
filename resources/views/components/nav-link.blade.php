@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-1.5 text-sm font-medium bg-blue-50 text-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition duration-150 ease-in-out'
            : 'inline-flex items-center whitespace-nowrap rounded-md px-2 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if ($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
