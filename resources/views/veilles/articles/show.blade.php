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
                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-chip text-indigo-800">{{ $article->pivot->matched_keyword }}</span>
                        @endif
                        @if ($article->author)
                            · par {{ $article->author }}
                        @endif
                        · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $isRead ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $isRead ? 'Lu' : 'Non lu' }}</span>
                        · <form method="POST"
                                action="{{ route($isRead ? 'veilles.articles.unread' : 'veilles.articles.read', [$veille, $article]) }}"
                                class="inline">
                            @csrf
                            @method($isRead ? 'DELETE' : 'PUT')
                            <input type="hidden" name="return" value="show">
                            @foreach ($params as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="text-xs text-indigo-700 hover:underline">
                                {{ $isRead ? 'Marquer non lu' : 'Marquer lu' }}
                            </button>
                        </form>
                        @if ($isFavorite)
                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">★ Favori</span>
                        @endif
                        · <form method="POST"
                                action="{{ route($isFavorite ? 'veilles.articles.unfavorite' : 'veilles.articles.favorite', [$veille, $article]) }}"
                                class="inline">
                            @csrf
                            @method($isFavorite ? 'DELETE' : 'PUT')
                            <input type="hidden" name="return" value="show">
                            @foreach ($params as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="text-xs text-indigo-700 hover:underline">
                                {{ $isFavorite ? 'Retirer le favori' : 'Ajouter aux favoris' }}
                            </button>
                        </form>
                        · <form method="POST"
                                action="{{ route('veilles.articles.archive', [$veille, $article]) }}"
                                class="inline">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="return" value="index">
                            @foreach ($params as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" class="text-xs text-indigo-700 hover:underline">Archiver</button>
                        </form>
                    </p>
                    <p class="text-sm text-gray-500">
                        Catégorie :
                        <form method="POST" action="{{ route('veilles.articles.category', [$veille, $article]) }}" class="inline-flex items-center gap-2">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="return" value="show">
                            @foreach ($params as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <select name="category_id"
                                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm py-1">
                                <option value="">— choisir —</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="text-xs text-indigo-700 hover:underline">Classer</button>
                        </form>
                        @if ($categoryId !== null)
                            · <form method="POST" action="{{ route('veilles.articles.category.delete', [$veille, $article]) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="return" value="show">
                                @foreach ($params as $name => $value)
                                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                @endforeach
                                <button type="submit" class="text-xs text-indigo-700 hover:underline">Retirer la catégorie</button>
                            </form>
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
                    <h3 class="text-sm font-medium text-gray-700 mb-2">Ma note</h3>
                    @if ($note !== null)
                        <p class="text-sm text-gray-800 whitespace-pre-line mb-3">{{ $note }}</p>
                    @endif
                    <form method="POST" action="{{ route('veilles.articles.note', [$veille, $article]) }}" class="space-y-2">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return" value="show">
                        @foreach ($params as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <textarea name="note" rows="3"
                                  class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                  placeholder="Ajouter une note personnelle…">{{ old('note', $note) }}</textarea>
                        <button type="submit"
                                class="inline-flex items-center px-3 py-1.5 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Enregistrer la note
                        </button>
                        @if ($note !== null)
                            <button type="submit" form="delete-note"
                                    class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-700 uppercase tracking-widest hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                Supprimer la note
                            </button>
                        @endif
                    </form>
                    @if ($note !== null)
                        <form id="delete-note" method="POST" action="{{ route('veilles.articles.note.delete', [$veille, $article]) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="return" value="show">
                            @foreach ($params as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                        </form>
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
