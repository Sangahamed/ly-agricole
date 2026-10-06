<div class="mx-auto max-w-md">
    <h1 class="text-xl font-semibold">Mon mot de passe</h1>
    <p class="mt-1 mb-6 text-sm text-stone-600">
        Au moins {{ \App\Livewire\Utilisateurs\GestionUtilisateurs::MOT_DE_PASSE_MIN }} caractères. Mot de passe oublié : contactez l'administrateur.
    </p>

    @if ($statut !== '')
        <p class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900" role="status">{{ $statut }}</p>
    @endif

    <form wire:submit="changer" class="space-y-5 rounded-xl border border-stone-200 bg-white p-5">
        <div>
            <label for="actuel" class="mb-1 block text-sm font-medium text-stone-700">Mot de passe actuel</label>
            <input wire:model="actuel" id="actuel" type="password" autocomplete="current-password" required
                class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none">
            @error('actuel')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="nouveau" class="mb-1 block text-sm font-medium text-stone-700">Nouveau mot de passe</label>
            <input wire:model="nouveau" id="nouveau" type="password" autocomplete="new-password" required
                class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none">
            @error('nouveau')
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="nouveau_confirmation" class="mb-1 block text-sm font-medium text-stone-700">Nouveau mot de passe, encore une fois</label>
            <input wire:model="nouveau_confirmation" id="nouveau_confirmation" type="password" autocomplete="new-password" required
                class="block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none">
        </div>

        <button type="submit" wire:loading.attr="disabled"
            class="w-full rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">
            <span wire:loading.remove wire:target="changer">Changer le mot de passe</span>
            <span wire:loading wire:target="changer">Enregistrement…</span>
        </button>
    </form>
</div>
