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

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-medium mb-4">Ajouter une source personnelle</h3>

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

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
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
</x-app-layout>
