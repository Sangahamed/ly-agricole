<x-layouts.app :title="$producteur->nomComplet()">
    <div class="mb-6">
        <a href="{{ route('producteurs') }}" class="text-sm text-emerald-800 hover:underline">← Producteurs</a>
    </div>

    @if (session('statut'))
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ session('statut') }}</p>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-6">
        <div class="flex items-start gap-5">
            @if ($producteur->photo)
                <img src="{{ route('producteurs.photo', $producteur) }}" alt="Photo de {{ $producteur->nomComplet() }}"
                    class="h-28 w-28 rounded-lg object-cover">
            @else
                <div class="flex h-28 w-28 items-center justify-center rounded-lg bg-stone-200 text-xs text-stone-500">Pas de photo</div>
            @endif

            <div>
                <h1 class="text-xl font-semibold">{{ $producteur->nomComplet() }}</h1>
                <p class="mt-1 font-mono text-sm text-stone-600">{{ $producteur->code }}</p>
                @unless ($producteur->actif)
                    <p class="mt-1 text-sm text-amber-700">Fiche désactivée</p>
                @endunless
            </div>
        </div>

        <div class="flex gap-2">
            @can('gerer-producteurs')
                <a href="{{ route('producteurs.carte', $producteur) }}" target="_blank"
                    class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm text-stone-800 hover:bg-stone-50">Imprimer la carte</a>
                <a href="{{ route('producteurs.modifier', $producteur) }}"
                    class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm text-stone-800 hover:bg-stone-50">Modifier</a>
            @endcan
            @can('annuler-operation', $producteur)
                {{-- Confirmation dans la page (pas de boîte du navigateur). --}}
                <details class="relative">
                    <summary class="cursor-pointer list-none rounded-md border border-red-200 bg-white px-4 py-2 text-sm text-red-800 hover:bg-red-50 [&::-webkit-details-marker]:hidden">Supprimer</summary>
                    <form method="POST" action="{{ route('producteurs.supprimer', $producteur) }}" class="absolute right-0 z-10 mt-2 w-72 rounded-xl border border-red-200 bg-white p-4 text-sm shadow-lg">
                        @csrf
                        @method('DELETE')
                        <p class="text-stone-700">Supprimer définitivement la fiche de {{ $producteur->nomComplet() }}, sa photo et ses parcelles ? Possible seulement si elle n'a encore servi à rien (aucun prêt, achat ni visite).</p>
                        <button type="submit" class="mt-3 w-full rounded-md bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800">Oui, supprimer la fiche</button>
                    </form>
                </details>
            @endcan
        </div>
    </div>

    @if (session('refus'))
        <p class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ session('refus') }}</p>
    @endif

    <dl class="mt-8 grid gap-x-8 gap-y-4 rounded-xl border border-stone-200 bg-white p-6 text-sm shadow-sm sm:grid-cols-2 lg:grid-cols-3">
        <div><dt class="text-stone-500">Sexe</dt><dd>{{ $producteur->sexe?->libelle() ?? '—' }}</dd></div>
        <div><dt class="text-stone-500">Année de naissance</dt><dd>{{ $producteur->annee_naissance ?? '—' }}</dd></div>
        <div><dt class="text-stone-500">Pièce</dt><dd>{{ $producteur->piece_type ? $producteur->piece_type->libelle().' n° '.$producteur->piece_numero : '—' }}</dd></div>
        <div><dt class="text-stone-500">Téléphone</dt><dd>{{ \App\Support\Telephone::afficher($producteur->telephone) }}</dd></div>
        <div><dt class="text-stone-500">Mobile Money</dt><dd>{{ $producteur->numero_mobile_money ? \App\Support\Telephone::afficher($producteur->numero_mobile_money).' ('.$producteur->operateur_mm?->libelle().')' : '—' }}</dd></div>
        <div><dt class="text-stone-500">Village</dt><dd>{{ $producteur->village->nom }} ({{ $producteur->village->zone->nom }})</dd></div>
        <div><dt class="text-stone-500">Groupe</dt><dd>{{ $producteur->groupe?->nom ?? '—' }}</dd></div>
        <div><dt class="text-stone-500">Langue des messages</dt><dd>{{ $producteur->langue?->nom ?? 'Français (par défaut)' }}</dd></div>
        <div class="sm:col-span-2">
            <dt class="text-stone-500">Accord du producteur</dt>
            <dd>Recueilli le {{ $producteur->consentement_at->format('d/m/Y à H:i') }} par {{ $producteur->auteurConsentement->nom }}</dd>
        </div>
    </dl>

    @php($surfaceReleveeM2 = $producteur->parcelles->sum('surface_m2'))
    <section class="mt-8">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">
                Parcelles
                <span class="text-sm font-normal text-stone-500">
                    ({{ $producteur->parcelles->count() }} ; {{ \App\Support\Format::hectares($surfaceReleveeM2) }} relevés)
                </span>
            </h2>
            @can('gerer-producteurs')
                <a href="{{ route('producteurs.parcelles.nouvelle', $producteur) }}"
                    class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Ajouter une parcelle</a>
            @endcan
        </div>

        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Nom</th>
                        <th class="px-4 py-3 font-medium">Culture</th>
                        <th class="px-4 py-3 font-medium">Surface</th>
                        <th class="px-4 py-3 font-medium">Plantation</th>
                        <th class="px-4 py-3 font-medium">Arbres</th>
                        <th class="px-4 py-3 font-medium">Dernière visite</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($producteur->parcelles as $parcelle)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $parcelle->nom }}</td>
                            <td class="px-4 py-3">{{ $parcelle->produit?->nom ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($parcelle->surface_m2 === null)
                                    <span class="text-amber-700">Non relevée</span>
                                @else
                                    {{ \App\Support\Format::hectares($parcelle->surface_m2) }}
                                    @if ($parcelle->contour_origine === 'gps')
                                        <span class="block text-xs text-stone-500">relevé GPS en marchant</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $parcelle->annee_plantation ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $parcelle->nb_arbres ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($parcelle->visites_max_date_visite === null)
                                    <span class="text-stone-500">Jamais</span>
                                @else
                                    {{ \Illuminate\Support\Carbon::parse($parcelle->visites_max_date_visite)->format('d/m/Y') }}
                                    @can('voir-visites')
                                        <a href="{{ route('visites', ['q' => $parcelle->nom]) }}" class="block text-xs text-emerald-800 hover:underline">{{ $parcelle->visites_count }} visite(s)</a>
                                    @endcan
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('gerer-producteurs')
                                    <a href="{{ route('producteurs.parcelles.modifier', [$producteur, $parcelle]) }}" class="text-emerald-800 hover:underline">Modifier</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-stone-500">Aucune parcelle.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
