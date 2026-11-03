<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $article->title }}
            </h2>
            <a href="{{ route('veilles.articles.index', [$veille] + $params) }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 shrink-0">
                Retour à la liste
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-3">
                    <p class="text-sm text-gray-500">
                        {{ $article->source->name ?? 'Source inconnue' }}
                        · {{ ($article->published_at ?? $article->collected_at)->format('d/m/Y H:i') }}
                        @if ($article->pivot->matched_keyword !== null)
                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">{{ $article->pivot->matched_keyword }}</span>
                        @endif
                        @if ($article->author)
                            · par {{ $article->author }}
                        @endif
                    </p>
                    @if ($originalUrl !== null)
                        <a href="{{ $originalUrl }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center text-sm text-indigo-700 hover:underline">
                            Ouvrir l'article original ↗
                        </a>
                    @endif
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($body !== '')
                        <div class="article-content space-y-4">{!! $body !!}</div>
                    @else
                        <p class="text-gray-500">
                            Le contenu de cet article n'est pas disponible en lecture interne.
                            @if ($originalUrl !== null)
                                <a href="{{ $originalUrl }}" target="_blank" rel="noopener noreferrer"
                                   class="text-indigo-700 hover:underline">Consulter l'article original ↗</a>
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="flex items-center justify-between">
                @if ($prev)
                    <a href="{{ route('veilles.articles.show', [$veille, $prev] + $params) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        ← Plus récent
                    </a>
                @else
                    <span></span>
                @endif
                @if ($next)
                    <a href="{{ route('veilles.articles.show', [$veille, $next] + $params) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Plus ancien →
                    </a>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
