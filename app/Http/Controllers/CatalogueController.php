<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceTheme;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogueController extends Controller
{
    /** Catalogue des sources proposées par la plateforme (recherche incluse). */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        // Filtre thématique — id validé sur le référentiel (M13.2a).
        $themeId = (int) $request->query('theme', 0);

        $sources = Source::query()
            ->where('is_catalogue', true)
            ->where('catalogue_state', 'active')
            ->where('is_active', true)
            ->with('theme:id,name')
            ->when($themeId > 0, fn ($q) => $q->where('theme_id', $themeId))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('feed_url', 'like', "%{$search}%")
                        ->orWhere('site_url', 'like', "%{$search}%");
                });
            })
            ->withExists(['userSources as subscribed' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $themes = SourceTheme::orderBy('id')->get();

        return view('sources.catalogue', compact('sources', 'search', 'themes', 'themeId'));
    }
}
