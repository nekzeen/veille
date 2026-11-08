<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\CatalogueActivityService;
use App\Services\SourceLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        // M12.6 — suppression complète : cascades FK couvrent veilles,
        // pivots, article_user, exclusions, hidden, shares, categories ;
        // `sessions` (sans cascade) et `digest_drafts` sont purgés
        // explicitement dans la même transaction.
        DB::transaction(function () use ($user): void {
            $sourceIds = app(SourceLifecycleService::class)
                ->sourceIdsForUser($user->id);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
            app(SourceLifecycleService::class)->reevaluate($sourceIds);
            app(CatalogueActivityService::class)->reevaluate($sourceIds);
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
