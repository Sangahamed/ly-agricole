@php use App\Support\Format; @endphp

<div class="mx-auto max-w-3xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('ventes') }}" class="text-sm text-stone-500 hover:underline">← Ventes</a>
            <h1 class="mt-1 text-xl font-semibold">Vente {{ $vente->reference }}</h1>
            <p class="text-sm text-stone-600">{{ $vente->acheteur_nom }} · {{ $vente->type_acheteur->libelle() }} · {{ $vente->date_vente->format('d/m/Y H:i') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($peutAnnuler)
                <a href="{{ route('ventes.nouvelle', ['corrige' => $vente->id]) }}" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-800 hover:bg-stone-50">Modifier</a>
                <button type="button" wire:click="ouvrirAnnulation" class="rounded-md px-3 py-1.5 text-sm text-red-800 hover:bg-red-50">Supprimer</button>
            @endif
            <span class="rounded-full px-3 py-1 text-xs font-medium
                @class(['bg-emerald-100 text-emerald-900' => $vente->statut->value === 'valide', 'bg-amber-100 text-amber-900' => $vente->statut->value === 'a_valider', 'bg-red-100 text-red-900' => in_array($vente->statut->value, ['refuse', 'annule'], true)])">
                {{ $vente->statut->libelle() }}
            </span>
        </div>
    </div>

    @if ($annulationOuverte)
        <form wire:submit="annulerVente" class="rounded-md border border-red-200 bg-red-50 p-4">
            <p class="text-sm text-red-950">La vente passe « annulée » : les encaissements sont contre-passés et les kilos reviennent dans le lot. Elle reste visible avec qui l'a annulée et pourquoi.</p>
            <label for="motifAnnulation" class="mt-3 block text-sm font-medium text-red-950">Motif de l'annulation</label>
            <input wire:model="motifAnnulation" id="motifAnnulation" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
            @error('motifAnnulation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Supprimer la vente</button>
                <button type="button" wire:click="$set('annulationOuverte', false)" class="rounded-md px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Garder</button>
            </div>
        </form>
    @endif

    @if (session('statut'))
        <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ session('statut') }}</p>
    @endif
    @if ($vente->motif_annulation)
        <p class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800" id="annulation">Annulée par {{ $vente->annuleur?->nom }}, le {{ $vente->annule_at?->format('d/m/Y') }} : {{ $vente->motif_annulation }}</p>
    @endif
    @if ($vente->motif_refus)
        <p class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">Refusée : {{ $vente->motif_refus }}</p>
    @endif

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Poids vendu</p>
            <p class="mt-1 font-semibold tabular-nums">{{ Format::kg($vente->poids_net_g) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Prix au kilo</p>
            <p class="mt-1 font-semibold tabular-nums">{{ Format::fcfa($vente->prix_kg_fcfa) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Montant</p>
            <p class="mt-1 font-semibold tabular-nums">{{ Format::fcfa($vente->montant_fcfa) }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs text-stone-500">Reste à encaisser</p>
            <p class="mt-1 font-semibold tabular-nums {{ $vente->resteAEncaisser() > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                {{ Format::fcfa($vente->resteAEncaisser()) }}
            </p>
        </div>
    </div>

    @can('gerer-stock')
    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold">Lot</h2>
            <a href="{{ route('lots.fiche', $vente->lot) }}" class="text-sm text-emerald-800 hover:underline">{{ $vente->lot->code }} →</a>
        </div>
        <p class="text-sm text-stone-500">Marge du lot (toutes ventes validées, tous achats validés) :</p>
        <dl class="mt-2 grid grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-stone-500">Coût (achats)</dt>
                <dd class="font-medium tabular-nums">{{ Format::fcfa($marge['cout']) }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Revenu (ventes)</dt>
                <dd class="font-medium tabular-nums">{{ Format::fcfa($marge['revenu']) }}</dd>
            </div>
            <div>
                <dt class="text-stone-500">Marge</dt>
                <dd class="font-semibold tabular-nums {{ $marge['marge'] >= 0 ? 'text-emerald-700' : 'text-red-700' }}">{{ Format::fcfa($marge['marge']) }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-xs text-stone-400">
            Frais de transport, taxes et commissions à la revente non compris (pas encore rattachés au lot) : marge estimée haute.
        </p>
    </div>
    @endcan

    <div class="rounded-xl border border-stone-200 bg-white p-5">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold">Encaissements</h2>
            @can('encaisser-ventes')
                @if ($vente->resteAEncaisser() > 0 && ! $formulaireEncaissement)
                    <button type="button" wire:click="ouvrirEncaissement" class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800">
                        Encaisser
                    </button>
                @endif
            @endcan
        </div>

        @if ($formulaireEncaissement)
            <form wire:submit="encaisser" class="mb-5 space-y-4 rounded-lg border border-stone-200 bg-stone-50 p-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">Compte</label>
                        <select wire:model="compteId" id="compteId" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                            <option value="">— Choisir —</option>
                            @foreach ($comptes as $c)
                                <option value="{{ $c->id }}">{{ $c->nom }}</option>
                            @endforeach
                        </select>
                        @error('compteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="montantEncaisse" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                        <input wire:model="montantEncaisse" id="montantEncaisse" type="text" inputmode="numeric"
                            placeholder="{{ $vente->resteAEncaisser() }}" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                        @error('montantEncaisse') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="dateEncaissement" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                        <input wire:model="dateEncaissement" id="dateEncaissement" type="date" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                        @error('dateEncaissement') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="referencePaiement" class="mb-1 block text-sm font-medium text-stone-700">Référence (optionnel)</label>
                        <input wire:model="referencePaiement" id="referencePaiement" type="text" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" wire:click="$set('formulaireEncaissement', false)" class="text-sm text-stone-600 hover:underline">Annuler</button>
                    <button type="submit" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Enregistrer</button>
                </div>
            </form>
        @endif

        @if ($aContrePasser !== null)
            <form wire:submit="contrePasser" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
                <label for="motifContrePassation" class="block text-sm font-medium text-red-950">Motif de la contre-passation</label>
                <input wire:model="motifContrePassation" id="motifContrePassation" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
                @error('motifContrePassation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <div class="mt-3 flex justify-end gap-3">
                    <button type="button" wire:click="$set('aContrePasser', null)" class="text-sm text-stone-600 hover:underline">Annuler</button>
                    <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Contre-passer</button>
                </div>
            </form>
        @endif

        @if ($vente->encaissements->isEmpty())
            <p class="text-sm text-stone-500">Aucun encaissement pour l'instant.</p>
        @else
            <ul class="divide-y divide-stone-100 text-sm">
                @foreach ($vente->encaissements->sortByDesc('id') as $e)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <span class="font-medium tabular-nums {{ $e->montant_fcfa < 0 ? 'text-red-700' : '' }}">{{ Format::fcfa($e->montant_fcfa) }}</span>
                            <span class="ml-2 text-stone-500">{{ $e->compte->nom }} · {{ $e->date_encaissement->format('d/m/Y') }}</span>
                            @if ($e->reference_paiement) <span class="ml-2 text-xs text-stone-400">réf. {{ $e->reference_paiement }}</span> @endif
                            @if ($e->motif) <span class="block text-xs text-stone-500">{{ $e->motif }}</span> @endif
                        </div>
                        @can('encaisser-ventes')
                            @if ($e->montant_fcfa > 0 && ! $e->contrePassation)
                                <button type="button" wire:click="preparerContrePassation({{ $e->id }})" class="shrink-0 rounded-md px-2 py-1 text-xs text-red-800 hover:bg-red-50">
                                    Contre-passer
                                </button>
                            @endif
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
