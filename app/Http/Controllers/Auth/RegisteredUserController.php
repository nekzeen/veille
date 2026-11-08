<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // M12.15.1 — consentement exigé uniquement quand les textes sont
        // activés explicitement (LEGAL_ENFORCE). Mode provisoire : la
        // case n'est ni affichée ni requise, aucune acceptation n'est
        // enregistrée (pas de preuve fictive sur un document draft).
        $enforced = (bool) config('legal.enforce');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'consent' => $enforced ? ['required', 'accepted'] : ['nullable'],
            // Honeypot anti-bots : doit rester absent ou vide.
            'website' => ['nullable', 'size:0'],
        ], [
            'name.required' => 'Le nom est requis.',
            'email.required' => 'L’adresse e-mail est requise.',
            'email.email' => 'L’adresse e-mail est invalide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'password.required' => 'Le mot de passe est requis.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'consent.required' => 'Vous devez accepter les conditions d’utilisation.',
            'consent.accepted' => 'Vous devez accepter les conditions d’utilisation.',
            'website.size' => 'Soumission invalide.',
        ]);

        $attrs = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ];
        // M12.15 — traçabilité : n'écrit que si l'activation explicite
        // est faite ET la migration 000006 appliquée.
        if ($enforced && Schema::hasColumn('users', 'terms_version')) {
            $attrs['terms_version'] = config('legal.terms_version');
            $attrs['privacy_version'] = config('legal.privacy_version');
            $attrs['terms_accepted_at'] = now('UTC');
        }
        $user = User::create($attrs);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
