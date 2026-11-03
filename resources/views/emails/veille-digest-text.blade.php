Veille « {{ $alert->veille->name }} » — {{ $items->count() }} article(s)
Période : {{ $alert->period_start?->tz('Europe/Paris')->format('d/m/Y H:i') }} → {{ $alert->period_end?->tz('Europe/Paris')->format('d/m/Y H:i') }} (heure de Paris)

@foreach ($items as $match)
@php($article = $match->article)
- {{ $article->title }}
  {{ $article->source->name ?? 'Source inconnue' }}@if ($article->published_at) — {{ $article->published_at->tz('Europe/Paris')->format('d/m/Y H:i') }}@endif @if ($match->matched_keyword)— mot-clé « {{ $match->matched_keyword }} »@endif

  Lire : {{ route('articles.show', $article) }}
  Original : {{ $article->url }}

@endforeach
