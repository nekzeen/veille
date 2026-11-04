<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Mes catégories</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('categories.store') }}" class="flex gap-2">
                        @csrf
                        <input type="text" name="name" maxlength="100" required
                               placeholder="Nouvelle catégorie…" value="{{ old('name') }}"
                               class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Créer
                        </button>
                    </form>
                    @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow sm:rounded-lg border border-slate-300">
                <div class="p-6 text-gray-900">
                    @if ($categories->isEmpty())
                        <p class="text-gray-500">Vous n'avez pas encore de catégorie.</p>
                    @else
                        <ul class="divide-y divide-gray-200">
                            @foreach ($categories as $category)
                                <li class="py-4 space-y-2">
                                    <div class="flex items-center gap-3">
                                        <a href="{{ route('categories.articles', $category) }}"
                                           class="font-medium text-indigo-700 hover:underline">
                                            {{ $category->name }}
                                        </a>
                                        <span class="text-sm text-gray-500">
                                            {{ $category->articles_count }} article{{ $category->articles_count > 1 ? 's' : '' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <form method="POST" action="{{ route('categories.update', $category) }}"
                                              class="flex items-center gap-2">
                                            @csrf
                                            @method('PATCH')
                                            <input type="text" name="name" maxlength="100" required
                                                   value="{{ $category->name }}"
                                                   class="w-56 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                            <button type="submit" class="text-xs text-indigo-700 hover:underline">Renommer</button>
                                        </form>
                                        <form method="POST" action="{{ route('categories.destroy', $category) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs text-red-700 hover:underline">Supprimer</button>
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
