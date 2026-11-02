<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Modifier « {{ $veille->name }} »
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('veilles.update', $veille) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        @include('veilles.partials.fields')

                        <div class="flex items-center gap-4">
                            <x-primary-button type="submit">Enregistrer</x-primary-button>
                            <a href="{{ route('veilles.show', $veille) }}" class="text-sm text-gray-600 hover:underline">Annuler</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
