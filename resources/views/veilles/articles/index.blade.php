<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Articles — {{ $veille->name }}
            </h2>
            <a href="{{ route('veilles.show', $veille) }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Retour à la veille
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="GET" action="{{ route('veilles.articles.index', $veille) }}"
                          class="flex flex-wrap items-end gap-4">
                        <div>
                            <label for="source_id" class="block text-sm font-medium text-gray-700">Source</label>
                            <select id="source_id" name="source_id"
                                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <option value="">Toutes les sources</option>
                                @foreach ($filterSources as $filterSource)
                                    <option value="{{ $filterSource->id }}"
                                        @selected(($params['source_id'] ?? null) === $filterSource->id)>
                                        {{ $filterSource->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="keyword" class="block text-sm font-medium text-gray-700">Mot-clé correspondant</label>
                            <select id="keyword" name="keyword"
                                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                <option value="">Tous les mots-clés</option>
                                @foreach ($keywords as $kw)
                                    <option value="{{ $kw }}"
                                        @selected(($params['keyword'] ?? null) === $kw)>
                                        {{ $kw }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Filtrer
                        </button>
                        @if ($params !== [])
                            <a href="{{ route('veilles.articles.index', $veille) }}"
                               class="text-sm text-gray-500 hover:underline">Réinitialiser</a>
                        @endif
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($articles->isEmpty())
                        <p class="text-gray-500">Aucun article associé à cette veille sur les six derniers mois.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($articles as $article)
                                <li class="py-4">
                                    <a href="{{ route('veilles.articles.show', [$veille, $article] + $params) }}"
                                       class="font-medium text-indigo-700 hover:underline">
                                        {{ $article->title }}
                                    </a>
                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ $article->source->name ?? 'Source inconnue' }}
                                        · {{ ($article->published_at ?? $article->collected_at)->format('d/m/Y H:i') }}
                                        @if ($article->pivot->matched_keyword !== null)
                                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">{{ $article->pivot->matched_keyword }}</span>
                                        @endif
                                        @if ($readMap[$article->id] ?? false)
                                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Lu</span>
                                        @else
                                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Non lu</span>
                                        @endif
                                        · <form method="POST"
                                                action="{{ route(($readMap[$article->id] ?? false) ? 'veilles.articles.unread' : 'veilles.articles.read', [$veille, $article]) }}"
                                                class="inline">
                                            @csrf
                                            @method(($readMap[$article->id] ?? false) ? 'DELETE' : 'PUT')
                                            <input type="hidden" name="return" value="index">
                                            @foreach ($params as $name => $value)
                                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                            @endforeach
                                            <button type="submit" class="text-xs text-indigo-700 hover:underline">
                                                {{ ($readMap[$article->id] ?? false) ? 'Marquer non lu' : 'Marquer lu' }}
                                            </button>
                                        </form>
                                        @if ($favMap[$article->id] ?? false)
                                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">★ Favori</span>
                                        @endif
                                        · <form method="POST"
                                                action="{{ route(($favMap[$article->id] ?? false) ? 'veilles.articles.unfavorite' : 'veilles.articles.favorite', [$veille, $article]) }}"
                                                class="inline">
                                            @csrf
                                            @method(($favMap[$article->id] ?? false) ? 'DELETE' : 'PUT')
                                            <input type="hidden" name="return" value="index">
                                            @foreach ($params as $name => $value)
                                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                            @endforeach
                                            <button type="submit" class="text-xs text-indigo-700 hover:underline">
                                                {{ ($favMap[$article->id] ?? false) ? 'Retirer le favori' : 'Ajouter aux favoris' }}
                                            </button>
                                        </form>
                                    </p>
                                    @if ($article->summary !== null && $article->summary !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ Str::limit(strip_tags($article->summary), 200) }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-4">
                            {{ $articles->links() }}
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
