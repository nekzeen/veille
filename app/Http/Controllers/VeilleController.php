<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVeilleRequest;
use App\Http\Requests\UpdateVeilleRequest;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\CatalogueActivityService;
use App\Services\SourceLifecycleService;
use App\Services\VeilleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VeilleController extends Controller
{
    public function __construct(
        private readonly VeilleService $veilles,
        private readonly SourceLifecycleService $lifecycle,
    ) {}

    /** « Mes veilles » : uniquement celles de l'utilisateur connecté. */
    public function index(Request $request): View
    {
        $veilles = $request->user()->veilles()
            ->withCount(['keywords', 'sources'])
            ->orderBy('name')
            ->get();

        return view('veilles.index', compact('veilles'));
    }

    public function create(Request $request): View
    {
        return view('veilles.create', [
            'veille' => new Veille(['is_active' => true]),
            'accessibleSources' => $this->accessibleSources($request->user()),
        ]);
    }

    public function store(StoreVeilleRequest $request): RedirectResponse
    {
        $veille = $this->veilles->create(
            $request->user(),
            $this->veilleAttributes($request),
            $request->terms(),
            $request->sourceIds(),
        );

        return redirect()->route('veilles.show', $veille)
            ->with('status', "Veille « {$veille->name} » créée.");
    }

    public function show(Veille $veille): View
    {
        Gate::authorize('view', $veille);

        $veille->load([
            'keywords' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'sources',
        ]);

        return view('veilles.show', compact('veille'));
    }

    public function edit(Request $request, Veille $veille): View
    {
        Gate::authorize('update', $veille);

        $veille->load([
            'keywords' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            'sources',
        ]);

        return view('veilles.edit', [
            'veille' => $veille,
            'accessibleSources' => $this->accessibleSources($request->user()),
        ]);
    }

    public function update(UpdateVeilleRequest $request, Veille $veille): RedirectResponse
    {
        $this->veilles->update(
            $veille,
            $this->veilleAttributes($request),
            $request->terms(),
            $request->sourceIds(),
        );

        return redirect()->route('veilles.show', $veille)
            ->with('status', "Veille « {$veille->name} » mise à jour.");
    }

    public function destroy(Veille $veille): RedirectResponse
    {
        Gate::authorize('delete', $veille);

        // M13.1a — cascade `source_veille` : capturer les sources avant,
        // recalculer `orphaned_at` après la suppression effective.
        $sourceIds = $this->lifecycle->sourceIdsForVeille($veille->id);
        $this->veilles->delete($veille);
        $this->lifecycle->reevaluate($sourceIds);
        app(CatalogueActivityService::class)->reevaluate($sourceIds);

        return redirect()->route('veilles.index')
            ->with('status', "Veille « {$veille->name} » supprimée.");
    }

    /** Active / met en pause — touche uniquement `is_active` (D11). */
    public function toggle(Veille $veille): RedirectResponse
    {
        Gate::authorize('update', $veille);

        $veille = $this->veilles->toggle($veille);

        return back()->with(
            'status',
            $veille->is_active ? "Veille « {$veille->name} » activée." : "Veille « {$veille->name} » mise en pause.",
        );
    }

    /**
     * Attributs de la veille uniquement : `user_id` n'est jamais pris
     * dans la requête, les cases non cochées valent false.
     *
     * @return array{name: string, description: ?string, is_active: bool, alerts_enabled: bool}
     */
    private function veilleAttributes(StoreVeilleRequest $request): array
    {
        $data = $request->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'alerts_enabled' => $request->boolean('alerts_enabled'),
        ];
    }

    /**
     * Sources sélectionnables : catalogue (is_catalogue) ∪ abonnements
     * personnels (user_sources) — règle d'accessibilité §3.6/§5.
     *
     * @return Collection<int, Source>
     */
    private function accessibleSources(User $user): Collection
    {
        return Source::query()
            ->where('is_catalogue', true)
            ->orWhereIn(
                'id',
                UserSource::where('user_id', $user->id)->select('source_id'),
            )
            ->orderBy('name')
            ->get();
    }
}
