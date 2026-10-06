@php use App\Support\Format; @endphp
<x-vitrine.page :titre="$referencement['titre']" :description="$referencement['description']" :canonique="$referencement['canonique']">
    <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6 sm:py-20">
        <a href="{{ route('accueil') }}#prix" class="v-lien text-sm">← Les prix du moment</a>
        <p class="mt-6 text-sm font-medium uppercase tracking-widest text-emerald-800">Prix bord-champ</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight">Les prix, campagne après campagne</h1>
        <p class="v-texte-doux mt-2 max-w-2xl">D'abord le tableau d'ensemble de toutes nos cultures sur les sept dernières campagnes, puis, plus bas, la courbe détaillée de chaque produit. Chaque prix est daté et sourcé. Ces prix sont indicatifs : ils ne remplacent pas le prix officiel fixé pour la campagne.</p>

        {{-- Tableau d'ensemble : une ligne par culture, une colonne par campagne (la plus récente à droite). --}}
        <div class="mt-8">
            <h2 class="text-xl font-semibold">Toutes les cultures, {{ count($tableau['campagnes']) > 1 ? 'les '.count($tableau['campagnes']).' dernières campagnes' : 'la campagne connue' }}</h2>
            <p class="v-texte-doux mt-1 text-sm">Dernier prix publié de chaque campagne, en FCFA par kg, avec l'écart par rapport à la campagne précédente de la culture. Une case « — » : prix pas encore relevé. Les colonnes sont des années de campagne, d'octobre à septembre : la campagne de l'anacarde ouverte en février 2024 figure dans « 2023-2024 ». Quand un prix change en cours de campagne (campagne intermédiaire), c'est le dernier prix qui est indiqué.</p>
            @if (count($tableau['campagnes']) === 0)
                <p class="v-carte mt-4 rounded-2xl p-6">Aucune campagne commencée pour le moment : le tableau se remplira avec les campagnes et leurs prix.</p>
            @else
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <label for="recherche-culture" class="sr-only">Chercher une culture</label>
                    <input type="search" id="recherche-culture" data-recherche-culture placeholder="Chercher une culture (ex. maïs, hévéa…)" autocomplete="off"
                        class="w-full max-w-sm rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                    <p class="v-texte-doux text-xs" data-recherche-compte role="status"></p>
                </div>
                <div class="v-carte mt-3 overflow-x-auto rounded-2xl">
                    <table class="w-full min-w-[640px] text-left text-sm" data-tableau-cultures>
                        <caption class="sr-only">Dernier prix par culture et par campagne</caption>
                        <thead>
                            <tr class="v-texte-doux text-xs">
                                <th scope="col" class="sticky left-0 z-10 bg-[#f3ebdc] px-4 py-3 font-medium">Culture</th>
                                @foreach ($tableau['campagnes'] as $code)
                                    <th scope="col" class="px-3 py-3 text-right font-medium">{{ $code }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tableau['lignes'] as $ligne)
                                <tr class="border-t border-[#34251a]/10 {{ $ligne['connu'] ? '' : 'v-texte-doux' }}" data-culture="{{ $ligne['produit']->nom }}">
                                    <th scope="row" class="sticky left-0 z-10 bg-[#f3ebdc] px-4 py-2 font-medium">
                                        @if ($ligne['connu'])
                                            <a href="{{ route('prix.evolution', ['produit' => $ligne['produit']->id]) }}" class="underline decoration-dotted">{{ $ligne['produit']->nom }}</a>
                                        @else
                                            {{ $ligne['produit']->nom }}
                                        @endif
                                    </th>
                                    @foreach ($tableau['campagnes'] as $code)
                                        @php($case = $ligne['cases'][$code])
                                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                            @if ($case === null)
                                                <span aria-label="prix non relevé">—</span>
                                            @else
                                                <span class="font-medium">{{ Format::entier($case['prix']) }}</span>
                                                @if ($case['ecart'] !== null && $case['ecart'] !== 0)
                                                    <span class="ml-1 text-xs {{ $case['ecart'] > 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ $case['ecart'] > 0 ? '▲' : '▼' }} {{ Format::entier(abs($case['ecart'])) }}</span>
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="v-texte-doux hidden px-4 py-6 text-sm" data-recherche-vide>Aucune culture ne correspond à cette recherche.</p>
                </div>
            @endif
        </div>

        <h2 id="courbes" class="mt-12 scroll-mt-24 text-xl font-semibold">Courbe détaillée par produit</h2>
        <p class="v-texte-doux mt-1 text-sm">Choisissez un produit ou une période : les courbes se mettent à jour aussitôt. Le prix reste le même jusqu'au changement suivant.</p>

        @if ($produits->isEmpty())
            <p class="v-carte mt-8 rounded-2xl p-6">Aucun prix publié pour le moment.</p>
        @else
            <div data-zone-courbes>
            {{-- Filtres : une seule ligne, au-dessus des graphiques ; appliqués dès qu'on choisit (bouton seulement sans JavaScript). --}}
            <form method="GET" action="{{ route('prix.evolution') }}#courbes" data-filtres-prix class="v-carte mt-8 flex flex-wrap items-end gap-4 rounded-2xl p-4 text-sm">
                <div>
                    <label for="produit" class="mb-1 block text-xs font-medium">Produit</label>
                    <select name="produit" id="produit" class="rounded-md border border-stone-300 bg-white px-3 py-2">
                        <option value="">Tous les produits</option>
                        @foreach ($produits as $p)
                            <option value="{{ $p->id }}" @selected($produitChoisi?->id === $p->id)>{{ $p->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="periode" class="mb-1 block text-xs font-medium">Période</label>
                    <select name="periode" id="periode" class="rounded-md border border-stone-300 bg-white px-3 py-2">
                        <option value="tout" @selected($periode === 'tout')>Toute la période</option>
                        <option value="6m" @selected($periode === '6m')>6 derniers mois</option>
                        <option value="12m" @selected($periode === '12m')>12 derniers mois</option>
                        {{-- Les campagnes du produit choisi seulement : toutes les cultures en donneraient plus de cent. --}}
                        @foreach ($campagnes->when($produitChoisi !== null, fn ($liste) => $liste->where('produit_id', $produitChoisi->id)) as $c)
                            <option value="campagne-{{ $c->id }}" @selected($periode === 'campagne-'.$c->id)>Campagne {{ $c->produit->nom }} {{ $c->code }}</option>
                        @endforeach
                        <option value="perso" @selected($periode === 'perso')>Dates personnalisées</option>
                    </select>
                </div>
                <div data-dates-perso @if ($periode !== 'perso') hidden @endif class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="du" class="mb-1 block text-xs font-medium">Du</label>
                        <input type="date" name="du" id="du" value="{{ $du }}" class="rounded-md border border-stone-300 bg-white px-3 py-2">
                    </div>
                    <div>
                        <label for="au" class="mb-1 block text-xs font-medium">Au</label>
                        <input type="date" name="au" id="au" value="{{ $au }}" class="rounded-md border border-stone-300 bg-white px-3 py-2">
                    </div>
                </div>
                <noscript><button type="submit" class="v-bouton rounded-md bg-emerald-800 px-4 py-2 font-medium text-white hover:bg-emerald-900">Afficher</button></noscript>
                <p class="v-texte-doux text-xs" data-filtres-etat role="status" aria-live="polite"></p>
            </form>

            <div class="mt-8 space-y-8">
                @foreach ($graphiques as $g)
                    @php($c = $g['courbe'])
                    @php($r = $g['serie']['resume'])
                    <article class="v-carte rounded-2xl p-5 sm:p-6" aria-labelledby="titre-{{ $g['produit']->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="titre-{{ $g['produit']->id }}" class="text-xl font-semibold">{{ $g['produit']->nom }}</h2>
                            <p class="v-texte-doux text-xs">du {{ $g['serie']['debut']->format('d/m/Y') }} au {{ $g['serie']['fin']->format('d/m/Y') }} · FCFA par kilo</p>
                        </div>

                        @if ($c === null)
                            <p class="v-texte-doux mt-4">Aucun prix publié sur cette période.</p>
                        @else
                            <div class="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3">
                                <p>
                                    <span class="text-3xl font-semibold tracking-tight">{{ Format::entier($r['dernier']) }}</span>
                                    <span class="v-texte-doux text-sm">FCFA/kg</span>
                                    @if ($r['variation'] !== 0)
                                        <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $r['variation'] > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ $r['variation'] > 0 ? '▲ +' : '▼ −' }}{{ Format::entier(abs($r['variation'])) }} depuis {{ Format::entier($r['premier']) }}</span>
                                    @else
                                        <span class="ml-2 inline-flex items-center rounded-full bg-[#34251a]/8 px-2 py-0.5 text-xs font-semibold">Stable</span>
                                    @endif
                                </p>
                                <dl class="v-texte-doux flex gap-5 text-xs">
                                    <div><dt>Plus bas</dt><dd class="text-sm font-medium tabular-nums text-[#34251a]">{{ Format::entier($r['min']) }}</dd></div>
                                    <div><dt>Plus haut</dt><dd class="text-sm font-medium tabular-nums text-[#34251a]">{{ Format::entier($r['max']) }}</dd></div>
                                    <div><dt>Changements</dt><dd class="text-sm font-medium tabular-nums text-[#34251a]">{{ $r['changements'] }}</dd></div>
                                </dl>
                            </div>

                            <div class="relative mt-5" data-courbe>
                                @php($idg = 'lavis-'.$g['produit']->id)
                                <svg viewBox="0 0 {{ $c['largeur'] }} {{ $c['hauteur'] }}" class="w-full touch-pan-y select-none overflow-visible" role="img"
                                    data-zone-g="{{ $c['zone']['gauche'] }}" data-zone-d="{{ $c['zone']['droite'] }}" data-zone-h="{{ $c['zone']['haut'] }}" data-zone-b="{{ $c['zone']['bas'] }}"
                                    aria-label="Évolution du prix {{ $g['produit']->nom }} : de {{ Format::entier($r['premier']) }} à {{ Format::entier($r['dernier']) }} FCFA par kilo, du {{ $g['serie']['debut']->format('d/m/Y') }} au {{ $g['serie']['fin']->format('d/m/Y') }}. Le tableau ci-dessous donne toutes les valeurs.">
                                    <defs>
                                        <linearGradient id="{{ $idg }}" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0" stop-color="#1f7a45" stop-opacity="0.22" />
                                            <stop offset="1" stop-color="#1f7a45" stop-opacity="0.02" />
                                        </linearGradient>
                                    </defs>

                                    {{-- Grille : filets fins et discrets ; la ligne de base un peu plus marquée. --}}
                                    @foreach ($c['graduations_y'] as $grad)
                                        <line x1="{{ $c['zone']['gauche'] }}" x2="{{ $c['zone']['droite'] }}" y1="{{ $grad['y'] }}" y2="{{ $grad['y'] }}" stroke="#34251a" stroke-opacity="{{ $loop->first ? '0.28' : '0.08' }}" stroke-width="1" shape-rendering="crispEdges" />
                                        <text x="{{ $c['zone']['gauche'] - 12 }}" y="{{ $grad['y'] + 4 }}" text-anchor="end" font-size="11" fill="#7a6450" style="font-variant-numeric: tabular-nums">{{ Format::entier($grad['valeur']) }}</text>
                                    @endforeach
                                    @foreach ($c['graduations_x'] as $grad)
                                        <line x1="{{ $grad['x'] }}" x2="{{ $grad['x'] }}" y1="{{ $c['zone']['bas'] }}" y2="{{ $c['zone']['bas'] + 5 }}" stroke="#34251a" stroke-opacity="0.28" shape-rendering="crispEdges" />
                                        <text x="{{ $grad['x'] }}" y="{{ $c['zone']['bas'] + 22 }}" text-anchor="middle" font-size="11" fill="#7a6450">{{ $grad['libelle'] }}</text>
                                    @endforeach

                                    <path d="{{ $c['aire'] }}" fill="url(#{{ $idg }})" stroke="none" />
                                    <path d="{{ $c['chemin'] }}" fill="none" stroke="#1f7a45" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                                    {{-- Chaque changement de prix : point cerclé de la couleur du fond ; le prix déjà en vigueur au début est creux. --}}
                                    @foreach ($c['marqueurs'] as $m)
                                        @php($pt = $g['serie']['points'][$m['index']])
                                        <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="4" fill="{{ $pt['report'] ? '#f3ebdc' : '#1f7a45' }}" stroke="{{ $pt['report'] ? '#1f7a45' : '#f3ebdc' }}" stroke-width="2" pointer-events="none" />
                                    @endforeach

                                    {{-- Dernier prix : pastille à droite de la courbe. --}}
                                    @php($texteFin = Format::entier($r['dernier']))
                                    @php($largeurPastille = 16 + 7.5 * mb_strlen($texteFin))
                                    <circle cx="{{ $c['fin']['x'] }}" cy="{{ $c['fin']['y'] }}" r="5" fill="#1f7a45" stroke="#f3ebdc" stroke-width="2" />
                                    <rect x="{{ $c['fin']['x'] + 10 }}" y="{{ $c['fin']['y'] - 11 }}" width="{{ $largeurPastille }}" height="22" rx="11" fill="#1f7a45" />
                                    <text x="{{ $c['fin']['x'] + 10 + $largeurPastille / 2 }}" y="{{ $c['fin']['y'] + 4 }}" text-anchor="middle" font-size="12" font-weight="600" fill="#ffffff" style="font-variant-numeric: tabular-nums">{{ $texteFin }}</text>

                                    {{-- Réticule : suit le pointeur, le point se pose sur le prix en vigueur à cette date. --}}
                                    <g data-reticule visibility="hidden" pointer-events="none">
                                        <line data-reticule-ligne x1="0" x2="0" y1="{{ $c['zone']['haut'] }}" y2="{{ $c['zone']['bas'] }}" stroke="#34251a" stroke-opacity="0.35" stroke-width="1" />
                                        <circle data-reticule-point cx="0" cy="0" r="6" fill="#1f7a45" stroke="#f3ebdc" stroke-width="2.5" />
                                    </g>

                                    {{-- Cibles clavier (Tab) : une par changement de prix, invisibles. --}}
                                    @foreach ($c['marqueurs'] as $m)
                                        @php($pt = $g['serie']['points'][$m['index']])
                                        <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="12" fill="transparent" tabindex="0" pointer-events="none" style="outline: none"
                                            data-x="{{ $m['x'] }}" data-y="{{ $m['y'] }}"
                                            data-date="{{ $pt['report'] ? 'En vigueur au '.$pt['date']->format('d/m/Y') : 'Depuis le '.$pt['date']->format('d/m/Y') }}" data-prix="{{ Format::entier($pt['prix']) }} FCFA / kg"
                                            data-source="{{ $pt['source'] }}"
                                            aria-label="{{ $pt['date']->format('d/m/Y') }} : {{ Format::entier($pt['prix']) }} FCFA par kilo, source {{ $pt['source'] }}" />
                                    @endforeach

                                    <rect data-zone-survol x="{{ $c['zone']['gauche'] }}" y="{{ $c['zone']['haut'] }}" width="{{ $c['zone']['droite'] - $c['zone']['gauche'] }}" height="{{ $c['zone']['bas'] - $c['zone']['haut'] }}" fill="transparent" style="cursor: crosshair" />
                                </svg>
                                <div data-info-bulle role="status" class="pointer-events-none absolute z-10 hidden max-w-[17rem] rounded-xl border border-[#34251a]/15 bg-[#fffaf1]/95 px-3 py-2 text-xs shadow-lg backdrop-blur-sm"></div>
                            </div>
                            </div>

                            <details class="mt-3 text-sm">
                                <summary class="v-lien inline cursor-pointer">Voir les valeurs en tableau</summary>
                                <table class="mt-2 w-full text-left text-xs">
                                    <thead><tr class="v-texte-doux"><th class="py-1 pr-3 font-medium">Date d'effet</th><th class="py-1 pr-3 text-right font-medium">Prix (FCFA/kg)</th><th class="py-1 font-medium">Source</th></tr></thead>
                                    <tbody>
                                        @foreach ($g['serie']['points'] as $pt)
                                            <tr class="border-t border-[#34251a]/10">
                                                <td class="py-1 pr-3 tabular-nums">{{ $pt['date']->format('d/m/Y') }}@if ($pt['report']) <span class="v-texte-doux">(déjà en vigueur)</span>@endif</td>
                                                <td class="py-1 pr-3 text-right tabular-nums">{{ Format::entier($pt['prix']) }}</td>
                                                <td class="py-1">@if ($pt['url'])<a href="{{ $pt['url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $pt['source'] }}</a>@else{{ $pt['source'] }}@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </details>
                        @endif

                        @if (count($g['campagnes']) > 0)
                            <div class="mt-5">
                                <h3 class="text-sm font-semibold">Les {{ count($g['campagnes']) > 1 ? count($g['campagnes']).' dernières campagnes' : 'campagnes connues' }}, une à une</h3>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="w-full text-left text-xs">
                                        <thead><tr class="v-texte-doux"><th class="py-1 pr-3 font-medium">Campagne</th><th class="py-1 pr-3 text-right font-medium">Début</th><th class="py-1 pr-3 text-right font-medium">Fin</th><th class="py-1 pr-3 text-right font-medium">Plus bas</th><th class="py-1 pr-3 text-right font-medium">Plus haut</th><th class="py-1 pr-3 text-right font-medium">Évolution pendant</th><th class="py-1 text-right font-medium">Depuis la précédente</th></tr></thead>
                                        <tbody>
                                            @foreach ($g['campagnes'] as $l)
                                                <tr class="border-t border-[#34251a]/10">
                                                    <td class="py-1 pr-3"><a href="{{ route('prix.evolution', ['produit' => $g['produit']->id, 'periode' => 'campagne-'.$l['campagne']->id]) }}" class="underline">{{ $l['campagne']->code }}</a></td>
                                                    <td class="py-1 pr-3 text-right tabular-nums">{{ Format::entier($l['resume']['premier']) }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums">{{ Format::entier($l['resume']['dernier']) }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums">{{ Format::entier($l['resume']['min']) }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums">{{ Format::entier($l['resume']['max']) }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums {{ $l['resume']['variation'] > 0 ? 'text-emerald-700' : ($l['resume']['variation'] < 0 ? 'text-red-700' : '') }}">{{ $l['resume']['variation'] === 0 ? '—' : (($l['resume']['variation'] > 0 ? '+' : '−').Format::entier(abs($l['resume']['variation']))) }}</td>
                                                    @php($dp = $l['depuis_precedente'])
                                                    <td class="py-1 text-right tabular-nums {{ $dp === null ? '' : ($dp > 0 ? 'text-emerald-700' : ($dp < 0 ? 'text-red-700' : '')) }}">{{ $dp === null ? '—' : ($dp === 0 ? '=' : (($dp > 0 ? '▲ +' : '▼ −').Format::entier(abs($dp)))) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
            </div>
        @endif
    </section>

    @verbatim
    <script>
        (function () {
            // Recherche dans le tableau des cultures, à chaque lettre tapée ; sans tenir compte des accents.
            var recherche = document.querySelector('[data-recherche-culture]');
            if (recherche) {
                var sansAccent = function (t) { return t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
                var lignes = Array.prototype.slice.call(document.querySelectorAll('[data-tableau-cultures] tbody tr'));
                var compte = document.querySelector('[data-recherche-compte]');
                var vide = document.querySelector('[data-recherche-vide]');
                recherche.addEventListener('input', function () {
                    var terme = sansAccent(recherche.value.trim());
                    var n = 0;
                    lignes.forEach(function (tr) {
                        var visible = terme === '' || sansAccent(tr.dataset.culture).indexOf(terme) !== -1;
                        tr.hidden = !visible;
                        n += visible ? 1 : 0;
                    });
                    compte.textContent = terme === '' ? '' : n + (n > 1 ? ' cultures' : ' culture');
                    vide.classList.toggle('hidden', n > 0);
                });
            }

            // Filtres des courbes : appliqués dès qu'on choisit ; seule la zone des courbes est rechargée.
            var zone = document.querySelector('[data-zone-courbes]');
            var enCours = null;
            function brancherFiltres() {
                var form = zone.querySelector('[data-filtres-prix]');
                var produit = form.querySelector('#produit');
                var periode = form.querySelector('#periode');
                var dates = form.querySelector('[data-dates-perso]');
                form.addEventListener('submit', function (e) { e.preventDefault(); rafraichir(form); });
                produit.addEventListener('change', function () {
                    // Une campagne appartient à un produit : on revient à « toute la période » en changeant de produit.
                    if (periode.value.indexOf('campagne-') === 0) { periode.value = 'tout'; }
                    rafraichir(form);
                });
                periode.addEventListener('change', function () {
                    dates.hidden = periode.value !== 'perso';
                    if (periode.value !== 'perso') { rafraichir(form); }
                });
                dates.querySelectorAll('input').forEach(function (champ) {
                    champ.addEventListener('change', function () {
                        if (form.querySelector('#du').value && form.querySelector('#au').value) { rafraichir(form); }
                    });
                });
            }
            function rafraichir(form) {
                var params = new URLSearchParams(new FormData(form));
                if (params.get('periode') !== 'perso') { params.delete('du'); params.delete('au'); }
                if (!params.get('produit')) { params.delete('produit'); }
                var url = form.getAttribute('action').split('#')[0] + '?' + params.toString();
                var focus = document.activeElement && document.activeElement.id;
                var etat = form.querySelector('[data-filtres-etat]');
                etat.textContent = 'Mise à jour…';
                zone.style.opacity = '0.55';
                if (enCours) { enCours.abort(); }
                enCours = new AbortController();
                fetch(url, { signal: enCours.signal, headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
                    .then(function (html) {
                        var neuve = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-zone-courbes]');
                        if (!neuve) { throw new Error('zone'); }
                        zone.innerHTML = neuve.innerHTML;
                        history.replaceState(null, '', url + '#courbes');
                        zone.style.opacity = '';
                        brancherFiltres();
                        brancherCourbes(zone);
                        if (focus && document.getElementById(focus)) { document.getElementById(focus).focus(); }
                    })
                    .catch(function (err) {
                        if (err.name === 'AbortError') { return; }
                        // En cas d'échec, on recharge la page entière : le filtre s'applique quand même.
                        window.location.href = url + '#courbes';
                    });
            }
            if (zone) { brancherFiltres(); }

            brancherCourbes(document);
        })();

        // Réticule + info-bulle : la souris ou le doigt n'a pas à viser la ligne, seulement une date.
        // Au clavier (Tab), chaque changement de prix donne la même info-bulle.
        function brancherCourbes(racine) {
            racine.querySelectorAll('[data-courbe]').forEach(function (bloc) {
                var svg = bloc.querySelector('svg');
                var bulle = bloc.querySelector('[data-info-bulle]');
                var reticule = svg.querySelector('[data-reticule]');
                var ligne = svg.querySelector('[data-reticule-ligne]');
                var point = svg.querySelector('[data-reticule-point]');
                var zone = svg.querySelector('[data-zone-survol]');
                var cibles = Array.prototype.slice.call(svg.querySelectorAll('circle[data-prix]'));
                var g = parseFloat(svg.dataset.zoneG), d = parseFloat(svg.dataset.zoneD);

                function remplir(cible) {
                    bulle.textContent = '';
                    [['font-semibold text-sm text-[#24201a]', cible.dataset.prix], ['text-[#5c4632]', cible.dataset.date], ['mt-1 text-[#7a6450]', cible.dataset.source]].forEach(function (l) {
                        var s = document.createElement('span');
                        s.className = 'block ' + l[0];
                        s.textContent = l[1];
                        bulle.appendChild(s);
                    });
                }
                function montrer(cible, x) {
                    var y = parseFloat(cible.dataset.y);
                    remplir(cible);
                    ligne.setAttribute('x1', x); ligne.setAttribute('x2', x);
                    point.setAttribute('cx', x); point.setAttribute('cy', y);
                    reticule.setAttribute('visibility', 'visible');
                    var r = svg.getBoundingClientRect();
                    var echelle = r.width / svg.viewBox.baseVal.width;
                    bulle.classList.remove('hidden');
                    var l = bulle.offsetWidth;
                    bulle.style.left = Math.max(0, Math.min(x * echelle - l / 2, r.width - l)) + 'px';
                    bulle.style.top = Math.max(0, y * echelle - bulle.offsetHeight - 16) + 'px';
                }
                function cacher() { bulle.classList.add('hidden'); reticule.setAttribute('visibility', 'hidden'); }
                // Prix en vigueur à l'abscisse x : le dernier changement à gauche (ou le premier).
                function enVigueur(x) {
                    var choisi = cibles[0];
                    cibles.forEach(function (c) { if (parseFloat(c.dataset.x) <= x) { choisi = c; } });
                    return choisi;
                }
                zone.addEventListener('pointermove', function (e) {
                    var r = svg.getBoundingClientRect();
                    var x = Math.max(g, Math.min(d, (e.clientX - r.left) * svg.viewBox.baseVal.width / r.width));
                    montrer(enVigueur(x), x);
                });
                zone.addEventListener('pointerleave', cacher);
                cibles.forEach(function (c) {
                    c.addEventListener('focus', function () { montrer(c, parseFloat(c.dataset.x)); });
                    c.addEventListener('blur', cacher);
                });
            });
        }
    </script>
    @endverbatim
</x-vitrine.page>
