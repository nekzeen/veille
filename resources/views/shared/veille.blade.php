<x-shared-layout :title="$veille->name">
    @if ($share->isGlobal() && $veilles->count() > 1)
        <nav class="mb-4 flex flex-wrap gap-2 text-sm">
            @foreach ($veilles as $v)
                <a href="{{ route('shared.veille', ['token' => $token, 'veille' => $v->id]) }}"
                   class="rounded-full px-3 py-1 border {{ $v->id === $veille->id ? 'bg-violet-600 text-white border-violet-600' : 'bg-white text-slate-700 border-slate-300' }}">
                    {{ $v->name }}
                </a>
            @endforeach
        </nav>
    @endif

    @if ($veille->description)
        <p class="mb-4 text-sm text-slate-600">{{ $veille->description }}</p>
    @endif

    <ul class="space-y-3">
        @forelse ($articles as $a)
            <li class="bg-white rounded-lg border border-slate-300 shadow p-4">
                <a href="{{ route('shared.article', ['token' => $token, 'article' => $a->id]) }}"
                   class="font-medium text-slate-800 hover:text-violet-700">{{ $a->title }}</a>
                @if ($a->summary)
                    <p class="mt-1 text-sm text-slate-600 line-clamp-2">{{ Str::limit(strip_tags($a->summary), 180) }}</p>
                @endif
                <p class="mt-2 text-xs text-slate-500">
                    {{ $a->source?->name ?? 'Source inconnue' }}
                    · {{ ($a->published_at ?? $a->collected_at)->format('d/m/Y') }}
                    · <a href="{{ $a->url }}" target="_blank" rel="noopener noreferrer" class="text-violet-700 hover:underline">article original</a>
                </p>
            </li>
        @empty
            <li class="bg-white rounded-lg border border-slate-300 shadow p-6 text-sm text-slate-500">
                Aucun article dans cette veille pour le moment.
            </li>
        @endforelse
    </ul>

    <div class="mt-4">{{ $articles->links() }}</div>
</x-shared-layout>
