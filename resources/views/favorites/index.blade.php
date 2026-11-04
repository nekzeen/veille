<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Mes favoris
            </h2>
            <a href="{{ route('veilles.index') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Mes veilles
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    @if ($articles->isEmpty())
                        <p class="text-gray-500">Vous n'avez pas encore de favori.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($articles as $article)
                                <li class="py-4">
                                    <a href="{{ route('articles.show', $article) }}"
                                       class="font-medium text-indigo-700 hover:underline">
                                        {{ $article->title }}
                                    </a>
                                    <p class="mt-1 text-sm text-gray-500">
                                        {{ $article->source->name ?? 'Source inconnue' }}
                                        · {{ ($article->published_at ?? $article->collected_at)->format('d/m/Y H:i') }}
                                        @if ($readMap[$article->id] ?? false)
                                            · <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Lu</span>
                                        @endif
                                        · <form method="POST" action="{{ route('articles.unfavorite', $article) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs text-indigo-700 hover:underline">Retirer le favori</button>
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
