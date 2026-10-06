@php($champ = 'block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none')
<div>
    <div class="mb-6">
        <a href="{{ route('achats') }}" class="text-sm text-emerald-800 hover:underline">← Achats</a>
        <h1 class="mt-2 text-xl font-semibold">{{ $achatCorrige ? 'Modifier l\'achat '.$achatCorrige->reference : 'Nouvel achat bord-champ' }}</h1>
    @if ($achatCorrige)
        <p class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">À l'enregistrement, l'achat {{ $achatCorrige->reference }} passe « annulé » (stock, caisse et prêt remis comme avant) et celui-ci le remplace. L'ancien reste visible.</p>
    @endif
        @if ($campagne)
            <p class="mt-1 text-sm text-stone-600">
                Campagne {{ $campagne->produit->nom }} {{ $campagne->code }} —
                prix officiel : {{ $campagne->prix_officiel_kg_fcfa === null ? 'pas encore annoncé' : $format::fcfa($campagne->prix_officiel_kg_fcfa).'/kg (minimum)' }}
            </p>
        @else
            <p class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">Aucune campagne ouverte : pas d'achat possible.</p>
        @endif
    </div>

    @error('achat')
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800" id="erreur-achat">{{ $message }}</p>
    @enderror

    <form wire:submit="enregistrer" class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">Fournisseur</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="fournisseurType" class="mb-1 block text-sm font-medium text-stone-700">Vendu par</label>
                        <select wire:model.live="fournisseurType" id="fournisseurType" class="{{ $champ }}">
                            @foreach ($typesFournisseur as $t)
                                <option value="{{ $t->value }}">{{ $t->libelle() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($fournisseurType === 'producteur')
                        <div>
                            <label for="producteurId" class="mb-1 block text-sm font-medium text-stone-700">Producteur</label>
                            <select wire:model.live="producteurId" id="producteurId" class="{{ $champ }}">
                                <option value="">— Choisir —</option>
                                @foreach ($producteurs as $p)
                                    <option value="{{ $p->id }}">{{ $p->nomComplet() }} — {{ $p->code }} ({{ $p->village->nom }})</option>
                                @endforeach
                            </select>
                            @error('producteurId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($fournisseurType === 'pisteur')
                        <div>
                            <label for="pisteurId" class="mb-1 block text-sm font-medium text-stone-700">Pisteur</label>
                            <select wire:model="pisteurId" id="pisteurId" class="{{ $champ }}">
                                <option value="">— Choisir —</option>
                                @foreach ($pisteurs as $p)
                                    <option value="{{ $p->id }}">{{ $p->nom }}</option>
                                @endforeach
                            </select>
                            @error('pisteurId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="fournisseurNom" class="mb-1 block text-sm font-medium text-stone-700">Coopérative</label>
                            <input wire:model="fournisseurNom" id="fournisseurNom" type="text" class="{{ $champ }}">
                            @error('fournisseurNom') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="lotId" class="mb-1 block text-sm font-medium text-stone-700">Lot</label>
                        <select wire:model="lotId" id="lotId" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($lots as $l)
                                <option value="{{ $l->id }}">{{ $l->code }} — {{ $l->magasin->nom }}</option>
                            @endforeach
                        </select>
                        @if ($lots->isEmpty()) <p class="mt-1 text-xs text-amber-700">Aucun lot ouvert : en créer un dans Stock › Lots.</p> @endif
                        @error('lotId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="pointCollecteId" class="mb-1 block text-sm font-medium text-stone-700">Point de collecte <span class="font-normal text-stone-500">(facultatif)</span></label>
                        <select wire:model="pointCollecteId" id="pointCollecteId" class="{{ $champ }}">
                            <option value="">—</option>
                            @foreach ($pointsCollecte as $pc)
                                <option value="{{ $pc->id }}">{{ $pc->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dateAchat" class="mb-1 block text-sm font-medium text-stone-700">Date et heure</label>
                        <input wire:model="dateAchat" id="dateAchat" type="datetime-local" class="{{ $champ }}">
                        @error('dateAchat') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">Pesée, qualité et prix</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="poidsBrutKg" class="mb-1 block text-sm font-medium text-stone-700">Poids brut (kg)</label>
                        <input wire:model.live.debounce.400ms="poidsBrutKg" id="poidsBrutKg" type="text" inputmode="decimal" class="{{ $champ }} text-right">
                        @error('poidsBrutKg') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tareKg" class="mb-1 block text-sm font-medium text-stone-700">Tare, sacs (kg)</label>
                        <input wire:model.live.debounce.400ms="tareKg" id="tareKg" type="text" inputmode="decimal" class="{{ $champ }} text-right">
                        @error('tareKg') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="prixKg" class="mb-1 block text-sm font-medium text-stone-700">Prix (FCFA/kg)</label>
                        <input wire:model.live.debounce.400ms="prixKg" id="prixKg" type="text" inputmode="numeric" class="{{ $champ }} text-right">
                        @error('prixKg') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="humidite" class="mb-1 block text-sm font-medium text-stone-700">Humidité (%)</label>
                        <input wire:model="humidite" id="humidite" type="text" inputmode="decimal" placeholder="8,5" class="{{ $champ }} text-right">
                        @error('humidite') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="kor" class="mb-1 block text-sm font-medium text-stone-700">KOR (lbs / 80 kg)</label>
                        <input wire:model="kor" id="kor" type="text" inputmode="decimal" placeholder="48,50" class="{{ $champ }} text-right">
                        @error('kor') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="grainage" class="mb-1 block text-sm font-medium text-stone-700">Grainage (noix/kg)</label>
                        <input wire:model="grainage" id="grainage" type="number" step="1" min="1" placeholder="190" class="{{ $champ }} text-right">
                        @error('grainage') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            @if ($fournisseurType === 'producteur' && $prets->isNotEmpty())
                <section class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-6">
                    <h2 class="mb-4 font-semibold">Remboursement du prêt en kilos</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="pretId" class="mb-1 block text-sm font-medium text-stone-700">Prêt</label>
                            <select wire:model.live="pretId" id="pretId" class="{{ $champ }}">
                                <option value="">— Ne pas rembourser sur cet achat —</option>
                                @foreach ($prets as $p)
                                    <option value="{{ $p->id }}">{{ $p->reference }} — restant dû {{ $format::fcfa($p->restantDu()) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($pretId !== '' && ! $apercu['erreurRegle'])
                            <div>
                                <label for="kilosRetenus" class="mb-1 block text-sm font-medium text-stone-700">Kilos retenus pour le prêt</label>
                                <input wire:model.live.debounce.400ms="kilosRetenus" id="kilosRetenus" type="text" inputmode="decimal"
                                    placeholder="{{ $apercu['grammesRetenus'] !== null ? \App\Support\Mesure::versSaisie($apercu['grammesRetenus'], 3) : '' }}" class="{{ $champ }} text-right">
                                <p class="mt-1 text-xs text-stone-500">Vide = ce qu'il faut pour solder le prêt, dans la limite du poids net.</p>
                                @error('kilosRetenus') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </div>
                    @if ($apercu['erreurRegle'])
                        <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900" id="regle-manquante">{{ $apercu['erreurRegle'] }}</p>
                    @elseif ($regleChoisie)
                        <p class="mt-3 text-xs text-stone-600">Règle de la direction : {{ $regleChoisie->libelle() }} ({{ $format::fcfa((int) $apercu['prixNature']) }}/kg).</p>
                    @endif
                </section>
            @endif
        </div>

        <aside class="space-y-4">
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm lg:sticky lg:top-20">
                <h2 class="mb-3 font-semibold">Aperçu</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-500">Poids net</dt><dd class="font-medium tabular-nums" id="apercu-net">{{ $apercu['net'] !== null && $apercu['net'] > 0 ? $format::kg($apercu['net']) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-500">Valeur de l'achat</dt><dd class="font-medium tabular-nums" id="apercu-montant">{{ $format::fcfa($apercu['montant']) }}</dd></div>
                    @if ($apercu['valeurRetenue'] !== null)
                        <div class="flex justify-between text-emerald-900"><dt>Retenu sur le prêt</dt><dd class="font-medium tabular-nums" id="apercu-retenu">{{ $format::kg($apercu['grammesRetenus']) }} · {{ $format::fcfa($apercu['valeurRetenue']) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-stone-200 pt-2 text-base"><dt class="font-medium">À payer en espèces</dt><dd class="font-semibold tabular-nums" id="apercu-especes">{{ $format::fcfa($apercu['especes']) }}</dd></div>
                </dl>

                <div class="mt-4">
                    <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">Payé depuis</label>
                    <select wire:model="compteId" id="compteId" class="{{ $champ }}">
                        <option value="">— Choisir —</option>
                        @foreach ($comptes as $c)
                            <option value="{{ $c->id }}">{{ $c->nom }}</option>
                        @endforeach
                    </select>
                    @error('compteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <button type="submit" wire:loading.attr="disabled" @disabled(! $campagne)
                    class="mt-4 w-full rounded-md bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer l'achat</button>
            </section>
        </aside>
    </form>
</div>
