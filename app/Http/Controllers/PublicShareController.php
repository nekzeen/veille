<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VeilleShare;
use App\Services\Articles\HtmlContentSanitizer;
use App\Services\Shares\PublicShareQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consultation publique en lecture seule (M10.4). Séparé des
 * contrôleurs privés : périmètre = share injecté par
 * ResolveShareToken ; aucun état privé, aucune donnée `article_user`,
 * `matched_keyword` ou mot-clé exposé.
 */
class PublicShareController extends Controller
{
    public function index(Request $request, PublicShareQuery $query): View
    {
        /** @var VeilleShare $share */
        $share = $request->attributes->get('share');
        $veilles = $query->veilles($share);

        if (! $share->isGlobal()) {
            return $this->veilleView($request, $share, $share->veille, $query, $veilles);
        }

        return view('shared.index', [
            'share' => $share,
            'veilles' => $veilles,
            'token' => $request->route('token'),
            'owner' => $share->user?->name,
        ]);
    }

    public function veille(Request $request, string $token, string $veille, PublicShareQuery $query): View
    {
        $share = $request->attributes->get('share');
        $v = $query->findVeille($share, (int) $veille);
        abort_if($v === null, 404);

        return $this->veilleView($request, $share, $v, $query, $query->veilles($share));
    }

    public function article(Request $request, string $token, string $article, PublicShareQuery $query, HtmlContentSanitizer $sanitizer): View
    {
        $share = $request->attributes->get('share');
        $a = $query->findArticle($share, (int) $article);
        abort_if($a === null, 404);

        return view('shared.article', [
            'share' => $share,
            'article' => $a,
            'content' => $sanitizer->sanitize($a->content ?? $a->summary ?? ''),
            'veilles' => $query->veilles($share),
            'token' => $request->route('token'),
        ]);
    }

    private function veilleView(Request $request, VeilleShare $share, $veille, PublicShareQuery $query, $veilles): View
    {
        abort_if($veille === null, 404);

        return view('shared.veille', [
            'share' => $share,
            'veille' => $veille,
            'veilles' => $veilles,
            'token' => $request->route('token'),
            'articles' => $query->articles($veille)->appends([]),
        ]);
    }
}
