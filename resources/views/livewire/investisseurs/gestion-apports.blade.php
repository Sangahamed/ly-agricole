@php use App\Support\Format; @endphp

<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-xl font-semibold">Apports de campagne</h1>
        <div class="flex flex-wrap items-end gap-3 text-sm">
            <div>
                <label for="campagneId" class="mb-1 block text-stone-600">Campagne</label>
                <select wire:model.live="campagneId" id="campagneId" class="rounded-md border border-stone-300 px-3 py-1.5 focus:border-emerald-600 focus:outline-none">
                    @foreach ($campagnes as $c)
                        <option value="{{ $c->id }}">{{ $c->produit->nom }} · {{ $c->code }}</option>
                    @endforeach
                </select>
            </div>
            @if ($campagne && $comptesDedies->isNotEmpty())
                <button type="button" wire:click="ouvrir" class="rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800">Nouvel apport</button>
            @endif
        </div>
    </div>

    @if ($statut !== '')
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ $statut }}</p>
    @endif

    @if ($campagne && $comptesDedies->isEmpty())
        <p class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
            Aucun compte de trésorerie n'est dédié à cette campagne (contrat art. 5). Créez-en un depuis la Trésorerie avant d'enregistrer un apport.
        </p>
    @endif

    @if ($formulaire)
        <form wire:submit="enregistrer" class="mb-6 space-y-4 rounded-xl border border-stone-200 bg-white p-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="investisseurId" class="mb-1 block text-sm font-medium text-stone-700">Investisseur</label>
                    {{-- Liste ET saisie : choisir, ou taper un nom (investisseur sans compte). --}}
                    <select wire:model="investisseurId" id="investisseurId" data-recherche-creer class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                        <option value="">— Apport de LY elle-même —</option>
                        @foreach ($investisseurs as $i)
                            <option value="{{ $i->id }}">{{ $i->nom }}</option>
                        @endforeach
                        @foreach ($nomsSansCompte as $nom)
                            <option value="{{ $nom }}">{{ $nom }} (sans compte)</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-stone-500">Pas dans la liste ? Tapez son nom : il est enregistré sans compte de connexion.</p>
                    @error('investisseurId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">Compte</label>
                    <select wire:model="compteId" id="compteId" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                        <option value="">— Choisir —</option>
                        @foreach ($comptesDedies as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}{{ $campagne && $c->campagne_id === $campagne->id ? ' — compte de la campagne' : '' }}</option>
                        @endforeach
                    </select>
                    @error('compteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="montant" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                    <input wire:model="montant" id="montant" type="text" inputmode="numeric" placeholder="3 000 000" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                    @error('montant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="dateApport" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                    <input wire:model="dateApport" id="dateApport" type="date" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                    @error('dateApport') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="motif" class="mb-1 block text-sm font-medium text-stone-700">Motif / référence (optionnel)</label>
                <input wire:model="motif" id="motif" type="text" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$set('formulaire', false)" class="text-sm text-stone-600 hover:underline">Annuler</button>
                <button type="submit" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Enregistrer</button>
            </div>
        </form>
    @endif

    @if ($aContrePasser !== null)
        <form wire:submit="contrePasser" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-5">
            <label for="motifContrePassation" class="block text-sm font-medium text-red-950">Motif de la contre-passation</label>
            <input wire:model="motifContrePassation" id="motifContrePassation" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
            @error('motifContrePassation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex justify-end gap-3">
                <button type="button" wire:click="$set('aContrePasser', null)" class="text-sm text-stone-600 hover:underline">Annuler</button>
                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Contre-passer</button>
            </div>
        </form>
    @endif

    @if ($campagne && $repartition)
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Apports des investisseurs</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ Format::fcfa($repartition['parInvestisseurs']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Apport propre de LY</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ Format::fcfa($repartition['parLy']) }}</p>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-4">
                <p class="text-xs text-stone-500">Total collecté</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ Format::fcfa($repartition['parInvestisseurs'] + $repartition['parLy']) }}</p>
            </div>
        </div>

        @if ($repartition['lignes']->isNotEmpty())
            <div class="mb-6 rounded-xl border border-stone-200 bg-white p-5">
                <h2 class="mb-3 font-semibold">Part de chaque investisseur</h2>
                <p class="mb-3 text-xs text-stone-500">
                    Part de l'ensemble des apports d'investisseurs — pas une quote-part du résultat (contrat art. 10 à 14, à coder dès que le texte exact sera disponible).
                </p>
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($repartition['lignes'] as $ligne)
                        <li class="flex items-center justify-between gap-4 py-2">
                            <span>{{ $ligne['nom'] }}</span>
                            <span class="tabular-nums">{{ Format::fcfa($ligne['montant']) }}
                                <span class="ml-2 text-xs text-stone-500">({{ intdiv($ligne['part_pour_mille'], 10) }},{{ str_pad((string) ($ligne['part_pour_mille'] % 10), 1, '0', STR_PAD_LEFT) }} %)</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif

    @if ($campagne)
        <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Investisseur</th>
                        <th class="px-4 py-3 text-right font-medium">Montant</th>
                        <th class="px-4 py-3 font-medium">Motif</th>
                        <th class="px-4 py-3 font-medium">Par</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($apports as $a)
                        <tr wire:key="apport-{{ $a->id }}">
                            <td class="whitespace-nowrap px-4 py-3">{{ $a->date_apport->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $a->nomApporteur() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums {{ $a->montant_fcfa < 0 ? 'text-red-700' : '' }}">{{ Format::fcfa($a->montant_fcfa) }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $a->motif ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ $a->auteur->nom }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if ($a->montant_fcfa > 0 && ! $a->contrePassation)
                                    <button type="button" wire:click="preparerContrePassation({{ $a->id }})" class="rounded-md px-2 py-1 text-xs text-red-800 hover:bg-red-50">Contre-passer</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-stone-500">Aucun apport sur cette campagne.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
