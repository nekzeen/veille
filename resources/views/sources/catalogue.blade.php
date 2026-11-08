<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Catalogue des sources
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('status'))
                <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-md">{{ session('status') }}</div>
            @endif

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">

                    <form method="GET" action="{{ route('sources.catalogue') }}" class="mb-6 flex flex-col gap-2 sm:flex-row">
                        <x-text-input type="text" name="q" value="{{ $search }}" placeholder="Rechercher une source…" class="w-full" />
                        <select name="theme" class="rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="">Toutes les thématiques</option>
                            @foreach ($themes as $theme)
                                <option value="{{ $theme->id }}" @selected($themeId === $theme->id)>{{ $theme->name }}</option>
                            @endforeach
                        </select>
                        <x-primary-button type="submit">Rechercher</x-primary-button>
                    </form>

                    @if ($sources->isEmpty())
                        <p class="text-gray-500">Aucune source trouvée.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($sources as $source)
                                <li class="py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900">{{ $source->name }}
                                            @if ($source->theme)
                                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">{{ $source->theme->name }}</span>
                                            @endif
                                        </p>
                                        <p class="text-sm text-gray-500 break-all">{{ $source->feed_url }}</p>
                                        @if ($source->site_url)
                                            <p class="text-sm text-indigo-700 break-all"><a href="{{ $source->site_url }}" target="_blank" rel="noopener" class="hover:underline">{{ $source->site_url }}</a></p>
                                        @endif
                                        @if ($source->description)
                                            <p class="text-sm text-gray-600 mt-1">{{ $source->description }}</p>
                                        @endif
                                    </div>
                                    <div class="shrink-0">
                                        @if ($source->subscribed)
                                            <span class="inline-flex items-center px-3 py-1 text-sm text-green-700 bg-green-100 rounded-md">Abonné</span>
                                        @else
                                            <form method="POST" action="{{ route('sources.subscribe', $source) }}">
                                                @csrf
                                                <x-primary-button type="submit">S'abonner</x-primary-button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-6">{{ $sources->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
