@php($champ = 'block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none')
<div>
    <div class="mb-6">
        <a href="{{ route('prets') }}" class="text-sm text-emerald-800 hover:underline">← Prêts</a>
        <h1 class="mt-2 text-xl font-semibold">{{ $pretCorrige ? 'Modifier le prêt '.$pretCorrige->reference : 'Nouvelle demande de prêt' }}</h1>
        @if ($pretCorrige)
            <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">À l'enregistrement, le prêt {{ $pretCorrige->reference }} passe « annulé » et celui-ci le remplace (nouvelle référence). L'ancien reste visible.</p>
        @endif
        <p class="mt-1 text-sm text-stone-600">{{ $regleValidation }}</p>
    </div>

    <form wire:submit="enregistrer" class="space-y-6">
        <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="producteurId" class="mb-1 block text-sm font-medium text-stone-700">Producteur</label>
                    <select wire:model.live="producteurId" id="producteurId" class="{{ $champ }}">
                        <option value="">— Choisir —</option>
                        @foreach ($producteurs as $p)
                            <option value="{{ $p->id }}">{{ $p->nomComplet() }} — {{ $p->code }} ({{ $p->village->nom }})</option>
                        @endforeach
                    </select>
                    @error('producteurId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    @if ($caution)
                        <p class="mt-2 rounded-md border px-3 py-2 text-sm {{ $caution['niveau'] === 'blocage' ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-200 bg-amber-50 text-amber-900' }}">{{ $caution['message'] }}</p>
                    @endif
                </div>
                <div>
                    <label for="campagneId" class="mb-1 block text-sm font-medium text-stone-700">Campagne</label>
                    <select wire:model="campagneId" id="campagneId" class="{{ $champ }}">
                        <option value="">— Choisir —</option>
                        @foreach ($campagnes as $c)
                            <option value="{{ $c->id }}">{{ $c->produit->nom }} {{ $c->code }} ({{ $c->statut->libelle() }})</option>
                        @endforeach
                    </select>
                    @error('campagneId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="montant" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                    <input wire:model="montant" id="montant" type="text" inputmode="numeric" placeholder="3 000 000" class="{{ $champ }} text-right">
                    @error('montant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="forme" class="mb-1 block text-sm font-medium text-stone-700">Forme</label>
                    <select wire:model="forme" id="forme" class="{{ $champ }}">
                        <option value="">— Choisir —</option>
                        @foreach ($formes as $f)
                            <option value="{{ $f->value }}">{{ $f->libelle() }}</option>
                        @endforeach
                    </select>
                    @error('forme') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="prixReference" class="mb-1 block text-sm font-medium text-stone-700">Prix de référence (FCFA/kg) <span class="font-normal text-stone-500">(facultatif)</span></label>
                    <input wire:model="prixReference" id="prixReference" type="text" inputmode="numeric" class="{{ $champ }} text-right">
                    <p class="mt-1 text-xs text-stone-500">Sert seulement à estimer les kilos attendus. Le prix appliqué aux livraisons reste à décider (question 3).</p>
                    @error('prixReference') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="echeance" class="mb-1 block text-sm font-medium text-stone-700">Échéance</label>
                    <input wire:model="echeance" id="echeance" type="date" class="{{ $champ }}">
                    @error('echeance') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="mb-3 font-semibold">Parcelles financées</h2>
            @if ($producteurId === '')
                <p class="text-sm text-stone-500">Choisir d'abord le producteur.</p>
            @elseif ($parcelles->isEmpty())
                <p class="text-sm text-stone-500">Ce producteur n'a pas encore de parcelle enregistrée.</p>
            @else
                <div class="space-y-2">
                    @foreach ($parcelles as $parcelle)
                        <label wire:key="parcelle-{{ $parcelle->id }}" class="flex items-center gap-2 text-sm">
                            <input wire:model="parcelleIds" type="checkbox" value="{{ $parcelle->id }}" class="rounded border-stone-300 text-emerald-700">
                            {{ $parcelle->nom }}
                            <span class="text-stone-500">— {{ $parcelle->surface_m2 === null ? 'surface non relevée' : \App\Support\Format::hectares($parcelle->surface_m2) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <label class="flex items-start gap-2 text-sm font-medium text-stone-800">
                <input wire:model.live="partieLiee" type="checkbox" class="mt-0.5 rounded border-stone-300 text-emerald-700">
                Ce producteur est un proche de la direction ou une partie liée (contrat, art. 17.3)
            </label>
            @if ($partieLiee)
                <div class="mt-3">
                    <label for="accordEcrit" class="mb-1 block text-sm font-medium text-stone-700">Accord écrit (PDF ou photo)</label>
                    <input wire:model="accordEcrit" id="accordEcrit" type="file" accept="application/pdf,image/jpeg,image/png" class="block text-sm">
                    @error('accordEcrit') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @endif
        </section>

        <div class="flex gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer la demande</button>
            <a href="{{ route('prets') }}" class="rounded-md px-4 py-2 text-stone-700 hover:bg-stone-100">Annuler</a>
        </div>
    </form>
</div>
