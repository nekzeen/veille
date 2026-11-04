<x-shared-layout :title="$share->label ?: 'Toutes mes veilles'" :owner="$owner">
    @if ($veilles->isEmpty())
        <div class="bg-white rounded-lg border border-slate-300 shadow p-6 text-sm text-slate-500">
            Aucune veille active pour le moment.
        </div>
    @else
        <ul class="grid gap-4 sm:grid-cols-2">
            @foreach ($veilles as $v)
                <li class="bg-white rounded-lg border border-slate-300 shadow p-5 flex flex-col">
                    <h2 class="font-semibold text-slate-800">
                        <a href="{{ route('shared.veille', ['token' => $token, 'veille' => $v->id]) }}"
                           class="hover:text-violet-700">{{ $v->name }}</a>
                    </h2>
                    <p class="mt-1 text-sm text-slate-600 line-clamp-2">
                        {{ $v->description ?: 'Aucune description renseignée' }}
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</x-shared-layout>
