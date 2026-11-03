<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $category->name }}
            </h2>
            <a href="{{ route('categories.index') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Mes catégories
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if ($articles->isEmpty())
                        <p class="text-gray-500">Aucun article dans cette catégorie.</p>
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
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-4">{{ $articles->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
