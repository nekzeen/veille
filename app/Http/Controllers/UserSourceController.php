<?php

namespace App\Http\Controllers;

use App\Http\Requests\DetectedSourceRequest;
use App\Http\Requests\StorePersonalSourceRequest;
use App\Jobs\FetchFeedJob;
use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\UserSource;
use App\Services\Feed\Detection\SourceNotCollectableException;
use App\Services\SourceSubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class UserSourceController extends Controller
{
    public function __construct(private SourceSubscriptionService $service) {}

    /** « Mes sources » : abonnements personnels de l'utilisateur. */
    public function index(Request $request): View
    {
        $showErrors = $request->query('error') === '1';
        $subscriptions = $request->user()
            ->userSources()
            ->with('source')
            ->when($showErrors, fn ($q) => $q->whereHas('source',
                fn ($s) => $s->where('fail_count', '>', 0)))
            ->orderByDesc('created_at')
            ->get();

        $themes = SourceTheme::orderBy('id')->get();

        return view('sources.index', compact('subscriptions', 'showErrors', 'themes'));
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

    /**
     * Ajout d'une source personnelle après détection (M8.5) : le
     * serveur redétecte l'URL — seule la configuration HTML filtrée du
     * navigateur est transmise. Réponse JSON pour le parcours Alpine,
     * redirection sinon.
     */
    public function storeDetected(DetectedSourceRequest $request): JsonResponse|RedirectResponse
    {
        // M13.2b — thématique obligatoire UNIQUEMENT à l'enregistrement.
        $request->validate(['theme_id' => 'required|integer|exists:source_themes,id']);

        try {
            [$source, $subscription, $detection] = $this->service->addDetectedSource(
                $request->user(),
                (string) $request->validated('url'),
                $request->htmlConfig(),
                $request->themeId(),
            );
        } catch (SourceNotCollectableException $e) {
            $message = $e->getMessage();
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => $message], 422);
            }

            return back()->withInput()->withErrors(['url' => $message]);
        }

        // M13.2c — distinguer création / réutilisation / déjà suivie.
        [$outcome, $message] = match (true) {
            $source->wasRecentlyCreated => ['created', "Source « {$source->name} » créée et publiée au catalogue."],
            $subscription->wasRecentlyCreated => ['reused', "Source « {$source->name} » déjà dans le catalogue — abonnement ajouté."],
            default => ['already_subscribed', "Vous suivez déjà « {$source->name} » — rien de nouveau créé."],
        };

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'ok',
                'outcome' => $outcome,
                'message' => $message,
                'source' => [
                    'id' => $source->id,
                    'name' => $source->name,
                    'fetch_url' => $detection->fetchUrl,
                    'connector' => $detection->connector,
                ],
            ]);
        }

        return redirect()->route('sources.index')
            ->with('status', $message);
    }

    /** Désabonnement : supprime seulement l'abonnement de l'utilisateur. */
    public function destroy(Request $request, UserSource $userSource): RedirectResponse
    {
        Gate::authorize('delete', $userSource);

        $this->service->unsubscribe($request->user(), $userSource);

        return back()->with('status', 'Désabonnement effectué.');
    }

    /**
     * M12.16.1 — réactivation manuelle d'une source désactivée après
     * échecs répétés : remise à zéro du compteur d'échecs ; la collecte
     * la reprendra au prochain cycle. Action explicite de l'abonné.
     */
    public function reactivate(Request $request, UserSource $userSource): RedirectResponse
    {
        Gate::authorize('view', $userSource);

        $userSource->source()->update([
            'is_active' => true,
            'fail_count' => 0,
            'last_error' => null,
        ]);

        return back()->with('status', 'Source « '.$userSource->source->name.' » réactivée.');
    }

    /**
     * M12.18 — relance manuelle ponctuelle : dispatch d'un
     * FetchFeedJob(force) — dédup native par sourceId + verrou
     * existant préservés. Une source désactivée n'est PAS réactivée.
     */
    public function retry(Request $request, UserSource $userSource): RedirectResponse
    {
        Gate::authorize('view', $userSource);
        $source = $userSource->source;

        // Collecte en cours ? Le verrou du job le signale déjà.
        if (Cache::has(FetchFeedJob::lockKey($source->id))) {
            return back()->with('status', 'Une collecte est déjà en cours pour cette source.');
        }

        FetchFeedJob::dispatch($source->id, true);

        return back()->with('status', 'Relance enregistrée — le résultat sera visible à la prochaine mise à jour.');
    }
}
