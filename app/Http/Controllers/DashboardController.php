<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\Veille;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Dashboard §M7.14 : indicateurs, veilles et derniers articles de
 * l'utilisateur — isolation stricte par user_id partout.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $uid = (int) $user->id;

        $veilles = $user->veilles()
            ->with('keywords')
            ->withCount('articleVeilles')
            ->orderBy('name')
            ->get();

        // Articles non lus par veille : pas de ligne article_user ou is_read=0.
        $unreadPerVeille = ArticleVeille::query()
            ->leftJoin('article_user', function ($j) use ($uid): void {
                $j->on('article_user.article_id', '=', 'article_veille.article_id')
                    ->where('article_user.user_id', '=', $uid);
            })
            ->where('article_veille.user_id', $uid)
            ->where(fn ($q) => $q->whereNull('article_user.is_read')->orWhere('article_user.is_read', false))
            ->groupBy('article_veille.veille_id')
            ->pluck(DB::raw('COUNT(*)'), 'article_veille.veille_id');

        $veilles->each(fn (Veille $v) => $v->setAttribute('unread_count', (int) ($unreadPerVeille[$v->id] ?? 0)));

        $stats = [
            'veilles_actives' => $veilles->where('is_active', true)->count(),
            'articles_total' => $veilles->sum('article_veilles_count'),
            'articles_non_lus' => $unreadPerVeille->values()->sum(),
        ];

        // Derniers articles détectés, toutes veilles confondues.
        $recents = ArticleVeille::query()
            ->where('article_veille.user_id', $uid)
            ->with(['article.source:id,name,site_url', 'veille:id,name'])
            ->orderByDesc('article_veille.id')
            ->limit(10)
            ->get();

        $readMap = ArticleUser::where('user_id', $uid)
            ->whereIn('article_id', $recents->pluck('article_id'))
            ->pluck('is_read', 'article_id');
        $recents->each(fn (ArticleVeille $av) => $av->setAttribute('is_read', (bool) ($readMap[$av->article_id] ?? false)));

        // État des collectes sur les sources utilisées par les veilles.
        $sourceIds = DB::table('source_veille')
            ->whereIn('veille_id', $veilles->pluck('id'))
            ->distinct()
            ->pluck('source_id');
        $sources = Source::whereIn('id', $sourceIds)->get();
        $collecte = [
            'sources_actives' => $sources->where('is_active', true)->count(),
            'sources_en_erreur' => $sources->where('fail_count', '>', 0)->count(),
            'derniere_collecte' => $sources->max('last_success_at'),
        ];

        return view('dashboard', compact('veilles', 'stats', 'recents', 'collecte'));
    }
}
