@php
    /** @var \App\Models\Veille $veille */
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Source> $accessibleSources */
    $selectedSources = collect(old('sources', $veille->exists ? $veille->sources->pluck('id')->all() : []))
        ->map(fn ($id) => (int) $id)->all();
    $keywordsText = old('keywords', $veille->exists ? $veille->keywords->pluck('term')->implode("\n") : '');
@endphp

<div>
    <x-input-label for="name" value="Nom" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
        :value="old('name', $veille->name)" required maxlength="255" autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="description" value="Description (facultative)" />
    <textarea id="description" name="description" rows="3" maxlength="500"
        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
    >{{ old('description', $veille->description) }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>

<div class="flex flex-col gap-2 sm:flex-row sm:gap-6">
    <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="is_active" value="1"
            @checked(old('is_active', $veille->exists ? $veille->is_active : true))
            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
        <span class="text-sm text-gray-700">Veille active</span>
    </label>
    <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="alerts_enabled" value="1"
            @checked(old('alerts_enabled', $veille->alerts_enabled))
            class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
        <span class="text-sm text-gray-700">Alertes e-mail quotidiennes</span>
    </label>
</div>

<div>
    <x-input-label for="keywords" value="Mots-clés (un par ligne)" />
    <textarea id="keywords" name="keywords" rows="5"
        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
        placeholder="laravel&#10;formation continue&#10;numérique"
    >{{ $keywordsText }}</textarea>
    <p class="mt-1 text-xs text-gray-500">Insensible à la casse et aux accents ; les doublons sont ignorés.</p>
    <x-input-error :messages="$errors->get('keywords')" class="mt-2" />
</div>

<div>
    <x-input-label value="Sources de la veille" />
    <x-input-error :messages="$errors->get('sources')" class="mt-2" />
    @if ($accessibleSources->isEmpty())
        <p class="mt-2 text-sm text-gray-500">
            Aucune source accessible — ajoutez une source personnelle ou abonnez-vous au catalogue.
        </p>
    @else
        <ul class="mt-2 space-y-2">
            @foreach ($accessibleSources as $source)
                <li>
                    <label class="inline-flex items-start gap-2">
                        <input type="checkbox" name="sources[]" value="{{ $source->id }}"
                            @checked(in_array($source->id, $selectedSources))
                            class="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                        <span>
                            <span class="text-sm font-medium text-gray-700">{{ $source->name }}</span>
                            <span class="text-xs text-gray-400">
                                — {{ $source->is_catalogue ? 'Catalogue' : 'Personnelle' }}
                            </span>
                            <span class="block text-xs text-gray-500 break-all">{{ $source->feed_url }}</span>
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    @endif
</div>
