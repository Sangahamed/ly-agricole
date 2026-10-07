<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Connexion' }} — {{ config('app.name') }}</title>

        @fonts
        @include('partials.icones')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{-- Messages posés avant une redirection : affichés en bandeau (resources/js/notifications-flash.js). --}}
    <body class="min-h-screen bg-stone-100 text-stone-900 antialiased" data-flash-statut="{{ session('statut') }}" data-flash-erreur="{{ session('erreur') ?? $errors->first() }}">
        <div class="grid min-h-screen lg:grid-cols-2">
            {{-- Présentation : rien ici ne mène à un écran, uniquement du texte et l'identité. --}}
            <div class="hidden flex-col justify-between bg-emerald-900 p-10 text-white lg:flex xl:p-14">
                <div>
                    <a href="{{ route('accueil') }}" class="inline-flex rounded-xl bg-white p-1.5" aria-label="Retour à l'accueil de LY AGRICOLE">
                        <img src="{{ asset('images/logo-yl-agro.png') }}" alt="" width="56" height="56" class="h-14 w-14">
                    </a>
                    <p class="mt-6 text-3xl font-semibold tracking-tight">LY AGRICOLE</p>
                    <p class="mt-1 text-sm text-emerald-200">Cultiver – Élever – Durer</p>
                </div>

                <div class="max-w-md space-y-6">
                    <p class="text-xl font-medium leading-snug">
                        Chaque franc prêté, chaque kilo acheté, chaque franc remboursé.
                    </p>
                    <ul class="space-y-4 text-sm text-emerald-100">
                        <li class="flex gap-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true"><path d="M12 3v18M16.5 7.5c-.6-1-2-1.5-4.5-1.5-2.6 0-4 1-4 2.6 0 3.6 9 1.6 9 5.6 0 1.7-1.6 2.8-4.5 2.8-2.3 0-4-.6-4.7-2" /></svg>
                            <span>Prêts de campagne suivis de la demande au remboursement, en argent ou en kilos.</span>
                        </li>
                        <li class="flex gap-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true"><path d="M21 8 12 3 3 8m18 0-9 5m9-5v8l-9 5m0-8L3 8m9 5v8M3 8v8l9 5" /></svg>
                            <span>Achats bord-champ, stock par lot et trésorerie, à chaque étape.</span>
                        </li>
                        <li class="flex gap-3">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10" /></svg>
                            <span>Rien ne s'efface : chaque saisie est journalisée et validée par une autre personne.</span>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-emerald-300">Usage interne — réservé aux équipes et partenaires de LY AGRICOLE.</p>
            </div>

            {{-- Connexion : accessible sans compte, mais ne mène nulle part sans identifiants. --}}
            <main class="flex flex-col items-center justify-center px-4 py-10">
                <div class="mb-8 text-center lg:hidden">
                    <p class="text-2xl font-semibold tracking-wide text-emerald-800">LY AGRICOLE</p>
                    <p class="mt-1 text-sm text-stone-500">Cultiver – Élever – Durer</p>
                </div>

                <div class="w-full max-w-sm rounded-xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
