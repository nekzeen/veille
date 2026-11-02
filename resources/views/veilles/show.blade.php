<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $veille->name }}
            </h2>
            <a href="{{ route('veilles.edit', $veille) }}"
               class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                Modifier
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-md">{{ session('status') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    @if ($veille->description)
                        <p class="text-gray-700">{{ $veille->description }}</p>
                    @endif

                    <p class="text-sm">
                        <span class="{{ $veille->is_active ? 'text-green-700' : 'text-amber-700' }} font-medium">
                            {{ $veille->is_active ? 'Veille active' : 'Veille en pause' }}
                        </span>
                        <span class="text-gray-500">
                            · {{ $veille->alerts_enabled ? 'Alertes e-mail quotidiennes activées' : 'Alertes désactivées' }}
                        </span>
                    </p>

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Mots-clés</h3>
                        @if ($veille->keywords->isEmpty())
                            <p class="text-sm text-gray-500">Aucun mot-clé.</p>
                        @else
                            <ul class="flex flex-wrap gap-2">
                                @foreach ($veille->keywords as $keyword)
                                    <li class="px-2 py-1 bg-indigo-50 text-indigo-800 text-xs rounded">
                                        {{ $keyword->term }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Sources</h3>
                        @if ($veille->sources->isEmpty())
                            <p class="text-sm text-gray-500">Aucune source associée.</p>
                        @else
                            <ul class="space-y-1">
                                @foreach ($veille->sources as $source)
                                    <li class="text-sm text-gray-700">
                                        {{ $source->name }}
                                        <span class="text-xs text-gray-400">
                                            — {{ $source->is_catalogue ? 'Catalogue' : 'Personnelle' }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 flex flex-wrap items-center gap-4">
                    <form method="POST" action="{{ route('veilles.toggle', $veille) }}">
                        @csrf
                        @method('PATCH')
                        <x-secondary-button type="submit">
                            {{ $veille->is_active ? 'Mettre en pause' : 'Activer' }}
                        </x-secondary-button>
                    </form>
                    <form method="POST" action="{{ route('veilles.destroy', $veille) }}"
                          onsubmit="return confirm('Supprimer définitivement cette veille ?');">
                        @csrf
                        @method('DELETE')
                        <x-danger-button type="submit">Supprimer</x-danger-button>
                    </form>
                    <a href="{{ route('veilles.index') }}" class="text-sm text-gray-600 hover:underline">
                        Retour à mes veilles
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
