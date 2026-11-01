<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogueController extends Controller
{
    /** Catalogue des sources proposées par la plateforme (recherche incluse). */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $sources = Source::query()
            ->where('is_catalogue', true)
            ->where('is_active', true)
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

        return view('sources.catalogue', compact('sources', 'search'));
    }
}
