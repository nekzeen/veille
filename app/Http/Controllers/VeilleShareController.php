<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreVeilleShareRequest;
use App\Models\VeilleShare;
use App\Services\Shares\ShareTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Gestion propriétaire des partages (M10.3). Même discipline que M9.1 :
 * scoping `own()` → 404 sur tout share d'autrui ; jeton clair flashé
 * une seule fois (création/rotation), jamais persisté ni relisté.
 */
class VeilleShareController extends Controller
{
    private function own(Request $request, VeilleShare $share): VeilleShare
    {
        abort_if($share->user_id !== $request->user()->id, 404);

        return $share;
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('shares.index', [
            'shares' => VeilleShare::where('user_id', $user->id)
                ->with('veille:id,name,is_active')
                ->orderByDesc('id')->get(),
            'veilles' => $user->veilles()->orderBy('name')->get(['id', 'name']),
            'preselect' => (int) $request->query('veille', 0),
        ]);
    }

    public function store(StoreVeilleShareRequest $request, ShareTokenService $tokens): RedirectResponse
    {
        $plain = $tokens->generatePlainToken();
        VeilleShare::create([
            'user_id' => $request->user()->id,
            'veille_id' => $request->veilleId(),
            'scope' => $request->validated('scope'),
            'label' => trim((string) $request->validated('label')) ?: null,
            'expires_at' => $request->validated('expires_at'),
            'token_hash' => $tokens->hash($plain),
        ]);

        return $this->showNewLink($plain, 'Partage créé.');
    }

    public function update(Request $request, VeilleShare $share): RedirectResponse
    {
        $share = $this->own($request, $share);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $share->update([
            'label' => array_key_exists('label', $data)
                ? (trim((string) $data['label']) ?: null) : $share->label,
            'expires_at' => array_key_exists('expires_at', $data)
                ? $data['expires_at'] : $share->expires_at,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active'] : $share->is_active,
        ]);

        return redirect()->route('shares.index')->with('status', 'Partage mis à jour.');
    }

    public function rotate(Request $request, VeilleShare $share, ShareTokenService $tokens): RedirectResponse
    {
        return $this->showNewLink(
            $tokens->rotate($this->own($request, $share)),
            'Nouveau lien généré — l\u2019ancien est immédiatement invalide.',
        );
    }

    public function destroy(Request $request, VeilleShare $share, ShareTokenService $tokens): RedirectResponse
    {
        $tokens->revoke($this->own($request, $share));

        return redirect()->route('shares.index')->with('status', 'Partage révoqué.');
    }

    /** Lien complet flashé une seule fois — jamais stocké ni listé. */
    private function showNewLink(string $plainToken, string $status): RedirectResponse
    {
        return redirect()->route('shares.index')
            ->with('status', $status)
            ->with('share_link', URL::to('/shared/'.$plainToken));
    }
}
