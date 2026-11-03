<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Digest — {{ $alert->veille->name }}</title>
</head>
<body style="font-family: sans-serif; color: #1f2937; line-height: 1.5;">
    <h1 style="font-size: 18px;">Veille « {{ $alert->veille->name }} »</h1>
    <p style="color: #6b7280;">
        {{ $items->count() }} article(s) — période du
        {{ $alert->period_start?->tz('Europe/Paris')->format('d/m/Y à H:i') }}
        au {{ $alert->period_end?->tz('Europe/Paris')->format('d/m/Y à H:i') }}
        (heure de Paris).
    </p>
    <ul style="padding-left: 1.2em;">
        @foreach ($items as $match)
            @php($article = $match->article)
            <li style="margin-bottom: 0.8em;">
                <a href="{{ route('articles.show', $article) }}"
                   style="color: #1d4ed8; font-weight: 600;">{{ $article->title }}</a>
                <br>
                <span style="color: #6b7280; font-size: 13px;">
                    {{ $article->source->name ?? 'Source inconnue' }}
                    @if ($article->published_at)
                        — {{ $article->published_at->tz('Europe/Paris')->format('d/m/Y à H:i') }}
                    @endif
                    @if ($match->matched_keyword)
                        — mot-clé « {{ $match->matched_keyword }} »
                    @endif
                </span>
                <br>
                <a href="{{ $article->url }}" style="color: #6b7280; font-size: 12px;">Article original</a>
            </li>
        @endforeach
    </ul>
</body>
</html>
