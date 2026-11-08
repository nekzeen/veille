<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf
        {{-- Honeypot anti-bots : invisible pour un humain, doit rester vide. --}}
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off"
               style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">

        <div>
            <x-input-label for="name" value="Nom" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" value="Adresse e-mail" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Mot de passe" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Confirmer le mot de passe" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        {{-- Consentement (M12.15.1) : affiché et exigé uniquement quand
             LEGAL_ENFORCE est activé — pas d'acceptation demandée tant
             que les textes sont en version provisoire. --}}
        @if (config('legal.enforce'))
        <div class="mt-4">
            <label for="consent" class="inline-flex items-start gap-2 text-sm text-gray-700">
                <input id="consent" type="checkbox" name="consent" value="1" required
                       class="mt-0.5 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                <span>J'accepte les <a class="underline text-indigo-600 hover:text-indigo-800" href="{{ route('terms') }}">conditions d'utilisation</a>
                    et reconnais avoir pris connaissance de la
                    <a class="underline text-indigo-600 hover:text-indigo-800" href="{{ route('privacy') }}">politique de confidentialité</a>.</span>
            </label>
            <x-input-error :messages="$errors->get('consent')" class="mt-2" />
        </div>
        @endif

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                Déjà un compte ? Se connecter
            </a>

            <x-primary-button class="ms-4">
                Créer mon compte
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
