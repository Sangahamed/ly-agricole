<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Accès refusé — {{ config('app.name') }}</title>

        @include('partials.icones')

        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-stone-50 px-4 text-stone-900 antialiased">
        <main class="max-w-sm text-center">
            <p class="text-sm font-semibold tracking-wide text-emerald-800">LY AGRICOLE</p>
            <h1 class="mt-4 text-xl font-semibold">Accès refusé</h1>
            <p class="mt-2 text-stone-600">
                Votre rôle ne donne pas accès à cette page. Si vous en avez besoin, demandez à l'administrateur.
            </p>
            <a href="{{ url('/tableau-de-bord') }}" class="mt-6 inline-block rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                Retour au tableau de bord
            </a>
        </main>
    </body>
</html>
