<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">Mes partages</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('share_link'))
                {{-- Jeton complet : affiché UNE fois, jamais stocké ni relisté. --}}
                <div class="rounded-lg border border-violet-300 bg-violet-50 px-4 py-3">
                    <p class="text-sm font-medium text-violet-900 mb-2">
                        Copiez ce lien maintenant — il ne sera plus affiché :
                    </p>
                    <div class="flex items-center gap-2">
                        <code id="new-share-link" class="flex-1 break-all rounded bg-white px-3 py-2 text-xs text-violet-800 border border-violet-200">{{ session('share_link') }}</code>
                        <button type="button" class="shrink-0 rounded-md bg-violet-600 px-3 py-2 text-xs font-medium text-white hover:bg-violet-700"
                                onclick="navigator.clipboard.writeText(document.getElementById('new-share-link').textContent.trim())">Copier</button>
                    </div>
                </div>
            @endif

            {{-- Création --}}
            <div class="bg-white shadow border border-slate-300 rounded-lg p-6" x-data="{ scope: '{{ old('scope', $preselect ? 'single' : 'all') }}' }">
                <h3 class="text-sm font-semibold text-slate-800 mb-4">Nouveau partage</h3>
                <form method="POST" action="{{ route('shares.store') }}" class="space-y-4">
                    @csrf
                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="radio" name="scope" value="all" x-model="scope" @checked(old('scope') === 'all')
                                   class="text-violet-600 focus:ring-violet-500"> Toutes mes veilles (actuelles et futures)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="radio" name="scope" value="single" x-model="scope" @checked(old('scope') === 'single' || $preselect)
                                   class="text-violet-600 focus:ring-violet-500"> Une veille précise
                        </label>
                    </div>

                    <div x-show="scope === 'single'" class="flex flex-col sm:flex-row gap-3">
                        <select name="veille_id" x-bind:disabled="scope !== 'single'"
                                class="rounded-md border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                            <option value="">Choisir une veille…</option>
                            @foreach ($veilles as $v)
                                <option value="{{ $v->id }}" @selected((string) old('veille_id', $preselect) === (string) $v->id)>{{ $v->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3">
                        <input type="text" name="label" value="{{ old('label') }}" maxlength="120"
                               placeholder="Libellé (facultatif)"
                               class="flex-1 rounded-md border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                        <input type="date" name="expires_at" value="{{ old('expires_at') }}" min="{{ now()->addDay()->toDateString() }}"
                               title="Expiration (facultative)"
                               class="rounded-md border-slate-300 text-sm focus:border-violet-500 focus:ring-violet-500">
                        <button type="submit" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">
                            Créer le lien
                        </button>
                    </div>
                    @error('scope')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('veille_id')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('expires_at')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="text-xs text-slate-500">Le destinataire voit les articles en lecture seule. Présets possibles : saisir une date à +7, +30 ou +90 jours, ou laisser vide pour aucune expiration.</p>
                </form>
            </div>

            {{-- Liste --}}
            <div class="bg-white shadow border border-slate-300 rounded-lg overflow-hidden">
                <ul class="divide-y divide-slate-200">
                    @forelse ($shares as $share)
                        @php
                            $expired = $share->expires_at !== null && $share->expires_at->isPast();
                            $state = $share->revoked_at ? ['Révoqué', 'red'] : ($expired ? ['Expiré', 'amber'] : ($share->is_active ? ['Actif', 'emerald'] : ['Suspendu', 'slate']));
                        @endphp
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-sm font-medium text-slate-800">
                                    {{ $share->label ?: ($share->isGlobal() ? 'Partage global' : $share->veille?->name) }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ $share->isGlobal() ? 'Toutes les veilles' : 'Veille : '.($share->veille?->name ?? 'supprimée') }}
                                </span>
                                <span class="inline-flex items-center rounded-full bg-{{ $state[1] }}-100 px-2 py-0.5 text-xs font-medium text-{{ $state[1] }}-800">{{ $state[0] }}</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                Créé le {{ $share->created_at->format('d/m/Y') }}
                                · {{ $share->expires_at ? 'Expire le '.$share->expires_at->format('d/m/Y') : 'Sans expiration' }}
                                @if ($share->rotated_at) · Dernière rotation le {{ $share->rotated_at->format('d/m/Y H:i') }} @endif
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @unless ($share->revoked_at)
                                    <form method="POST" action="{{ route('shares.rotate', $share) }}">
                                        @csrf
                                        <button class="rounded-md border border-violet-300 bg-white px-3 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50">Régénérer le lien</button>
                                    </form>
                                    <form method="POST" action="{{ route('shares.destroy', $share) }}" onsubmit="return confirm('Révoquer ce partage ? Le lien cessera de fonctionner.')">
                                        @csrf @method('DELETE')
                                        <button class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50">Révoquer</button>
                                    </form>
                                @endunless
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-8 text-center text-sm text-slate-500">Aucun partage pour le moment.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
