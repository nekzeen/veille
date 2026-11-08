@php
    $navItems = [
        ['route' => 'dashboard', 'active' => ['dashboard'], 'label' => __('Tableau de bord')],
        ['route' => 'veilles.index', 'active' => ['veilles.*'], 'label' => 'Mes veilles'],
        ['route' => 'favorites.index', 'active' => ['favorites.*', 'articles.*'], 'label' => 'Mes favoris'],
        ['route' => 'archives.index', 'active' => ['archives.*'], 'label' => 'Mes archives'],
        ['route' => 'categories.index', 'active' => ['categories.*'], 'label' => 'Mes catégories'],
        ['route' => 'exclusions.index', 'active' => ['exclusions.*'], 'label' => 'Mes exclusions'],
        ['route' => 'shares.index', 'active' => ['shares.*'], 'label' => 'Mes partages'],
        ['route' => 'sources.index', 'active' => ['sources.index'], 'label' => 'Mes sources'],
        ['route' => 'sources.catalogue', 'active' => ['sources.catalogue'], 'label' => 'Catalogue'],
    ];
@endphp

<nav x-data="{ open: false }" class="bg-white border-b border-slate-200">
    <!-- Primary Navigation Menu -->
    <div class="max-w-screen-2xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between h-14 gap-4">
            <div class="flex items-center min-w-0">
                <!-- Logo -->
                <a href="{{ route('dashboard') }}" class="shrink-0 flex items-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300">
                    <img src="{{ asset('images/logo.svg') }}" alt="Veille" class="h-7 w-auto">
                </a>

                <span class="hidden xl:block mx-4 h-6 w-px bg-slate-200" aria-hidden="true"></span>

                <!-- Navigation Links -->
                <div class="hidden xl:flex xl:items-center xl:gap-1">
                    @foreach ($navItems as $item)
                        <x-nav-link :href="route($item['route'])" :active="request()->routeIs(...$item['active'])">
                            {{ $item['label'] }}
                        </x-nav-link>
                    @endforeach
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden xl:flex xl:items-center shrink-0">
                <span class="mr-3 h-6 w-px bg-slate-200" aria-hidden="true"></span>
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-1 whitespace-nowrap rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition ease-in-out duration-150">
                            <span>{{ Auth::user()->name }}</span>
                            <svg class="fill-current h-4 w-4 text-slate-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profil') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Se déconnecter') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Text menu toggle (narrow screens) -->
            <div class="flex items-center xl:hidden">
                <button type="button" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="responsive-nav"
                        class="whitespace-nowrap rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 transition duration-150 ease-in-out">
                    <span x-text="open ? 'Fermer' : 'Menu'">Menu</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div id="responsive-nav" :class="{'block': open, 'hidden': ! open}" class="hidden xl:hidden border-t border-slate-200">
        <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-3 grid gap-1 sm:grid-cols-2 md:grid-cols-3">
            @foreach ($navItems as $item)
                <x-responsive-nav-link :href="route($item['route'])" :active="request()->routeIs(...$item['active'])">
                    {{ $item['label'] }}
                </x-responsive-nav-link>
            @endforeach
        </div>

        <!-- Responsive Settings Options -->
        <div class="border-t border-slate-200">
            <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 py-3">
                <div class="px-3">
                    <div class="font-medium text-base text-slate-900">{{ Auth::user()->name }}</div>
                    <div class="font-medium text-sm text-slate-500">{{ Auth::user()->email }}</div>
                </div>

                <div class="mt-2 grid gap-1 sm:grid-cols-2 md:grid-cols-3">
                    <x-responsive-nav-link :href="route('profile.edit')">
                        {{ __('Profil') }}
                    </x-responsive-nav-link>

                    <!-- Authentication -->
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <x-responsive-nav-link :href="route('logout')"
                                onclick="event.preventDefault();
                                            this.closest('form').submit();">
                            {{ __('Se déconnecter') }}
                        </x-responsive-nav-link>
                    </form>
                </div>
            </div>
        </div>
    </div>
</nav>
