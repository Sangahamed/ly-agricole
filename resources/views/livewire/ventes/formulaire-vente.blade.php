<div class="mx-auto max-w-2xl">
    <h1 class="mb-6 text-xl font-semibold">{{ $venteCorrigee ? 'Modifier la vente '.$venteCorrigee->reference : 'Nouvelle vente' }}</h1>
    @if ($venteCorrigee)
        <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">À l'enregistrement, la vente {{ $venteCorrigee->reference }} passe « annulée » (ses kilos reviennent dans le lot, ses encaissements sont contre-passés) et celle-ci la remplace. L'ancienne reste visible.</p>
    @endif

    @error('vente')
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <form wire:submit="enregistrer" class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <div>
            <label for="campagneId" class="mb-1 block text-sm font-medium text-stone-700">Campagne</label>
            <select wire:model.live="campagneId" id="campagneId" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @if ($campagne)
                    <option value="{{ $campagne->id }}">{{ $campagne->produit->nom }} · {{ $campagne->code }}</option>
                @endif
            </select>
            @error('campagneId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="lotId" class="mb-1 block text-sm font-medium text-stone-700">Lot</label>
            <select wire:model="lotId" id="lotId" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                <option value="">— Choisir —</option>
                @foreach ($lots as $l)
                    <option value="{{ $l->id }}">{{ $l->code }} · {{ \App\Support\Format::kg($l->stock()) }} disponibles · {{ $l->magasin->nom }}</option>
                @endforeach
            </select>
            @error('lotId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            @if ($lots->isEmpty())
                <p class="mt-1 text-sm text-amber-700">Aucun lot avec du stock sur cette campagne.</p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="typeAcheteur" class="mb-1 block text-sm font-medium text-stone-700">Type d'acheteur</label>
                <select wire:model="typeAcheteur" id="typeAcheteur" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                    @foreach ($typesAcheteur as $t)
                        <option value="{{ $t->value }}">{{ $t->libelle() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="acheteurNom" class="mb-1 block text-sm font-medium text-stone-700">Nom de l'acheteur</label>
                <input wire:model="acheteurNom" id="acheteurNom" type="text" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @error('acheteurNom') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="dateVente" class="mb-1 block text-sm font-medium text-stone-700">Date de la vente</label>
            <input wire:model="dateVente" id="dateVente" type="datetime-local" class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
            @error('dateVente') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="poidsKg" class="mb-1 block text-sm font-medium text-stone-700">Poids vendu (kg)</label>
                <input wire:model.live="poidsKg" id="poidsKg" type="text" inputmode="decimal" placeholder="500,000"
                    class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @error('poidsKg') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                @if ($apercu['stockDisponible'] !== null)
                    <p class="mt-1 text-xs text-stone-500">{{ \App\Support\Format::kg($apercu['stockDisponible']) }} disponibles dans ce lot.</p>
                @endif
            </div>
            <div>
                <label for="prixKg" class="mb-1 block text-sm font-medium text-stone-700">Prix au kilo (FCFA)</label>
                <input wire:model.live="prixKg" id="prixKg" type="text" inputmode="numeric" placeholder="900"
                    class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
                @error('prixKg') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="qualiteAcceptee" class="mb-1 block text-sm font-medium text-stone-700">Qualité acceptée (optionnel)</label>
            <input wire:model="qualiteAcceptee" id="qualiteAcceptee" type="text" placeholder="Conforme au contrat, KOR 48"
                class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:outline-none">
        </div>

        @if ($apercu['montant'] !== null)
            <p class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                Montant de la vente : <span class="font-semibold">{{ \App\Support\Format::fcfa($apercu['montant']) }}</span>
                — l'encaissement se fait séparément, en une ou plusieurs fois.
            </p>
        @endif

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('ventes') }}" class="text-sm text-stone-600 hover:underline">Annuler</a>
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-emerald-700 px-5 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">
                Enregistrer la vente
            </button>
        </div>
    </form>
</div>
