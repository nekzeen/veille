<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Tableau de bord
            </h2>
            <a href="{{ route('veilles.create') }}"
               class="inline-flex items-center px-3 py-1.5 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none transition">
                + Nouvelle veille
            </a>
        </div>
    </x-slot>

    <div class="py-6 bg-gray-50 min-h-screen">
        <div class="max-w-[1280px] mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Indicateurs --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Veilles actives</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['veilles_actives'] }}</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Articles détectés</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $stats['articles_total'] }}</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Non lus</p>
                    <p class="mt-2 text-3xl font-bold {{ $stats['articles_non_lus'] > 0 ? 'text-indigo-600' : 'text-gray-400' }}">
                        {{ $stats['articles_non_lus'] }}
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sources actives</p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $collecte['sources_actives'] }}</p>
                </div>
            </div>

            {{-- État des collectes --}}
            @php
                $enErreur = $collecte['sources_en_erreur'];
                $hasCollecte = $collecte['derniere_collecte'] !== null;
                $badgeClass = $enErreur > 0
                    ? 'bg-red-50 text-red-700 border-red-200'
                    : ($hasCollecte ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-600 border-gray-200');
                $badgeLabel = $enErreur > 0
                    ? 'Erreurs en cours'
                    : ($hasCollecte ? 'Opérationnel' : 'Aucune collecte enregistrée');
            @endphp
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-4 flex flex-wrap items-center gap-x-6 gap-y-2">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $badgeClass }}">
                    <span class="w-2 h-2 rounded-full mr-2 {{ $enErreur > 0 ? 'bg-red-500' : ($hasCollecte ? 'bg-green-500' : 'bg-gray-400') }}"></span>
                    {{ $badgeLabel }}
                </span>
                <p class="text-sm text-gray-600">
                    {{ $collecte['sources_actives'] }} source(s) active(s) ·
                    {{ $enErreur }} en erreur ·
                    Dernière collecte réussie :
                    <span class="font-medium text-gray-800">{{ $collecte['derniere_collecte'] ?? '—' }}</span>
                </p>
            </div>

            {{-- Mes veilles --}}
            <section>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-800">Mes veilles</h3>
                    <a href="{{ route('veilles.index') }}" class="text-sm text-indigo-600 hover:underline">Toutes mes veilles →</a>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    @forelse ($veilles as $veille)
                        <a href="{{ route('veilles.articles.index', $veille) }}"
                           class="block bg-white rounded-xl shadow-sm border border-gray-100 p-5 hover:border-indigo-300 hover:shadow transition group">
                            <div class="flex items-start justify-between gap-3">
                                <h4 class="font-semibold text-gray-900 group-hover:text-indigo-700">{{ $veille->name }}</h4>
                                <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $veille->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $veille->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                Mots-clés :
                                @foreach ($veille->keywords as $kw)
                                    <span class="inline-block bg-indigo-50 text-indigo-700 rounded px-1.5 py-0.5 mr-1">{{ $kw->term }}</span>
                                @endforeach
                            </p>
                            <div class="mt-3 flex items-center gap-4 text-sm">
                                <span class="text-gray-600">{{ $veille->article_veilles_count }} article(s)</span>
                                <span class="{{ $veille->unread_count > 0 ? 'text-indigo-600 font-semibold' : 'text-gray-400' }}">
                                    {{ $veille->unread_count }} non lu(s)
                                </span>
                            </div>
                        </a>
                    @empty
                        <div class="md:col-span-2 bg-white rounded-xl border border-dashed border-gray-300 p-8 text-center">
                            <p class="text-gray-500">
                                Aucune veille pour le moment —
                                <a href="{{ route('veilles.create') }}" class="text-indigo-600 font-medium hover:underline">créez votre première veille</a>
                                pour commencer.
                            </p>
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- Derniers articles détectés --}}
            <section class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800">Derniers articles détectés</h3>
                    <span class="text-xs text-gray-400">10 plus récents</span>
                </div>
                <ul class="divide-y divide-gray-100">
                    @forelse ($recents as $av)
                        <li class="px-5 py-4 hover:bg-gray-50 transition">
                            <div class="flex items-start gap-3">
                                <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $av->is_read ? 'bg-gray-300' : 'bg-indigo-500' }}"
                                      title="{{ $av->is_read ? 'Lu' : 'Non lu' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('veilles.articles.show', [$av->veille_id, $av->article_id]) }}"
                                       class="block text-base {{ $av->is_read ? 'text-gray-500' : 'text-gray-900 font-medium' }} hover:text-indigo-700 leading-snug">
                                        {{ $av->article->title }}
                                    </a>
                                    <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                                        <span class="font-medium text-gray-600">{{ $av->article->source->name ?? 'Source inconnue' }}</span>
                                        <span>{{ $av->article->published_at ?? $av->article->created_at }}</span>
                                        <span class="inline-flex items-center bg-gray-100 rounded px-1.5 py-0.5">veille « {{ $av->veille->name }} »</span>
                                        <span class="inline-flex items-center bg-indigo-50 text-indigo-700 rounded px-1.5 py-0.5">{{ $av->matched_keyword }}</span>
                                    </p>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-sm text-gray-500">
                            Aucun article détecté pour l'instant — les résultats apparaîtront après les prochaines collectes.
                        </li>
                    @endforelse
                </ul>
            </section>

        </div>
    </div>
</x-app-layout>
