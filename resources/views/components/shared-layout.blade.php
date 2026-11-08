<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Veille partagée' }} — Veille</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicon.svg') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-[#E2E6EC] text-slate-800 antialiased">
    <header class="bg-[#CFE3F8] border-b border-slate-300">
        <div class="max-w-4xl mx-auto px-4 py-4">
            <p class="text-sm text-slate-600 flex items-center gap-2">
                <img src="{{ asset('images/logo-icon.svg') }}" alt="Veille" class="h-6 w-6">
                Veille partagée en lecture seule{{ isset($owner) && $owner ? ' par '.$owner : '' }}</p>
            <h1 class="text-xl font-semibold text-slate-900">{{ $title ?? 'Veille partagée' }}</h1>
        </div>
    </header>
    <main class="max-w-4xl mx-auto px-4 py-6">
        {{ $slot }}
    </main>
    <footer class="max-w-4xl mx-auto px-4 pb-8 text-xs text-slate-500">
        Consultation publique — aucune action n'est possible.
    </footer>
</body>
</html>
