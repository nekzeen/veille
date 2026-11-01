<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePersonalSourceRequest;
use App\Models\Source;
use App\Models\UserSource;
use App\Services\SourceSubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserSourceController extends Controller
{
    public function __construct(private SourceSubscriptionService $service) {}

    /** « Mes sources » : abonnements personnels de l'utilisateur. */
    public function index(Request $request): View
    {
        $subscriptions = $request->user()
            ->userSources()
            ->with('source')
            ->orderByDesc('created_at')
            ->get();

        return view('sources.index', compact('subscriptions'));
    }

    /** Abonnement à une source existante (catalogue ou déjà enregistrée). */
    public function store(Request $request, Source $source): RedirectResponse
    {
        try {
            $this->service->subscribe($request->user(), $source);
        } catch (\RuntimeException) {
            abort(403);
        }

        return back()->with('status', 'Abonnement ajouté.');
    }

    /** Ajout d'une source personnelle par URL de flux. */
    public function storePersonal(StorePersonalSourceRequest $request): RedirectResponse
    {
        [$source] = $this->service->addPersonalSource(
            $request->user(),
            (string) $request->validated('feed_url'),
        );

        return redirect()->route('sources.index')
            ->with('status', "Source « {$source->name} » ajoutée.");
    }

    /** Désabonnement : supprime seulement l'abonnement de l'utilisateur. */
    public function destroy(Request $request, UserSource $userSource): RedirectResponse
    {
        Gate::authorize('delete', $userSource);

        $this->service->unsubscribe($request->user(), $userSource);

        return back()->with('status', 'Désabonnement effectué.');
    }
}
