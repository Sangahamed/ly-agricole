@php($champ = 'block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none')
<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold">Trésorerie</h1>
        <div class="flex flex-wrap gap-2 text-sm">
            <button type="button" wire:click="ouvrir('entree')" class="rounded-md border border-stone-300 bg-white px-3 py-2 hover:bg-stone-50">Entrée d'argent</button>
            <button type="button" wire:click="ouvrir('virement')" class="rounded-md border border-stone-300 bg-white px-3 py-2 hover:bg-stone-50">Virement interne</button>
            <button type="button" wire:click="ouvrir('avance')" class="rounded-md border border-stone-300 bg-white px-3 py-2 hover:bg-stone-50">Avance à un agent</button>
            <button type="button" wire:click="ouvrir('compte')" class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-800">Nouveau compte</button>
        </div>
    </div>

    @if ($statut !== '')
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ $statut }}</p>
    @endif

    @if ($formulaire !== null)
        <form wire:submit="enregistrer" class="mb-8 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold">
                {{ ['compte' => 'Nouveau compte', 'entree' => 'Entrée d\'argent', 'virement' => 'Virement interne', 'avance' => 'Avance à un agent'][$formulaire] }}
            </h2>

            @if ($formulaire === 'compte')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nom" class="mb-1 block text-sm font-medium text-stone-700">Nom</label>
                        <input wire:model="nom" id="nom" type="text" class="{{ $champ }}" placeholder="ex. Caisse centrale Korhogo">
                        @error('nom') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="type" class="mb-1 block text-sm font-medium text-stone-700">Type</label>
                        <select wire:model="type" id="type" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}">{{ $t->libelle() }}</option>
                            @endforeach
                        </select>
                        @error('type') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="titulaireId" class="mb-1 block text-sm font-medium text-stone-700">Caisse d'un agent <span class="font-normal text-stone-500">(facultatif)</span></label>
                        <select wire:model="titulaireId" id="titulaireId" class="{{ $champ }}">
                            <option value="">— Non —</option>
                            @foreach ($agents as $a)
                                <option value="{{ $a->id }}">{{ $a->nom }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-stone-500">Son solde sera ce qui reste à justifier par l'agent.</p>
                        @error('titulaireId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="campagneId" class="mb-1 block text-sm font-medium text-stone-700">Compte dédié à une campagne <span class="font-normal text-stone-500">(facultatif)</span></label>
                        <select wire:model="campagneId" id="campagneId" class="{{ $champ }}">
                            <option value="">— Non —</option>
                            @foreach ($campagnes as $c)
                                <option value="{{ $c->id }}">{{ $c->produit->nom }} {{ $c->code }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-stone-500">Fonds des investisseurs (contrat, art. 5) : les charges exclues (art. 10.3) y sont bloquées.</p>
                        @error('campagneId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">{{ $formulaire === 'entree' ? 'Compte' : 'Depuis le compte' }}</label>
                        <select wire:model.live="compteId" id="compteId" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($comptes->where('actif', true) as $c)
                                <option value="{{ $c->id }}">{{ $c->nom }} ({{ \App\Support\Format::fcfa($soldes[$c->id]) }})</option>
                            @endforeach
                        </select>
                        @error('compteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    @if ($formulaire === 'entree')
                        <div>
                            <label for="nature" class="mb-1 block text-sm font-medium text-stone-700">Nature</label>
                            <select wire:model.live="nature" id="nature" class="{{ $champ }}">
                                <option value="">— Choisir —</option>
                                @foreach ($natures as $valeur => $libelleNature)
                                    <option value="{{ $valeur }}">{{ $libelleNature }}</option>
                                @endforeach
                            </select>
                            @error('nature') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        @if ($nature === 'apport_campagne')
                            <div>
                                <label for="investisseurId" class="mb-1 block text-sm font-medium text-stone-700">Investisseur</label>
                                {{-- Liste ET saisie : choisir, ou taper un nom (investisseur sans compte). --}}
                                <select wire:model="investisseurId" id="investisseurId" data-recherche-creer class="{{ $champ }}">
                                    <option value="">— Choisir ou taper un nom —</option>
                                    @foreach ($investisseurs as $i)
                                        <option value="{{ $i->id }}">{{ $i->nom }}</option>
                                    @endforeach
                                    @foreach ($nomsSansCompte as $nomSansCompte)
                                        <option value="{{ $nomSansCompte }}">{{ $nomSansCompte }} (sans compte)</option>
                                    @endforeach
                                    <option value="ly">LY AGRICOLE (apport propre)</option>
                                </select>
                                @error('investisseurId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-stone-500">L'apport apparaît aussi dans l'onglet Apports et compte dans la part de l'investisseur.</p>
                            </div>
                            <div>
                                <label for="campagneApport" class="mb-1 block text-sm font-medium text-stone-700">Campagne</label>
                                <select wire:model="campagneId" id="campagneApport" class="{{ $champ }}">
                                    <option value="">— Choisir —</option>
                                    @foreach ($campagnes as $camp)
                                        <option value="{{ $camp->id }}">{{ $camp->produit->nom }} {{ $camp->code }}</option>
                                    @endforeach
                                </select>
                                @error('campagneId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @else
                        <div>
                            <label for="compteDestinationId" class="mb-1 block text-sm font-medium text-stone-700">{{ $formulaire === 'avance' ? 'Vers la caisse de l\'agent' : 'Vers le compte' }}</label>
                            <select wire:model="compteDestinationId" id="compteDestinationId" class="{{ $champ }}">
                                <option value="">— Choisir —</option>
                                @foreach ($comptes->where('actif', true) as $c)
                                    @if ($formulaire !== 'avance' || $c->titulaire_id !== null)
                                        <option value="{{ $c->id }}">{{ $c->nom }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @error('compteDestinationId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label for="montant" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                        <input wire:model="montant" id="montant" type="text" inputmode="numeric" class="{{ $champ }} text-right" placeholder="1 500 000">
                        @error('montant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="dateOperation" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                        <input wire:model="dateOperation" id="dateOperation" type="date" class="{{ $champ }}">
                        @error('dateOperation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="libelle" class="mb-1 block text-sm font-medium text-stone-700">Libellé</label>
                        <input wire:model="libelle" id="libelle" type="text" class="{{ $champ }}">
                        @error('libelle') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    @if ($formulaire !== 'avance')
                        <div>
                            <label for="reference" class="mb-1 block text-sm font-medium text-stone-700">Référence <span class="font-normal text-stone-500">(bordereau, n° Wave…)</span></label>
                            <input wire:model="reference" id="reference" type="text" class="{{ $champ }}">
                            @error('reference') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            @endif

            <div class="mt-6 flex gap-3">
                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer</button>
                <button type="button" wire:click="annuler" class="rounded-md px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Annuler</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-stone-50 text-left text-stone-600">
                <tr>
                    <th class="px-4 py-3 font-medium">Compte</th>
                    <th class="px-4 py-3 font-medium">Type</th>
                    <th class="px-4 py-3 font-medium">Titulaire / campagne</th>
                    <th class="px-4 py-3 text-right font-medium">Solde</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($comptes as $c)
                    <tr wire:key="compte-{{ $c->id }}" @class(['text-stone-400' => ! $c->actif])>
                        <td class="px-4 py-3"><a href="{{ route('tresorerie.releve', $c) }}" class="font-medium text-emerald-800 hover:underline">{{ $c->nom }}</a></td>
                        <td class="px-4 py-3">{{ $c->type->libelle() }}</td>
                        <td class="px-4 py-3">
                            @if ($c->titulaire) Caisse de {{ $c->titulaire->nom }} @endif
                            @if ($c->campagne) Campagne {{ $c->campagne->produit->nom }} {{ $c->campagne->code }} @endif
                            @if (! $c->titulaire && ! $c->campagne) — @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ \App\Support\Format::fcfa($soldes[$c->id]) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-stone-500">Aucun compte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
