<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Mes sources
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-md">{{ session('status') }}</div>
            @endif
            @if ($showErrors ?? false)
                <div class="p-4 bg-red-50 text-red-800 rounded-md flex justify-between items-center">
                    <span>Sources en erreur uniquement ({{ $subscriptions->count() }})</span>
                    <a href="{{ route('sources.index') }}" class="underline text-sm">Voir toutes mes sources</a>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300"
                 x-data="sourceAdder">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-1">Ajouter une source</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Collez l'adresse d'un site, d'un flux ou d'une page de résultats :
                        Veille détecte automatiquement comment la collecter.
                    </p>

                    <div class="flex flex-col gap-2 sm:flex-row">
                        <x-text-input type="url" x-model="url" x-bind:disabled="busy"
                            @keydown.enter.prevent="analyze()"
                            placeholder="https://exemple.fr ou https://exemple.fr/feed.xml"
                            class="w-full" required />
                        <x-secondary-button type="button" x-on:click="analyze()"
                            x-bind:disabled="busy || ! url.trim()">
                            <span x-show="! loading">Analyser</span>
                            <span x-show="loading" x-cloak>Analyse…</span>
                        </x-secondary-button>
                    </div>

                    {{-- Résultat de la détection + aperçu --}}
                    <div x-show="result" x-cloak class="mt-5">
                        <template x-if="result">
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                        x-bind:class="badgeClass()" x-text="typeLabel()"></span>
                                    <span class="text-sm text-gray-600" x-text="statusMessage()"></span>
                                </div>

                                <p x-show="result.fetch_url && result.fetch_url !== result.submitted_url"
                                    class="text-sm text-gray-600 mb-2 break-all">
                                    Adresse collectée :
                                    <span class="font-medium" x-text="result.fetch_url"></span>
                                </p>

                                <div x-show="result.preview && result.preview.items && result.preview.items.length"
                                    class="border border-gray-200 rounded-md divide-y divide-gray-100 mb-3">
                                    <template x-for="item in (result.preview ? result.preview.items : [])" :key="item.url">
                                        <div class="p-3">
                                            <a x-show="item.url" x-bind:href="item.url" target="_blank" rel="noopener"
                                                class="font-medium text-indigo-700 hover:underline"
                                                x-text="item.title || item.url"></a>
                                            <p x-show="item.summary" class="text-sm text-gray-600 mt-1"
                                                x-text="item.summary"></p>
                                            <p class="text-xs text-gray-400 mt-1">
                                                <span x-show="item.published_at" x-text="formatDate(item.published_at)"></span>
                                                <template x-if="item.missing && item.missing.length">
                                                    <span x-text="'— absent : ' + item.missing.join(', ')"></span>
                                                </template>
                                            </p>
                                        </div>
                                    </template>
                                </div>
                                <p x-show="result.preview && result.preview.status === 'ok' && result.preview.items.length === 0"
                                    class="text-sm text-gray-600 mb-3">
                                    Le format est reconnu mais aucun article n'a été extrait pour l'instant.
                                </p>
                                <p x-show="result.preview && result.preview.message"
                                    class="text-sm text-gray-600 mb-3" x-text="result.preview.message"></p>

                                {{-- Réglages avancés : source HTML uniquement --}}
                                <details x-show="result.connector === 'html' && savable()"
                                    class="mb-3 border border-amber-200 bg-amber-50 rounded-md p-3">
                                    <summary class="text-sm font-medium text-amber-900 cursor-pointer">
                                        Réglages d'extraction (avancé)
                                    </summary>
                                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                                        <template x-for="key in ['item','link','title','summary','date']" :key="key">
                                            <label class="block">
                                                <span class="text-gray-600" x-text="'Sélecteur ' + key"></span>
                                                <input type="text" x-model="selectors[key]" x-bind:disabled="busy"
                                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                            </label>
                                        </template>
                                        <label class="block sm:col-span-2">
                                            <span class="text-gray-600">Pages d'article lues pour compléter les infos (0 à 3)</span>
                                            <input type="number" min="0" max="3" x-model="enrichPages" x-bind:disabled="busy"
                                                class="mt-1 block w-24 border-gray-300 rounded-md shadow-sm text-sm" />
                                        </label>
                                    </div>
                                    <x-secondary-button type="button" class="mt-3"
                                        x-on:click="analyze()" x-bind:disabled="busy">
                                        Actualiser l'aperçu
                                    </x-secondary-button>
                                </details>

                                <div class="flex flex-wrap items-center gap-3">
                                    <x-primary-button type="button" x-show="savable()"
                                        x-on:click="confirm()" x-bind:disabled="busy">
                                        <span x-show="! saving">Ajouter cette source</span>
                                        <span x-show="saving" x-cloak>Ajout…</span>
                                    </x-primary-button>
                                    <button type="button" x-on:click="reset()"
                                        class="text-sm text-gray-600 hover:underline">
                                        Modifier l'adresse
                                    </button>
                                    <span x-show="error" class="text-sm text-red-600" x-text="error"></span>
                                    <span x-show="saved" class="text-sm text-green-700" x-text="saved"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">Ajout direct (flux RSS/Atom connu)</h3>

                    <form method="POST" action="{{ route('sources.personal') }}" class="flex flex-col gap-2 sm:flex-row">
                        @csrf
                        <div class="w-full">
                            <x-text-input type="url" name="feed_url" value="{{ old('feed_url') }}"
                                placeholder="https://exemple.fr/feed.xml" class="w-full" required />
                            <x-input-error :messages="$errors->get('feed_url')" class="mt-2" />
                        </div>
                        <x-primary-button type="submit">Ajouter</x-primary-button>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">Abonnements</h3>

                    @if ($subscriptions->isEmpty())
                        <p class="text-gray-500">Vous n'êtes abonné à aucune source.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($subscriptions as $subscription)
                                <li class="py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900">
                                            {{ $subscription->custom_name ?: $subscription->source->name }}
                                        </p>
                                        <p class="text-sm text-gray-500 break-all">{{ $subscription->source->feed_url }}</p>
                                        <p class="text-xs text-gray-400">
                                            {{ $subscription->source->is_catalogue ? 'Catalogue' : 'Personnelle' }}
                                            — abonné le {{ $subscription->created_at?->format('d/m/Y') }}
                                        </p>
                                        @if ($subscription->source->fail_count > 0)
                                            <div class="mt-2 text-xs border-l-2 border-red-200 bg-red-50 rounded-r px-3 py-2 space-y-0.5">
                                                <p class="font-medium text-red-700">
                                                    {{ ! $subscription->source->is_active ? 'Source désactivée (échecs répétés)' : ($subscription->source->fail_count > 1 ? 'Erreur récurrente' : 'Erreur ponctuelle') }}
                                                </p>
                                                <p class="text-red-600">{{ $subscription->source->last_error ?? 'Motif non enregistré' }}</p>
                                                <p class="text-gray-500">
                                                    Échecs consécutifs : {{ $subscription->source->fail_count }} ·
                                                    Dernière tentative : {{ $subscription->source->last_fetched_at?->format('d/m/Y H:i') ?? '—' }} ·
                                                    Dernier succès : {{ $subscription->source->last_success_at?->format('d/m/Y H:i') ?? '—' }}
                                                </p>
                                                <form method="POST" action="{{ route('sources.retry', $subscription) }}"
                                                      class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            onclick="this.disabled=true;this.form.submit()"
                                                            class="mt-1 inline-flex items-center px-2.5 py-1 text-xs font-medium rounded border border-slate-300 text-slate-700 hover:bg-slate-50">
                                                        Relancer la collecte
                                                    </button>
                                                </form>
                                                @if (! $subscription->source->is_active)
                                                    <form method="POST" action="{{ route('sources.reactivate', $subscription) }}"
                                                          class="inline">
                                                        @csrf
                                                        <button type="submit"
                                                                class="mt-1 inline-flex items-center px-2.5 py-1 text-xs font-medium rounded border border-red-300 text-red-700 hover:bg-red-100">
                                                            Réactiver
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    <div class="shrink-0">
                                        <form method="POST" action="{{ route('sources.unsubscribe', $subscription) }}"
                                              onsubmit="return confirm('Se désabonner de cette source ?');">
                                            @csrf
                                            @method('DELETE')
                                            <x-danger-button type="submit">Se désabonner</x-danger-button>
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

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('sourceAdder', () => ({
                url: '',
                loading: false,
                saving: false,
                result: null,
                error: '',
                saved: '',
                selectors: { item: '', link: '', title: '', summary: '', date: '' },
                enrichPages: 0,

                get busy() { return this.loading || this.saving; },

                payload() {
                    const s = {};
                    for (const k of ['item', 'link', 'title', 'summary', 'date']) {
                        if (this.selectors[k].trim() !== '') { s[k] = this.selectors[k].trim(); }
                    }
                    return {
                        url: this.url.trim(),
                        strategy: s.item ? 'selectors' : 'auto',
                        enrich_pages: parseInt(this.enrichPages || 0, 10),
                        selectors: s,
                    };
                },

                async post(route, payload) {
                    const response = await fetch(route, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (! response.ok) {
                        const first = data.errors ? Object.values(data.errors).flat()[0] : null;
                        throw new Error(first || data.message || 'Erreur inattendue (' + response.status + ').');
                    }
                    return data;
                },

                async analyze() {
                    this.loading = true; this.error = ''; this.saved = ''; this.result = null;
                    try {
                        this.result = await this.post('{{ url('/sources/preview') }}', this.payload());
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.loading = false;
                    }
                },

                savable() {
                    return this.result && this.result.connector_available
                        && (this.result.status === 'detected'
                            || (this.result.status === 'needs_review'
                                && this.result.preview && this.result.preview.items.length > 0));
                },

                async confirm() {
                    this.saving = true; this.error = ''; this.saved = '';
                    try {
                        const data = await this.post('{{ url('/sources/confirm') }}', this.payload());
                        this.saved = 'Source « ' + data.source.name + ' » ajoutée.';
                        setTimeout(() => window.location.reload(), 800);
                    } catch (e) {
                        this.error = e.message;
                    } finally {
                        this.saving = false;
                    }
                },

                reset() { this.result = null; this.error = ''; this.saved = ''; },

                typeLabel() {
                    const map = { rss: 'Flux RSS', atom: 'Flux Atom', json_feed: 'Flux JSON', html: 'Page HTML' };
                    return map[this.result.connector] || 'Format non reconnu';
                },

                badgeClass() {
                    if (this.result.status === 'detected') { return 'bg-green-100 text-green-800'; }
                    if (this.result.status === 'needs_review') { return 'bg-amber-100 text-amber-800'; }
                    return 'bg-red-100 text-red-800';
                },

                statusMessage() {
                    if (this.result.status === 'detected') {
                        return 'Format détecté — vérifiez l\'aperçu avant d\'ajouter.';
                    }
                    if (this.result.status === 'needs_review') {
                        return 'Page HTML : vérification recommandée — aucune validation automatique.';
                    }
                    return this.result.message || 'Cette adresse ne peut pas être collectée.';
                },

                formatDate(iso) {
                    const d = new Date(iso);
                    return isNaN(d) ? '' : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
                },
            }));
        });
    </script>
</x-app-layout>
