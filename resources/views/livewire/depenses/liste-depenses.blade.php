<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-xl font-semibold">Dépenses</h1>
        <div class="flex flex-wrap items-end gap-3 text-sm">
            <div>
                <label for="filtreStatut" class="mb-1 block text-stone-600">Statut</label>
                <select wire:model.live="filtreStatut" id="filtreStatut" class="rounded-md border border-stone-300 px-3 py-1.5 focus:border-emerald-600 focus:outline-none">
                    <option value="">Tous</option>
                    @foreach ($statuts as $s)
                        <option value="{{ $s->value }}">{{ $s->libelle() }}</option>
                    @endforeach
                </select>
            </div>
            @can('saisir-depenses')
                <a href="{{ route('depenses.nouvelle') }}" class="rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800">Nouvelle dépense</a>
            @endcan
        </div>
    </div>

    @if ($statut !== '')
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ $statut }}</p>
    @endif
    @error('action')
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    @if ($aRefuser !== null)
        <form wire:submit="refuser" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-5">
            <label for="motifRefus" class="block text-sm font-medium text-red-950">Motif du refus</label>
            <input wire:model="motifRefus" id="motifRefus" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
            @error('motifRefus') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-3">
                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Refuser</button>
                <button type="button" wire:click="annulerRefus" class="rounded-md px-4 py-2 text-sm text-stone-700 hover:bg-red-100">Annuler</button>
            </div>
        </form>
    @endif

    @if ($aAnnuler !== null)
        <form wire:submit="annulerDepense" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-5">
            <p class="text-sm font-semibold text-red-950">Supprimer cette dépense</p>
            <p class="mt-1 text-sm text-red-900">Elle restera visible, marquée « Annulée ». Si elle était payée, l'argent revient dans la caisse.</p>
            <label for="motifAnnulation" class="mt-3 block text-sm font-medium text-red-950">Pourquoi ? (obligatoire)</label>
            <input wire:model="motifAnnulation" id="motifAnnulation" type="text" placeholder="Ex. dépense saisie deux fois" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
            @error('motifAnnulation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-3">
                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Supprimer la dépense</button>
                <button type="button" wire:click="$set('aAnnuler', null)" class="rounded-md px-4 py-2 text-sm text-stone-700 hover:bg-red-100">Garder</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-stone-50 text-left text-stone-600">
                <tr>
                    <th class="px-4 py-3 font-medium">Date</th>
                    <th class="px-4 py-3 font-medium">Bénéficiaire</th>
                    <th class="px-4 py-3 font-medium">Catégorie</th>
                    <th class="px-4 py-3 font-medium">Compte</th>
                    <th class="px-4 py-3 text-right font-medium">Montant</th>
                    <th class="px-4 py-3 font-medium">Saisie par</th>
                    <th class="px-4 py-3 font-medium">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($depenses as $d)
                    <tr wire:key="depense-{{ $d->id }}">
                        <td class="whitespace-nowrap px-4 py-3">{{ $d->date_depense->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            {{ $d->beneficiaire }}
                            @if ($d->description) <span class="block text-xs text-stone-500">{{ $d->description }}</span> @endif
                        </td>
                        <td class="px-4 py-3">{{ $d->categorie->nom }}</td>
                        <td class="px-4 py-3">{{ $d->compte->nom }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ \App\Support\Format::fcfa($d->montant_fcfa) }}</td>
                        <td class="px-4 py-3">{{ $d->auteur->nom }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs',
                                'bg-amber-50 text-amber-800' => $d->statut->value === 'a_valider',
                                'bg-emerald-50 text-emerald-800' => $d->statut->value === 'payee',
                                'bg-red-50 text-red-800' => $d->statut->value === 'refusee',
                                'bg-stone-100 text-stone-600' => $d->statut->value === 'annulee',
                            ])>{{ $d->statut->libelle() }}</span>
                            @if ($d->validateur)
                                {{-- « validée par » même si la dépense a ensuite été annulée : c'est la validation que l'on nomme. --}}
                                <span class="block text-xs text-stone-500">{{ $d->statut->value === 'refusee' ? 'refusée' : 'validée' }} par {{ $d->validateur->nom }}</span>
                            @endif
                            @if ($d->motif_refus)
                                <span class="block text-xs text-red-700">{{ $d->motif_refus }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('depenses.justificatif', $d) }}" target="_blank" class="text-xs text-emerald-800 hover:underline">Justificatif</a>
                            @if ($peutValider && $d->statut->value === 'a_valider')
                                @if ($d->cree_par === $moi)
                                    <span class="ml-2 text-xs text-stone-500" title="Qui saisit ne valide pas">(à valider par un autre)</span>
                                @else
                                    <button type="button" wire:click="valider('{{ $d->id }}')" wire:confirm="Valider et payer {{ \App\Support\Format::fcfa($d->montant_fcfa) }} à {{ $d->beneficiaire }} ?"
                                        class="ml-2 rounded-md bg-emerald-700 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-800">Valider</button>
                                    <button type="button" wire:click="preparerRefus('{{ $d->id }}')" class="ml-1 rounded-md px-2 py-1 text-xs text-red-800 hover:bg-red-50">Refuser</button>
                                @endif
                            @endif
                            @if (($estDirection || $d->cree_par === $moi) && in_array($d->statut->value, ['a_valider', 'payee'], true))
                                <a href="{{ route('depenses.nouvelle', ['corrige' => $d->id]) }}" class="ml-1 rounded-md px-2 py-1 text-xs text-stone-700 hover:bg-stone-100">Modifier</a>
                                <button type="button" wire:click="preparerAnnulation('{{ $d->id }}')" class="ml-1 rounded-md px-2 py-1 text-xs text-red-800 hover:bg-red-50">Supprimer</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-stone-500">Aucune dépense.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $depenses->links() }}</div>
</div>
