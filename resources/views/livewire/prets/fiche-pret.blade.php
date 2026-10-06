@php($champ = 'block w-full rounded-md border border-stone-300 px-3 py-2 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20 focus:outline-none')
<div>
    <div class="mb-6">
        <a href="{{ route('prets') }}" class="text-sm text-emerald-800 hover:underline">← Prêts</a>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Prêt <span class="font-mono">{{ $pret->reference }}</span></h1>
                <p class="text-stone-600">
                    <a href="{{ route('producteurs.fiche', $pret->producteur) }}" class="text-emerald-800 hover:underline">{{ $pret->producteur->nomComplet() }}</a>
                    — {{ $pret->producteur->village->nom }} · campagne {{ $pret->campagne->produit->nom }} {{ $pret->campagne->code }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($peutAnnuler)
                    <a href="{{ route('prets.nouveau', ['corrige' => $pret->id]) }}" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-800 hover:bg-stone-50">Modifier</a>
                    <button type="button" wire:click="ouvrirAnnulation" class="rounded-md px-3 py-1.5 text-sm text-red-800 hover:bg-red-50">Supprimer</button>
                @endif
                <p class="text-lg font-semibold" id="statut-pret">{{ $pret->statut->libelle() }}</p>
            </div>
        </div>
    </div>

    @if ($annulationOuverte)
        <form wire:submit="annulerPret" class="mb-6 rounded-md border border-red-200 bg-red-50 p-4">
            <p class="text-sm text-red-950">Le prêt passe « annulé » : il ne compte plus dans les totaux, mais reste visible avec qui l'a annulé et pourquoi.</p>
            <label for="motifAnnulation" class="mt-3 block text-sm font-medium text-red-950">Motif de l'annulation</label>
            <input wire:model="motifAnnulation" id="motifAnnulation" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
            @error('motifAnnulation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            <div class="mt-3 flex gap-2">
                <button type="submit" class="rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Supprimer le prêt</button>
                <button type="button" wire:click="$set('annulationOuverte', false)" class="rounded-md px-4 py-2 text-sm text-stone-700 hover:bg-stone-100">Garder</button>
            </div>
        </form>
    @endif

    @if ($statut !== '')
        <p class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">{{ $statut }}</p>
    @endif
    @error('action')
        <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <dl class="grid gap-4 rounded-xl border border-stone-200 bg-white p-6 text-sm shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-stone-500">Montant du prêt</dt><dd class="text-base font-semibold tabular-nums">{{ \App\Support\Format::fcfa($pret->montant_fcfa) }}</dd></div>
        <div>
            <dt class="text-stone-500">Remis au producteur</dt>
            <dd class="text-base font-semibold tabular-nums" id="remis">{{ \App\Support\Format::fcfa($decaisse + $intrantsRemis) }}</dd>
            <dd class="text-xs text-stone-500" id="detail-remis">argent {{ \App\Support\Format::fcfa($decaisse) }} · intrants {{ \App\Support\Format::fcfa($intrantsRemis) }}</dd>
        </div>
        <div><dt class="text-stone-500">Reste à remettre</dt><dd class="text-base font-semibold tabular-nums" id="reste">{{ \App\Support\Format::fcfa($pret->montant_fcfa - $decaisse - $intrantsRemis) }}</dd></div>
        <div>
            <dt class="text-stone-500">Restant dû</dt>
            <dd class="text-base font-semibold tabular-nums text-emerald-900" id="restant-du">{{ \App\Support\Format::fcfa($pret->restantDu()) }}</dd>
            <dd class="text-xs text-stone-500" id="detail-du">remis − remboursé {{ \App\Support\Format::fcfa($rembourse) }} (sans intérêt)</dd>
        </div>
        <div><dt class="text-stone-500">Forme</dt><dd>{{ $pret->forme->libelle() }}</dd></div>
        <div><dt class="text-stone-500">Échéance</dt><dd>{{ $pret->echeance->format('d/m/Y') }}</dd></div>
        <div>
            <dt class="text-stone-500">Kilos attendus (estimation)</dt>
            <dd>
                @if ($pret->grammes_attendus !== null)
                    {{ \App\Support\Format::kg($pret->grammes_attendus) }} <span class="text-stone-500">à {{ \App\Support\Format::fcfa($pret->prix_reference_kg_fcfa) }}/kg</span>
                @else — @endif
            </dd>
        </div>
        <div><dt class="text-stone-500">Surface financée</dt><dd>{{ $surface === null ? 'non relevée' : \App\Support\Format::hectares($surface) }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-stone-500">Demande saisie par</dt><dd>{{ $pret->auteur->nom }}, le {{ $pret->created_at->format('d/m/Y') }}</dd></div>
        @if ($pret->parcelles->isNotEmpty())
            <div class="sm:col-span-2"><dt class="text-stone-500">Parcelles</dt><dd>{{ $pret->parcelles->pluck('nom')->join(', ') }}</dd></div>
        @endif
        @if ($pret->partie_liee)
            <div class="sm:col-span-2 lg:col-span-4 text-amber-800">
                Partie liée (art. 17.3) — <a href="{{ route('prets.accord', $pret) }}" target="_blank" class="underline">accord écrit</a>
            </div>
        @endif
        @if ($pret->motif_annulation)
            <div class="sm:col-span-2 lg:col-span-4 text-red-800" id="annulation">Annulé par {{ $pret->annuleur?->nom }}, le {{ $pret->annule_at?->format('d/m/Y') }} : {{ $pret->motif_annulation }}</div>
        @endif
        @if ($pret->motif_refus)
            <div class="sm:col-span-2 lg:col-span-4 text-red-800">Refusé : {{ $pret->motif_refus }}</div>
        @endif
    </dl>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Validations ({{ $pret->validations->count() }}/{{ $pret->validations_requises }})</h2>
            @if ($peutValider)
                <div class="flex gap-2">
                    <button type="button" wire:click="valider" wire:confirm="Valider le prêt {{ $pret->reference }} de {{ \App\Support\Format::fcfa($pret->montant_fcfa) }} ?"
                        class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Valider</button>
                    <button type="button" wire:click="ouvrirRefus" class="rounded-md px-4 py-2 text-sm text-red-800 hover:bg-red-50">Refuser</button>
                </div>
            @elseif ($pret->statut->value === 'demande' && $pret->cree_par === auth()->id())
                <span class="text-sm text-stone-500">Votre demande : la validation revient à une autre personne.</span>
            @endif
        </div>
        <ul class="mt-3 space-y-1 text-sm">
            @forelse ($pret->validations as $v)
                <li>✓ {{ $v->user->nom }}, le {{ $v->created_at->format('d/m/Y à H:i') }}
                    @if ($v->user_id === $pret->cree_par) <span class="text-stone-500">— accordé directement par la direction, sans 2e accord</span>@endif</li>
            @empty
                <li class="text-stone-500">Aucune validation pour l'instant.</li>
            @endforelse
        </ul>

        @if ($refusOuvert)
            <form wire:submit="refuser" class="mt-4 rounded-md border border-red-200 bg-red-50 p-4">
                <label for="motifRefus" class="block text-sm font-medium text-red-950">Motif du refus</label>
                <input wire:model="motifRefus" id="motifRefus" type="text" class="mt-1 block w-full rounded-md border border-red-300 px-3 py-2 focus:outline-none">
                @error('motifRefus') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <button type="submit" class="mt-3 rounded-md bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800">Refuser la demande</button>
            </form>
        @endif
    </section>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Remises au producteur</h2>
            <div class="flex gap-2">
                @if ($peutVerserArgent)
                    <button type="button" wire:click="ouvrirDecaissement" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Verser de l'argent</button>
                @endif
                @if ($peutRemettreIntrants)
                    <button type="button" wire:click="ouvrirRemise" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Remettre des intrants</button>
                @endif
            </div>
        </div>

        @if ($decaissementOuvert)
            <form wire:submit="decaisser" class="mt-4 rounded-md border border-stone-200 bg-stone-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    @if (count($modes) > 1)
                        <div>
                            <label for="modeVersement" class="mb-1 block text-sm font-medium text-stone-700">Mode</label>
                            <select wire:model.live="modeVersement" id="modeVersement" class="{{ $champ }}">
                                @foreach ($modes as $m)
                                    <option value="{{ $m->value }}">{{ $m->libelle() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label for="compteId" class="mb-1 block text-sm font-medium text-stone-700">Depuis le compte ({{ $mode->libelle() }})</label>
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
                        <input wire:model="montant" id="montant" type="text" inputmode="numeric" class="{{ $champ }} text-right">
                        @error('montant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="dateDecaissement" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                        <input wire:model="dateDecaissement" id="dateDecaissement" type="date" class="{{ $champ }}">
                        @error('dateDecaissement') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    @if ($mode->value === 'mobile_money')
                        <div>
                            <label for="reference" class="mb-1 block text-sm font-medium text-stone-700">Référence de la transaction</label>
                            <input wire:model="reference" id="reference" type="text" class="{{ $champ }}">
                            @error('reference') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="recu" class="mb-1 block text-sm font-medium text-stone-700">Reçu signé par le producteur</label>
                            <input wire:model="recu" id="recu" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="block text-sm">
                            <div wire:loading wire:target="recu" class="mt-1 text-sm text-stone-500">Envoi du reçu…</div>
                            @error('recu') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <button type="submit" wire:loading.attr="disabled" class="mt-4 rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer le versement</button>
            </form>
        @endif

        @if ($remiseOuverte)
            <form wire:submit="remettreIntrants" class="mt-4 rounded-md border border-stone-200 bg-stone-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="intrantId" class="mb-1 block text-sm font-medium text-stone-700">Intrant</label>
                        <select wire:model.live="intrantId" id="intrantId" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($intrants as $i)
                                <option value="{{ $i->id }}">{{ $i->nom }} ({{ \App\Support\Format::fcfa($i->prix_unitaire_fcfa) }})</option>
                            @endforeach
                        </select>
                        @error('intrantId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="magasinId" class="mb-1 block text-sm font-medium text-stone-700">Depuis le magasin</label>
                        <select wire:model="magasinId" id="magasinId" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($magasins as $m)
                                <option value="{{ $m->id }}">{{ $m->nom }}</option>
                            @endforeach
                        </select>
                        @error('magasinId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="quantiteIntrant" class="mb-1 block text-sm font-medium text-stone-700">Quantité</label>
                        <input wire:model.live.debounce.400ms="quantiteIntrant" id="quantiteIntrant" type="number" step="1" min="1" class="{{ $champ }} text-right">
                        @if ($valeurApercu !== null)
                            <p class="mt-1 text-sm text-stone-600" id="valeur-apercu">Valeur au prix du jour : <strong>{{ \App\Support\Format::fcfa($valeurApercu) }}</strong></p>
                        @endif
                        @error('quantiteIntrant') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="dateRemise" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                        <input wire:model="dateRemise" id="dateRemise" type="date" class="{{ $champ }}">
                        @error('dateRemise') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="mt-4 rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Enregistrer la remise</button>
            </form>
        @endif

        <table class="mt-4 min-w-full text-sm">
            <thead class="text-left text-stone-600">
                <tr>
                    <th class="py-2 pr-4 font-medium">Date</th>
                    <th class="py-2 pr-4 font-medium">Remise</th>
                    <th class="py-2 pr-4 text-right font-medium">Valeur</th>
                    <th class="py-2 pr-4 font-medium">Depuis</th>
                    <th class="py-2 pr-4 font-medium">Par</th>
                    <th class="py-2 pr-4 font-medium">Pièces</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($pret->decaissements as $d)
                    <tr @class(['text-stone-400 line-through' => $d->mouvement->contrePassation !== null])>
                        <td class="py-2 pr-4">{{ $d->date_decaissement->format('d/m/Y') }}</td>
                        <td class="py-2 pr-4">Argent ({{ $d->mode->libelle() }})@if ($d->reference_paiement), réf. {{ $d->reference_paiement }}@endif</td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ \App\Support\Format::fcfa($d->montant_fcfa) }}</td>
                        <td class="py-2 pr-4">{{ $d->compte->nom }}</td>
                        <td class="py-2 pr-4">{{ $d->auteur->nom }}</td>
                        <td class="py-2 pr-4">
                            <a href="{{ route('prets.recu-pdf', [$pret, 'argent', $d->id]) }}" target="_blank" class="text-emerald-800 underline">reçu PDF</a>
                            · <a href="{{ route('tickets.remise', [$pret, 'argent', $d->id]) }}" target="_blank" class="text-emerald-800 underline">ticket 58 mm</a>
                            @if ($d->justificatif) · <a href="{{ route('prets.recu', $d) }}" target="_blank" class="text-emerald-800 underline">reçu signé</a> @endif
                        </td>
                    </tr>
                @endforeach
                @foreach ($pret->mouvementsIntrants as $m)
                    <tr @class(['text-stone-400 line-through' => $m->contrePassation !== null, 'text-amber-800' => $m->type->value === 'contre_passation'])>
                        <td class="py-2 pr-4">{{ $m->date_mouvement->format('d/m/Y') }}</td>
                        <td class="py-2 pr-4">
                            @if ($m->type->value === 'contre_passation')
                                Retour en stock (contre-passation) : {{ $m->quantite }} {{ $m->intrant->unite->libelle($m->quantite) }} {{ $m->intrant->nom }}
                            @else
                                {{ -$m->quantite }} {{ $m->intrant->unite->libelle(-$m->quantite) }} {{ $m->intrant->nom }} à {{ \App\Support\Format::fcfa((int) $m->prix_unitaire_fcfa) }}
                            @endif
                        </td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ \App\Support\Format::fcfa(-(int) $m->valeur_fcfa) }}</td>
                        <td class="py-2 pr-4">{{ $m->magasin->nom }}</td>
                        <td class="py-2 pr-4">{{ $m->auteur->nom }}</td>
                        <td class="py-2 pr-4">
                            @if ($m->type->value === 'distribution')
                                <a href="{{ route('prets.recu-pdf', [$pret, 'intrants', $m->id]) }}" target="_blank" class="text-emerald-800 underline">reçu PDF</a>
                                · <a href="{{ route('tickets.remise', [$pret, 'intrants', $m->id]) }}" target="_blank" class="text-emerald-800 underline">ticket 58 mm</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if ($pret->decaissements->isEmpty() && $pret->mouvementsIntrants->isEmpty())
                    <tr><td colspan="6" class="py-4 text-stone-500">Rien n'a encore été remis.</td></tr>
                @endif
            </tbody>
        </table>
    </section>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Remboursements <span class="text-sm font-normal text-stone-500">({{ \App\Support\Format::fcfa($rembourse) }})</span></h2>
            @if ($peutEncaisser)
                <button type="button" wire:click="ouvrirRemboursement" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">Encaisser un remboursement</button>
            @endif
        </div>
        <p class="mt-1 text-xs text-stone-500">Les remboursements en kilos se font à l'achat (Achats › Nouvel achat, avec le prêt du producteur).</p>

        @if ($remboursementOuvert)
            <form wire:submit="encaisserRemboursement" class="mt-4 rounded-md border border-stone-200 bg-stone-50 p-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="compteRemboursementId" class="mb-1 block text-sm font-medium text-stone-700">Encaissé sur le compte</label>
                        <select wire:model="compteRemboursementId" id="compteRemboursementId" class="{{ $champ }}">
                            <option value="">— Choisir —</option>
                            @foreach ($comptesRemboursement as $c)
                                <option value="{{ $c->id }}">{{ $c->nom }}</option>
                            @endforeach
                        </select>
                        @error('compteRemboursementId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="montantRemboursement" class="mb-1 block text-sm font-medium text-stone-700">Montant (FCFA)</label>
                        <input wire:model="montantRemboursement" id="montantRemboursement" type="text" inputmode="numeric" class="{{ $champ }} text-right">
                        @error('montantRemboursement') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="dateRemboursement" class="mb-1 block text-sm font-medium text-stone-700">Date</label>
                        <input wire:model="dateRemboursement" id="dateRemboursement" type="date" class="{{ $champ }}">
                        @error('dateRemboursement') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="referenceRemboursement" class="mb-1 block text-sm font-medium text-stone-700">Référence <span class="font-normal text-stone-500">(reçu, n° Wave…)</span></label>
                        <input wire:model="referenceRemboursement" id="referenceRemboursement" type="text" class="{{ $champ }}">
                    </div>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="mt-4 rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60">Encaisser</button>
            </form>
        @endif

        @if ($remboursementAContrePasser !== null)
            <form wire:submit="contrePasserRemboursement" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                <label for="motifContrePassation" class="block text-sm font-medium text-amber-950">Motif de la contre-passation</label>
                <input wire:model="motifContrePassation" id="motifContrePassation" type="text" class="mt-1 block w-full rounded-md border border-amber-300 px-3 py-2 focus:outline-none">
                @error('motifContrePassation') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <button type="submit" class="mt-3 rounded-md bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800">Contre-passer</button>
            </form>
        @endif

        <table class="mt-4 min-w-full text-sm">
            <thead class="text-left text-stone-600">
                <tr>
                    <th class="py-2 pr-4 font-medium">Date</th>
                    <th class="py-2 pr-4 font-medium">Type</th>
                    <th class="py-2 pr-4 font-medium">Détail</th>
                    <th class="py-2 pr-4 text-right font-medium">Montant</th>
                    <th class="py-2 pr-4 font-medium">Par</th>
                    <th class="py-2 pr-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($pret->remboursements as $r)
                    <tr @class(['text-stone-400 line-through' => $r->contrePassation !== null, 'text-amber-800' => $r->type->value === 'contre_passation'])>
                        <td class="py-2 pr-4">{{ $r->date_remboursement->format('d/m/Y') }}</td>
                        <td class="py-2 pr-4">{{ $r->type->libelle() }}</td>
                        <td class="py-2 pr-4">
                            @if ($r->type->value === 'nature')
                                {{ \App\Support\Format::kg((int) $r->grammes) }} à {{ \App\Support\Format::fcfa((int) $r->prix_kg_fcfa) }}/kg
                                <span class="text-xs text-stone-500">({{ $r->regle_valorisation?->libelle() }}, achat {{ $r->achat?->reference }})</span>
                            @elseif ($r->motif)
                                <span class="text-xs">{{ $r->motif }}</span>
                            @endif
                        </td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ \App\Support\Format::fcfa($r->montant_fcfa) }}</td>
                        <td class="py-2 pr-4">{{ $r->auteur->nom }}</td>
                        <td class="py-2 pr-4 text-right">
                            @if ($r->type->value === 'especes' && $r->contrePassation === null && auth()->user()->can('encaisser-remboursements'))
                                <button type="button" wire:click="preparerContrePassationRemboursement({{ $r->id }})" class="text-xs text-amber-800 hover:underline">Contre-passer</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-4 text-stone-500">Aucun remboursement.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
