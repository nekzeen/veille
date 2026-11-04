<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Mes veilles
            </h2>
            <a href="{{ route('veilles.create') }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Nouvelle veille
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-md">{{ session('status') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    @if ($veilles->isEmpty())
                        <p class="text-gray-500">Vous n'avez pas encore de veille.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($veilles as $veille)
                                <li class="py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900">
                                            <a href="{{ route('veilles.articles.index', $veille) }}" class="hover:underline">
                                                {{ $veille->name }}
                                            </a>
                                        </p>
                                        @if ($veille->description)
                                            <p class="text-sm text-gray-500 truncate">{{ $veille->description }}</p>
                                        @endif
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $veille->keywords_count }} mot-clé(s) ·
                                            {{ $veille->sources_count }} source(s) ·
                                            <span class="{{ $veille->is_active ? 'text-green-700' : 'text-amber-700' }}">
                                                {{ $veille->is_active ? 'Active' : 'En pause' }}
                                            </span> ·
                                            {{ $veille->alerts_enabled ? 'Alertes activées' : 'Alertes désactivées' }}
                                        </p>
                                    </div>
                                    <div class="shrink-0 flex flex-wrap items-center gap-2">
                                        <a href="{{ route('veilles.edit', $veille) }}"
                                           class="text-sm text-indigo-600 hover:underline">Modifier</a>
                                        <form method="POST" action="{{ route('veilles.toggle', $veille) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="text-sm text-gray-600 hover:underline">
                                                {{ $veille->is_active ? 'Mettre en pause' : 'Activer' }}
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('veilles.destroy', $veille) }}"
                                              onsubmit="return confirm('Supprimer définitivement cette veille ?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-sm text-red-600 hover:underline">
                                                Supprimer
                                            </button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
