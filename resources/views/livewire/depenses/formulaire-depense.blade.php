@php($champ = 'block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none')
<div>
    <div class="mb-6">
        <a href="{{ route('depenses') }}" class="text-sm text-emerald-800 hover:underline">← Dépenses</a>
        <h1 class="mt-2 text-xl font-semibold">{{ $depenseCorrigee ? 'Modifier la dépense' : 'Nouvelle dépense' }}</h1>
        @if ($depenseCorrigee)
            <p class="mt-1 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">À l'enregistrement, l'ancienne dépense passe « annulée » (son paiement est contre-passé) et celle-ci la remplace. Le justificatif reste celui d'avant si vous n'en joignez pas un nouveau.</p>
        @endif
        <p class="mt-1 text-sm text-stone-600">{{ $regleSeuil }}</p>
    </div>

    <form wire:submit="enregistrer" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        @if ($comptes->isEmpty())
            <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                Aucun compte depuis lequel vous pouvez payer. Un agent paie depuis sa propre caisse : demander à la comptabilité de la créer.
            </p>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="categorieId" class="mb-1 block text-sm font-medium text-stone-700">Catégorie</label>
                <select wire:model="categorieId" id="categorieId" class="{{ $champ }}">
                    <option value="">— Choisir —</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->nom }}{{ $c->exclue_fonds_campagne ? ' (hors fonds de campagne)' : '' }}</option>
                    @endforeach
                </select>
                @error('categorieId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">Payée depuis</label>
                <select wire:model="compteId" id="compteId" class="{{ $champ }}">
                    <option value="">— Choisir —</option>
                    @foreach ($comptes as $c)
                        <option value="{{ $c->id }}">{{ $c->nom }}</option>
                    @endforeach
                </select>
                @error('compteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="montant" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                <input wire:model="montant" id="montant" type="text" inputmode="numeric" placeholder="25 000" class="{{ $champ }} text-right">
                @error('montant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="dateDepense" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                <input wire:model="dateDepense" id="dateDepense" type="date" class="{{ $champ }}">
                @error('dateDepense') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="beneficiaire" class="mb-1 block text-sm font-medium text-stone-700">Bénéficiaire</label>
                <input wire:model="beneficiaire" id="beneficiaire" type="text" class="{{ $champ }}">
                @error('beneficiaire') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="campagneId" class="mb-1 block text-sm font-medium text-stone-700">Rattachée à la campagne <span class="font-normal text-stone-500">(facultatif)</span></label>
                <select wire:model="campagneId" id="campagneId" class="{{ $champ }}">
                    <option value="">— Aucune —</option>
                    @foreach ($campagnes as $c)
                        <option value="{{ $c->id }}">{{ $c->produit->nom }} {{ $c->code }}</option>
                    @endforeach
                </select>
                @error('campagneId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="description" class="mb-1 block text-sm font-medium text-stone-700">Description <span class="font-normal text-stone-500">(facultatif)</span></label>
                <input wire:model="description" id="description" type="text" class="{{ $champ }}">
                @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="justificatif" class="mb-1 block text-sm font-medium text-stone-700">Justificatif (photo du reçu ou PDF)</label>
                <input wire:model="justificatif" id="justificatif" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="block text-sm">
                <div wire:loading wire:target="justificatif" class="mt-1 text-sm text-stone-500">Envoi du fichier…</div>
                @error('justificatif') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer</button>
            <a href="{{ route('depenses') }}" class="rounded-md px-4 py-2 text-stone-700 hover:bg-stone-100">Annuler</a>
        </div>
    </form>
</div>
