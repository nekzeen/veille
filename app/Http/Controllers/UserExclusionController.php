<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserExclusionRequest;
use App\Jobs\ApplyExclusionJob;
use App\Models\UserExclusion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** « Mes exclusions » — CRUD des règles personnelles (M9.1). */
class UserExclusionController extends Controller
{
    /** Règle de l'utilisateur courant ou 404 (isolation). */
    private function own(Request $request, UserExclusion $exclusion): UserExclusion
    {
        abort_unless($exclusion->user_id === $request->user()->id, 404);

        return $exclusion;
    }

    public function index(Request $request): View
    {
        $exclusions = $request->user()->exclusions()->orderBy('pattern')->get();

        return view('exclusions.index', [
            'urlRules' => $exclusions->where('kind', UserExclusion::KIND_URL),
            'keywordRules' => $exclusions->where('kind', UserExclusion::KIND_KEYWORD),
        ]);
    }

    public function store(StoreUserExclusionRequest $request): RedirectResponse
    {
        $rule = $request->user()->exclusions()->create([
            'kind' => $request->validated('kind'),
            'pattern' => trim((string) $request->validated('pattern')),
        ]);

        ApplyExclusionJob::dispatch($request->user()->id, $rule->id);

        return redirect()->route('exclusions.index')->with('status', 'Règle ajoutée.');
    }

    public function update(StoreUserExclusionRequest $request, UserExclusion $exclusion): RedirectResponse
    {
        $exclusion = $this->own($request, $exclusion);
        $exclusion->update([
            'pattern' => trim((string) $request->validated('pattern')),
        ]);

        ApplyExclusionJob::dispatch($request->user()->id, $exclusion->id);

        return redirect()->route('exclusions.index')->with('status', 'Règle modifiée.');
    }

    public function toggle(Request $request, UserExclusion $exclusion): RedirectResponse
    {
        $exclusion = $this->own($request, $exclusion);
        $exclusion->update(['is_active' => ! $exclusion->is_active]);

        // Réactivation : les articles collectés pendant l'inactivité
        // n'ont aucune justification → recalcul rétroactif. La
        // désactivation, elle, n'exige aucun traitement (jointure
        // `is_active` côté lecture).
        if ($exclusion->is_active) {
            ApplyExclusionJob::dispatch($request->user()->id, $exclusion->id);
        }

        return redirect()->route('exclusions.index')
            ->with('status', $exclusion->is_active ? 'Règle activée.' : 'Règle désactivée.');
    }

    public function destroy(Request $request, UserExclusion $exclusion): RedirectResponse
    {
        $this->own($request, $exclusion)->delete();

        return redirect()->route('exclusions.index')->with('status', 'Règle supprimée.');
    }
}
