@php
    $campagneOuverte = \App\Models\Campagne::query()->with('produit')
        ->where('statut', \App\Enums\StatutCampagne::Ouverte)->orderByDesc('debut')->first();
    $utilisateur = auth()->user();
    $initiales = collect(preg_split('/\s+/', trim($utilisateur->nom)))->take(2)->map(fn ($m) => mb_strtoupper(mb_substr($m, 0, 1)))->implode('');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Avis en direct (Reverb) : Echo s'abonne au canal privé de cet utilisateur. --}}
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @auth
            <meta name="ly-utilisateur" content="{{ auth()->id() }}">
        @endauth

        <title>{{ $title ?? config('app.name') }} — {{ config('app.name') }}</title>

        @fonts
        @include('partials.icones')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- Messages posés avant une redirection : affichés en bandeau (resources/js/notifications-flash.js). --}}
    <body class="min-h-screen bg-stone-100 text-stone-900 antialiased" data-flash-statut="{{ session('statut') }}" data-flash-erreur="{{ session('erreur') ?? $errors->first() }}">
        {{-- Voile derrière le menu sur téléphone --}}
        <div data-menu-voile class="fixed inset-0 z-30 hidden bg-stone-900/40 lg:hidden"></div>

        <aside data-menu id="menu"
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-stone-200 bg-stone-50 transition-transform duration-200 lg:translate-x-0">
            <div class="flex h-16 shrink-0 items-center gap-3 border-b border-stone-200 px-5">
                <img src="{{ asset('images/logo-yl-agro.png') }}" alt="" width="36" height="36" class="h-9 w-9 shrink-0">
                <a href="{{ route('tableau-de-bord') }}" class="leading-tight">
                    <span class="block text-sm font-semibold tracking-wide">LY AGRICOLE</span>
                    <span class="block text-xs text-stone-500">Gestion de campagne</span>
                </a>
            </div>

            {{-- Chaque lien n'apparaît qu'avec le droit correspondant ; la route le revérifie. --}}
            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5" aria-label="Menu principal">
                <div class="space-y-1">
                    <x-nav-lien route="tableau-de-bord" motif="tableau-de-bord" icone="accueil">Tableau de bord</x-nav-lien>
                    @can('voir-rapports')
                        <x-nav-lien route="rapports" motif="rapports*" icone="rapports">Rapports</x-nav-lien>
                    @endcan
                </div>

                @canany(['voir-producteurs', 'saisir-achats', 'valider-achats', 'voir-rendements', 'voir-fiabilite', 'voir-visites'])
                    <div>
                        <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-stone-500">Terrain</p>
                        <div class="space-y-1">
                            @can('voir-producteurs')
                                <x-nav-lien route="producteurs" motif="producteurs*" icone="producteurs">Producteurs</x-nav-lien>
                            @endcan
                            @canany(['saisir-achats', 'valider-achats'])
                                <x-nav-lien route="achats" motif="achats*" icone="achats">Achats</x-nav-lien>
                            @endcanany
                            @can('voir-fiabilite')
                                <x-nav-lien route="fiabilite" motif="fiabilite*" icone="fiabilite">Fiabilité</x-nav-lien>
                            @endcan
                            @can('voir-rendements')
                                <x-nav-lien route="rendements" motif="rendements" icone="rendements">Rendements</x-nav-lien>
                            @endcan
                            @can('voir-visites')
                                <x-nav-lien route="visites" motif="visites" icone="visites">Visites</x-nav-lien>
                            @endcan
                            @can('voir-ia')
                                <x-nav-lien route="ia.diagnostics" motif="ia.diagnostics" icone="ia">Diagnostics IA</x-nav-lien>
                                <x-nav-lien route="ia.referentiel" motif="ia.referentiel" icone="traitements">Traitements</x-nav-lien>
                            @endcan
                        </div>
                    </div>
                @endcanany

                @canany(['voir-prets', 'voir-ventes', 'gerer-tresorerie', 'gerer-apports', 'voir-resultat-campagne', 'voir-rapport-campagne', 'voir-budget', 'saisir-depenses', 'valider-depenses'])
                    <div>
                        <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-stone-500">Argent</p>
                        <div class="space-y-1">
                            @can('voir-prets')
                                <x-nav-lien route="prets" motif="prets*" icone="prets">Prêts</x-nav-lien>
                            @endcan
                            @can('voir-ventes')
                                <x-nav-lien route="ventes" motif="ventes*" icone="ventes">Ventes</x-nav-lien>
                            @endcan
                            @can('gerer-tresorerie')
                                <x-nav-lien route="tresorerie" motif="tresorerie*" icone="tresorerie">Trésorerie</x-nav-lien>
                            @endcan
                            @can('gerer-apports')
                                <x-nav-lien route="apports" motif="apports" icone="apports">Apports</x-nav-lien>
                            @endcan
                            @can('voir-rapport-campagne')
                                <x-nav-lien route="rapport-campagne" motif="rapport-campagne*" icone="rapport">Rapport de campagne</x-nav-lien>
                            @endcan
                            @can('voir-resultat-campagne')
                                <x-nav-lien route="resultat" motif="resultat" icone="resultat">Résultat</x-nav-lien>
                            @endcan
                            @can('voir-budget')
                                <x-nav-lien route="budget" motif="budget" icone="budget">Budget</x-nav-lien>
                            @endcan
                            @canany(['saisir-depenses', 'valider-depenses'])
                                <x-nav-lien route="depenses" motif="depenses*" icone="depenses">Dépenses</x-nav-lien>
                            @endcanany
                        </div>
                    </div>
                @endcanany

                @can('voir-portail-investisseur')
                    <div>
                        <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-stone-500">Investisseur</p>
                        <div class="space-y-1">
                            <x-nav-lien route="mon-investissement" motif="mon-investissement" icone="apports">Mon investissement</x-nav-lien>
                        </div>
                    </div>
                @endcan

                @canany(['gerer-intrants', 'gerer-stock'])
                    <div>
                        <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-stone-500">Stock</p>
                        <div class="space-y-1">
                            @can('gerer-stock')
                                <x-nav-lien route="lots" motif="lots*" icone="lots">Lots</x-nav-lien>
                            @endcan
                            @can('gerer-intrants')
                                <x-nav-lien route="intrants" motif="intrants*" icone="intrants">Intrants</x-nav-lien>
                            @endcan
                        </div>
                    </div>
                @endcanany

                @canany(['gerer-referentiels', 'gerer-campagnes', 'gerer-parametres', 'gerer-tresorerie', 'gerer-intrants', 'voir-journal', 'ouvrir-comptes', 'gerer-appareils', 'gerer-publications'])
                    <div>
                        <p class="mb-2 px-3 text-xs font-medium uppercase tracking-wider text-stone-500">Administration</p>
                        <div class="space-y-1">
                            @canany(['gerer-referentiels', 'gerer-campagnes', 'gerer-parametres', 'gerer-tresorerie', 'gerer-intrants'])
                                <x-nav-lien route="referentiels" motif="referentiels*" icone="referentiels">Référentiels</x-nav-lien>
                            @endcanany
                            @can('voir-journal')
                                <x-nav-lien route="journal" motif="journal" icone="journal">Journal</x-nav-lien>
                            @endcan
                            @can('gerer-utilisateurs')
                                <x-nav-lien route="utilisateurs" motif="utilisateurs" icone="utilisateurs">Utilisateurs</x-nav-lien>
                            @elsecan('gerer-agents')
                                <x-nav-lien route="utilisateurs" motif="utilisateurs" icone="utilisateurs">Agents</x-nav-lien>
                            @endcan
                            @can('gerer-publications')
                                <x-nav-lien route="publications" motif="publications" icone="publications">Vitrine</x-nav-lien>
                            @endcan
                            @can('gerer-appareils')
                                <x-nav-lien route="appareils" motif="appareils" icone="appareils">Appareils</x-nav-lien>
                            @endcan
                        </div>
                    </div>
                @endcanany
            </nav>

            <div class="shrink-0 border-t border-stone-200 p-4 text-xs text-stone-500">
                @if ($campagneOuverte)
                    <p class="font-medium text-stone-700">Campagne en cours</p>
                    <p class="mt-0.5">{{ $campagneOuverte->produit->nom }} · {{ $campagneOuverte->code }}</p>
                @else
                    <p>Aucune campagne ouverte</p>
                @endif
            </div>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-stone-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" data-menu-bouton aria-controls="menu" aria-expanded="false" aria-label="Ouvrir le menu"
                        class="rounded-lg p-2 text-stone-700 hover:bg-stone-100 lg:hidden">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="h-6 w-6" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <p class="truncate text-sm font-medium text-stone-700">{{ $title ?? config('app.name') }}</p>
                </div>

                <div class="flex items-center gap-2 sm:gap-3">
                    @if ($campagneOuverte && $campagneOuverte->prix_officiel_kg_fcfa !== null)
                        <a href="{{ route('tableau-de-bord') }}" class="hidden rounded-full border border-stone-200 bg-stone-50 px-3 py-1.5 text-xs text-stone-700 md:block" title="Prix officiel bord-champ">
                            {{ $campagneOuverte->produit->nom }} <span class="font-semibold tabular-nums">{{ number_format($campagneOuverte->prix_officiel_kg_fcfa, 0, ',', "\u{202F}") }} FCFA/kg</span>
                        </a>
                    @endif

                    @canany(['saisir-prets', 'gerer-producteurs', 'saisir-depenses', 'saisir-achats'])
                        <details class="relative" data-deroulant>
                            <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg bg-emerald-800 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-900 [&::-webkit-details-marker]:hidden">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="h-4 w-4" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                <span class="hidden sm:inline">Nouveau</span>
                            </summary>
                            <div class="absolute right-0 mt-2 w-56 rounded-xl border border-stone-200 bg-white p-1.5 text-sm shadow-lg">
                                @can('saisir-prets')
                                    <a href="{{ route('prets.nouveau') }}" class="block rounded-lg px-3 py-2 hover:bg-stone-100">Demande de prêt</a>
                                @endcan
                                @can('gerer-producteurs')
                                    <a href="{{ route('producteurs.nouveau') }}" class="block rounded-lg px-3 py-2 hover:bg-stone-100">Producteur</a>
                                @endcan
                                @can('saisir-depenses')
                                    <a href="{{ route('depenses.nouvelle') }}" class="block rounded-lg px-3 py-2 hover:bg-stone-100">Dépense</a>
                                @endcan
                                @can('saisir-achats')
                                    <a href="{{ route('achats.nouveau') }}" class="block rounded-lg px-3 py-2 hover:bg-stone-100">Achat bord-champ</a>
                                @endcan
                            </div>
                        </details>
                    @endcanany

                    @auth
                        @php $nonLues = auth()->user()->unreadNotifications()->count(); @endphp
                        <a href="{{ route('notifications') }}" data-cloche class="relative inline-flex items-center justify-center rounded-lg p-2 text-stone-700 hover:bg-stone-100"
                            aria-label="Notifications{{ $nonLues > 0 ? ' : '.$nonLues.' non lue(s)' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16ZM10 20a2 2 0 0 0 4 0" /></svg>
                            @if ($nonLues > 0)
                                <span data-cloche-compte class="absolute -right-0.5 -top-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-semibold leading-5 text-white tabular-nums">{{ $nonLues > 99 ? '99+' : $nonLues }}</span>
                            @endif
                        </a>
                    @endauth

                    <details class="relative" data-deroulant>
                        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg p-1 hover:bg-stone-100 [&::-webkit-details-marker]:hidden">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-stone-200 text-xs font-semibold text-stone-800">{{ $initiales }}</span>
                            <span class="hidden text-left text-sm leading-tight sm:block">
                                <span class="block font-medium">{{ $utilisateur->nom }}</span>
                                <span class="block text-xs text-stone-500">{{ $utilisateur->role?->libelle() ?? 'Aucun rôle' }}</span>
                            </span>
                        </summary>
                        <div class="absolute right-0 mt-2 w-56 rounded-xl border border-stone-200 bg-white p-1.5 text-sm shadow-lg">
                            <p class="px-3 py-2 text-xs text-stone-500 sm:hidden">{{ $utilisateur->nom }} · {{ $utilisateur->role?->libelle() ?? 'Aucun rôle' }}</p>
                            <a href="{{ route('mot-de-passe') }}" class="block rounded-lg px-3 py-2 hover:bg-stone-100">Mon mot de passe</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-stone-100">Se déconnecter</button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>

            <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
