<x-shared-layout :title="$article->title">
    <article class="bg-white rounded-lg border border-slate-300 shadow p-6">
        <p class="text-xs text-slate-500 mb-3">
            {{ $article->source?->name ?? 'Source inconnue' }}
            · {{ ($article->published_at ?? $article->collected_at)->format('d/m/Y') }}
        </p>
        <div class="prose prose-sm max-w-none text-slate-700">{!! $content !!}</div>
        <p class="mt-6">
            <a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">
                Lire l'article original
            </a>
        </p>
    </article>
</x-shared-layout>
