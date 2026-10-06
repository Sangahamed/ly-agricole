<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>LY AGRICOLE — Du champ à l'acheteur</title>
        <meta name="description" content="LY AGRICOLE, entreprise agricole ivoirienne : achat bord-champ, stockage et commercialisation de cacao, de café, d'anacarde, de karité, de tomate et de nombreuses autres cultures, selon les saisons.">
        <link rel="canonical" href="{{ route('accueil') }}">
        <meta property="og:title" content="LY AGRICOLE — Du champ à l'acheteur">
        <meta property="og:description" content="Prix bord-champ en Côte d'Ivoire et commercialisation d'anacarde, de karité, de tomate et d'autres cultures.">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ route('accueil') }}">
        <meta property="og:image" content="{{ asset('images/logo-yl-agro.png') }}">
        <meta property="og:locale" content="fr_CI">
        <script type="application/ld+json">@json(\App\Services\Referencement::organisation())</script>
        <meta name="theme-color" content="#123524">
        <link rel="icon" type="image/png" href="{{ asset('images/logo-yl-agro.png') }}">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <script>document.documentElement.classList.add('js');</script>
        @include('vitrine.style')
    </head>
    <body class="v min-h-screen antialiased">
        <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2">Aller au contenu</a>

        @include('vitrine.entete')

        <main id="contenu">
            
            <section class="v-hero relative overflow-hidden text-white">
                <div class="v-soleil" aria-hidden="true"></div>
                <div class="relative mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.5fr_1fr]">
                    <div>
                        <p class="v-entree inline-block rounded-full border border-amber-300/50 px-3 py-1 text-xs font-medium uppercase tracking-widest text-amber-200" style="--d: .05s">Entreprise agricole ivoirienne</p>
                        <h1 class="v-entree mt-5 text-4xl font-semibold leading-[1.08] tracking-tight sm:text-6xl" style="--d: .2s">
                            Du champ<br class="hidden sm:block"> à l'acheteur.
                        </h1>
                        <p class="v-entree mt-6 max-w-xl text-lg leading-relaxed text-emerald-50/90" style="--d: .4s">
                            LY AGRICOLE achète au plus près des producteurs, stocke, et commercialise le cacao, le café,
                            l'anacarde, le karité, la tomate et bien d'autres produits de la terre ivoirienne, au fil des saisons.
                        </p>
                        <div class="v-entree mt-9 flex flex-wrap gap-3" style="--d: .6s">
                            <a href="#anacarde" class="v-bouton rounded-md bg-amber-400 px-6 py-3 font-semibold text-[#123524] hover:bg-amber-300">Découvrir l'anacarde <span class="v-fleche" aria-hidden="true">→</span></a>
                            <a href="#filieres" class="v-bouton rounded-md border border-amber-200/60 px-6 py-3 font-medium text-white hover:bg-white/10">Nos filières</a>
                            <a href="#chaine" class="v-bouton rounded-md border border-amber-200/60 px-6 py-3 font-medium text-white hover:bg-white/10">Comment nous travaillons</a>
                        </div>
                    </div>
                    <div class="v-entree flex justify-center lg:justify-end" style="--d: .35s">
                        <div id="logo-boite" class="v-logo-boite rounded-[2rem] bg-[#f3ebdc] p-6 shadow-2xl ring-8 ring-white/10">
                            <img src="{{ asset('images/logo-yl-agro.png') }}" alt="Logo de LY AGRO : un calao devant un soleil levant sur des champs, avec les lettres Y et L" width="200" height="200" class="h-48 w-48 sm:h-60 sm:w-60">
                        </div>
                    </div>
                </div>
            </section>

            <div class="v-bandeau" aria-hidden="true">
                <div class="v-bandeau-piste text-sm font-medium uppercase tracking-[0.25em]">
                    @for ($k = 0; $k < 2; $k++)
                        <span class="flex shrink-0 items-center gap-8 px-4 py-3">
                            @foreach ($cultures as $culture)
                                <span>{{ $culture->nom }}</span><span class="text-amber-300">✦</span>
                            @endforeach
                            <span>Achat bord-champ</span><span class="text-amber-300">✦</span>
                            <span>Stockage</span><span class="text-amber-300">✦</span>
                            <span>Commercialisation</span><span class="text-amber-300">✦</span>
                        </span>
                    @endfor
                </div>
            </div>

            @include('vitrine.anacarde')

            <section id="filieres" class="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                <p data-reveal class="text-sm font-medium uppercase tracking-widest text-emerald-800">Nos filières</p>
                <h2 data-reveal style="--d: .1s" class="mt-2 max-w-2xl text-3xl font-semibold tracking-tight">Des produits de la terre ivoirienne, suivis de la parcelle à la vente.</h2>

                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    <article data-reveal style="--d: .1s" class="v-carte rounded-2xl p-6">
                        <p class="v-texte-doux text-xs font-semibold uppercase tracking-widest">Cultures de rente</p>
                        <h3 class="mt-2 text-2xl font-semibold">Cacao, café, anacarde</h3>
                        <p class="v-texte-doux mt-3 leading-relaxed">
                            Achetés bord-champ, pesés et contrôlés (qualité, humidité), regroupés puis vendus aux exportateurs,
                            aux usines et aux grossistes. Hévéa, palmier à huile et coton complètent la gamme.
                        </p>
                    </article>
                    <article data-reveal style="--d: .25s" class="v-carte rounded-2xl p-6">
                        <p class="v-texte-doux text-xs font-semibold uppercase tracking-widest">Produits du terroir</p>
                        <h3 class="mt-2 text-2xl font-semibold">Karité, sésame, cola, gingembre</h3>
                        <p class="v-texte-doux mt-3 leading-relaxed">Des produits à collecter et à valoriser avec les producteurs de la zone, au rythme de leurs saisons.</p>
                    </article>
                    <article data-reveal style="--d: .4s" class="v-carte rounded-2xl p-6">
                        <p class="v-texte-doux text-xs font-semibold uppercase tracking-widest">Vivriers et maraîchers</p>
                        <h3 class="mt-2 text-2xl font-semibold">Tomate, maïs, riz, manioc…</h3>
                        <p class="v-texte-doux mt-3 leading-relaxed">Des cultures de saison, suivies avec la même rigueur que les filières longues : tomate, maïs, riz, manioc, igname, banane plantain, piment, gombo, oignon.</p>
                    </article>
                </div>

                <p data-reveal style="--d: .1s" class="v-carte mt-6 rounded-xl px-5 py-4">
                    <span class="font-medium">Selon les saisons et les prix du marché</span>, les produits que nous suivons changent : nous nous adaptons à ce que la terre et la demande offrent. La liste complète des cultures suivies figure ci-dessous, avec leurs prix.
                </p>
            </section>

            
            <section id="prix" class="mx-auto max-w-6xl px-4 pb-16 sm:px-6 sm:pb-20">
                <p data-reveal class="text-sm font-medium uppercase tracking-widest text-emerald-800">Prix bord-champ</p>
                <h2 data-reveal style="--d: .1s" class="mt-2 max-w-2xl text-3xl font-semibold tracking-tight">Les prix du moment, avec leur source.</h2>

                @if ($prix->isEmpty())
                    <p data-reveal class="v-carte mt-8 rounded-2xl p-6">Aucun prix publié pour le moment.</p>
                @else
                    <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($prixEnAvant as $i => $ligne)
                            <article data-reveal style="--d: {{ 0.1 * ($i % 3) }}s" class="v-carte rounded-2xl p-6">
                                <h3 class="text-lg font-semibold">{{ $ligne['produit']->nom }}</h3>
                                <p class="mt-2 text-3xl font-semibold tabular-nums">{{ \App\Support\Format::entier($ligne['prix']->prix_kg_fcfa) }} <span class="text-base font-normal v-texte-doux">FCFA / kg</span></p>
                                @if ($ligne['ecart'] !== null && $ligne['ecart'] !== 0)
                                    <p class="mt-1 text-sm font-medium {{ $ligne['ecart'] > 0 ? 'text-emerald-700' : 'text-red-700' }}">
                                        {{ $ligne['ecart'] > 0 ? '▲ +' : '▼ −' }}{{ \App\Support\Format::entier(abs($ligne['ecart'])) }} FCFA depuis le {{ $ligne['precedent']->date_effet->format('d/m/Y') }}
                                    </p>
                                @endif
                                <p class="v-texte-doux mt-3 text-xs">
                                    Au {{ $ligne['prix']->date_effet->format('d/m/Y') }} ·
                                    @if ($ligne['prix']->source_url)
                                        <a href="{{ $ligne['prix']->source_url }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $ligne['prix']->source }}</a>
                                    @else
                                        {{ $ligne['prix']->source }}
                                    @endif
                                </p>
                                @if ($ligne['prix']->note)<p class="v-texte-doux mt-1 text-xs">{{ $ligne['prix']->note }}</p>@endif
                            </article>
                        @endforeach
                    </div>

                @endif
                
                @if ($prix->isNotEmpty())
                    <p data-reveal class="mt-5"><a href="{{ route('prix.evolution') }}" class="v-bouton inline-block rounded-md bg-emerald-800 px-5 py-3 text-sm font-medium text-white hover:bg-emerald-900">Voir tous les prix et les courbes <span class="v-fleche" aria-hidden="true">→</span></a></p>
                @endif
                <p data-reveal class="v-texte-doux mt-5 text-xs">Prix indicatifs relevés à la date indiquée, avec leur source. Ils ne remplacent pas le prix officiel fixé pour la campagne.</p>
            </section>

            <svg class="v-vague text-[#dccdb2]" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 60V28C180 4 360 4 540 26s360 34 540 12 270-30 360-14v38Z" /></svg>

            
            <section id="chaine" class="v-alt -mt-px">
                <div class="mx-auto max-w-6xl px-4 pb-16 pt-6 sm:px-6 sm:pb-20">
                    <p data-reveal class="text-sm font-medium uppercase tracking-widest text-emerald-800">Comment nous travaillons</p>
                    <h2 data-reveal style="--d: .1s" class="mt-2 max-w-2xl text-3xl font-semibold tracking-tight">Chaque étape est suivie. Chaque kilo, chaque franc est tracé.</h2>

                    @php
                        $etapes = [
                            ['Producteurs partenaires', 'Nos agents rencontrent les producteurs dans les villages et relèvent leurs parcelles.'],
                            ['Avances de campagne', 'Un appui avant la récolte, remboursé en argent ou en kilos livrés. Sans intérêt.'],
                            ['Achat bord-champ', 'Chaque livraison est pesée et contrôlée sur place ; le producteur reçoit une confirmation.'],
                            ['Stockage', 'Les lots sont suivis en magasin, du premier kilo jusqu\'à la vente.'],
                            ['Commercialisation', 'Vente aux exportateurs, usines et grossistes, avec chaque encaissement suivi.'],
                        ];
                    @endphp
                    <ol id="etapes" class="v-etapes mt-12 space-y-6 lg:grid lg:grid-cols-5 lg:gap-5 lg:space-y-0">
                        <span class="v-ligne lg:hidden" aria-hidden="true"></span>
                        @foreach ($etapes as $i => [$titre, $texte])
                            <li data-reveal style="--d: {{ 0.12 * $i }}s" class="v-etape v-carte relative ml-12 rounded-2xl p-5 lg:ml-0">
                                <span class="v-pastille absolute -left-12 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-emerald-800 text-sm font-semibold text-white lg:static" aria-hidden="true">{{ $i + 1 }}</span>
                                <h3 class="mt-0 font-semibold lg:mt-4">{{ $titre }}</h3>
                                <p class="v-texte-doux mt-2 text-sm leading-relaxed">{{ $texte }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <p data-reveal class="v-texte-doux mt-10 max-w-3xl leading-relaxed">
                        Nos équipes de terrain travaillent avec un outil qui fonctionne <strong class="font-medium text-[#34251a]">même sans réseau</strong> :
                        rien ne se perd entre le village et le bureau.
                    </p>
                </div>
            </section>

            <svg class="v-vague -mt-px rotate-180 text-[#dccdb2]" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 60V28C180 4 360 4 540 26s360 34 540 12 270-30 360-14v38Z" /></svg>

            
            <section id="actualites" class="mx-auto max-w-6xl px-4 pt-6 sm:px-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p data-reveal class="text-sm font-medium uppercase tracking-widest text-emerald-800">Actualités</p>
                        <h2 data-reveal style="--d: .1s" class="mt-2 text-3xl font-semibold tracking-tight">Les nouvelles de LY AGRICOLE</h2>
                    </div>
                    @if ($actualites->isNotEmpty())
                        <a href="{{ route('actualites') }}" class="v-lien text-sm font-medium">Toutes les actualités →</a>
                    @endif
                </div>

                @if ($actualites->isEmpty())
                    <p data-reveal class="v-carte mt-8 rounded-2xl p-6">Aucune actualité pour le moment.</p>
                @else
                    <div class="mt-8 grid gap-5 md:grid-cols-3">
                        @foreach ($actualites as $i => $a)
                            <a data-reveal style="--d: {{ 0.12 * $i }}s" href="{{ route('actualites.voir', $a) }}" class="v-carte block rounded-2xl p-6">
                                <p class="v-texte-doux text-xs font-medium uppercase tracking-widest">{{ $a->publie_le->translatedFormat('j F Y') }}</p>
                                <h3 class="mt-1 text-lg font-semibold">{{ $a->titre }}</h3>
                                <p class="v-texte-doux mt-2 text-sm leading-relaxed">{{ \Illuminate\Support\Str::limit($a->contenu, 140) }}</p>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            
            <section id="mission" class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-16">
                <div class="grid gap-6 lg:grid-cols-2">
                    <div data-reveal="gauche" class="rounded-2xl p-8 text-white" style="background: linear-gradient(140deg, #123524, #1f4d33);">
                        <p class="text-sm font-medium uppercase tracking-widest text-amber-200">Notre mission</p>
                        <p class="mt-3 text-2xl font-semibold leading-snug">
                            Promouvoir et moderniser l'agriculture ivoirienne, et créer des emplois là où les produits sont cultivés.
                        </p>
                    </div>
                    <div data-reveal="droite" class="v-carte rounded-2xl p-8">
                        <p class="text-sm font-medium uppercase tracking-widest text-emerald-800">Notre vision</p>
                        <p class="mt-3 text-2xl font-semibold leading-snug">
                            Transformer localement nos produits et bâtir un réseau de commercialisation solide.
                        </p>
                    </div>
                </div>

                <div data-reveal class="v-carte mt-6 rounded-2xl p-6 sm:p-8">
                    <h2 class="text-xl font-semibold">Un groupe, plusieurs activités</h2>
                    <p class="v-texte-doux mt-2 max-w-3xl leading-relaxed">
                        Au-delà des cultures et du négoce, LY AGRICOLE regroupe d'autres activités agricoles :
                        <strong class="font-medium text-[#34251a]">l'élevage</strong> et <strong class="font-medium text-[#34251a]">la pisciculture</strong>.
                        Toutes s'appuient sur les mêmes principes : sérieux, suivi, et durée.
                    </p>
                </div>
            </section>

            
            <section id="contact" class="v-alt">
                <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 sm:py-16 md:grid-cols-2">
                    <div data-reveal="gauche">
                        <h2 class="text-2xl font-semibold tracking-tight">Nous trouver</h2>
                        <address class="mt-4 not-italic leading-relaxed">
                            <span class="font-medium">LY AGRICOLE</span><br>
                            <span class="v-texte-doux">Siège : Yopougon Gesco, Abidjan<br>Côte d'Ivoire</span>
                        </address>
                        @php($email = config('vitrine.contact.email'))
                        @php($tel = config('vitrine.contact.telephone'))
                        <ul class="mt-4 space-y-2">
                            <li>
                                <span class="v-texte-doux text-sm">E-mail</span><br>
                                <a href="mailto:{{ $email }}" class="v-lien font-medium" data-contact-email>{{ $email }}</a>
                            </li>
                            <li>
                                <span class="v-texte-doux text-sm">Téléphone</span><br>
                                <a href="tel:+225{{ $tel }}" class="v-lien font-medium tabular-nums" data-contact-telephone>{{ \App\Support\Telephone::afficher($tel) }}</a>
                            </li>
                        </ul>
                    </div>
                    <div data-reveal="droite">
                        <h2 class="text-2xl font-semibold tracking-tight">Producteur, agent, partenaire ?</h2>
                        <p class="v-texte-doux mt-4 leading-relaxed">
                            Les équipes de LY AGRICOLE accèdent à leur espace de gestion avec leur compte.
                        </p>
                        @auth
                            <a href="{{ route('tableau-de-bord') }}" class="v-bouton mt-4 inline-block rounded-md bg-emerald-800 px-5 py-3 font-medium text-white hover:bg-emerald-900">Ouvrir mon espace <span class="v-fleche" aria-hidden="true">→</span></a>
                        @else
                            <a href="{{ route('login') }}" class="v-bouton mt-4 inline-block rounded-md bg-emerald-800 px-5 py-3 font-medium text-white hover:bg-emerald-900">Accéder à l'espace de gestion <span class="v-fleche" aria-hidden="true">→</span></a>
                        @endauth
                    </div>
                </div>
            </section>
        </main>

        @include('vitrine.pied')

        @include('vitrine.scripts')
    </body>
</html>
