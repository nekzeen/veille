<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Mes exclusions</h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 rounded-md px-4 py-2 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-md px-4 py-2 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- Domaines et URL exclus --}}
            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-1">Domaines et URL exclus</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Les articles dont l'adresse finale correspond à ces règles seront écartés de vos veilles.
                    </p>

                    <form method="POST" action="{{ route('exclusions.store') }}" class="flex flex-col gap-2 sm:flex-row mb-5">
                        @csrf
                        <input type="hidden" name="kind" value="url" />
                        <select name="subtype" class="border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="domain">Domaine entier (indeed.com)</option>
                            <option value="subdomain">Sous-domaines (*.indeed.com)</option>
                            <option value="exact">URL exacte (https://…)</option>
                            <option value="path">Chemin (/freelance/)</option>
                        </select>
                        <x-text-input type="text" name="pattern" value="{{ old('pattern') }}"
                            placeholder="indeed.com" class="w-full" required />
                        <x-primary-button type="submit">Ajouter</x-primary-button>
                    </form>

                    @if ($urlRules->isEmpty())
                        <p class="text-sm text-gray-500">Aucune règle d'URL pour le moment.</p>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($urlRules as $rule)
                                <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-chip text-indigo-700 mr-2">{{ $rule->typeLabel() }}</span>
                                        <span class="text-sm font-medium break-all">{{ $rule->pattern }}</span>
                                        <span class="ml-2 text-xs {{ $rule->is_active ? 'text-green-700' : 'text-gray-400' }}">
                                            {{ $rule->is_active ? 'Active' : 'Désactivée' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <form method="POST" action="{{ route('exclusions.toggle', $rule) }}">
                                            @csrf @method('PATCH')
                                            <x-secondary-button type="submit">{{ $rule->is_active ? 'Désactiver' : 'Activer' }}</x-secondary-button>
                                        </form>
                                        <form method="POST" action="{{ route('exclusions.update', $rule) }}" class="flex items-center gap-1">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="kind" value="url" />
                                            <input type="hidden" name="subtype" value="{{ $rule->subtype() }}" />
                                            <x-text-input type="text" name="pattern" value="{{ $rule->pattern }}" class="w-40 text-xs" required />
                                            <x-secondary-button type="submit">Modifier</x-secondary-button>
                                        </form>
                                        <form method="POST" action="{{ route('exclusions.destroy', $rule) }}"
                                              onsubmit="return confirm('Supprimer cette règle ?');">
                                            @csrf @method('DELETE')
                                            <x-danger-button type="submit">Supprimer</x-danger-button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Mots-clés et expressions exclus --}}
            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-1">Mots-clés et expressions exclus</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Les articles dont le titre, le résumé ou le contenu contient une de ces
                        expressions seront écartés (casse et accents ignorés).
                    </p>

                    <form method="POST" action="{{ route('exclusions.store') }}" class="flex flex-col gap-2 sm:flex-row mb-5">
                        @csrf
                        <input type="hidden" name="kind" value="keyword" />
                        <x-text-input type="text" name="pattern" value="{{ old('pattern') }}"
                            placeholder="offre d'emploi, contenu sponsorisé…" class="w-full" required />
                        <x-primary-button type="submit">Ajouter</x-primary-button>
                    </form>

                    @if ($keywordRules->isEmpty())
                        <p class="text-sm text-gray-500">Aucune expression pour le moment.</p>
                    @else
                        <ul class="divide-y divide-gray-100">
                            @foreach ($keywordRules as $rule)
                                <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-chip text-indigo-700 mr-2">Expression</span>
                                        <span class="text-sm font-medium">{{ $rule->pattern }}</span>
                                        <span class="ml-2 text-xs {{ $rule->is_active ? 'text-green-700' : 'text-gray-400' }}">
                                            {{ $rule->is_active ? 'Active' : 'Désactivée' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <form method="POST" action="{{ route('exclusions.toggle', $rule) }}">
                                            @csrf @method('PATCH')
                                            <x-secondary-button type="submit">{{ $rule->is_active ? 'Désactiver' : 'Activer' }}</x-secondary-button>
                                        </form>
                                        <form method="POST" action="{{ route('exclusions.update', $rule) }}" class="flex items-center gap-1">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="kind" value="keyword" />
                                            <x-text-input type="text" name="pattern" value="{{ $rule->pattern }}" class="w-40 text-xs" required />
                                            <x-secondary-button type="submit">Modifier</x-secondary-button>
                                        </form>
                                        <form method="POST" action="{{ route('exclusions.destroy', $rule) }}"
                                              onsubmit="return confirm('Supprimer cette règle ?');">
                                            @csrf @method('DELETE')
                                            <x-danger-button type="submit">Supprimer</x-danger-button>
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
