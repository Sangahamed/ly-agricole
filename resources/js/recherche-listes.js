// Recherche dans toutes les listes déroulantes (demande du 2026-10-07) : chaque <select> devient
// une liste où l'on tape pour filtrer, sans toucher aux formulaires — la valeur reste celle du
// <select> d'origine (wire:model continue de marcher : Tom Select y renvoie « change »).
//
// Livewire redessine la page à chaque échange : sans précaution, il retirerait le bloc ajouté
// par Tom Select (absent du HTML du serveur) et ré-afficherait le <select> caché. D'où les deux
// crochets plus bas : garder le bloc, puis resynchroniser options et valeur.
//
// `data-recherche-creer` : liste ET saisie (un texte tapé devient la valeur, ex. le nom d'un
// investisseur sans compte). `data-sans-recherche` : laisser le <select> natif.
import TomSelect from 'tom-select';

const CACHE = ['tomselected', 'ts-hidden-accessible'];

function equiper(select) {
    if (select.tomselect || select.multiple || select.hasAttribute('data-sans-recherche')) return;
    const creer = select.hasAttribute('data-recherche-creer');
    new TomSelect(select, {
        allowEmptyOption: true,
        create: creer,
        createOnBlur: creer,
        persist: false,
        maxOptions: null,
        dropdownParent: 'body',
        render: {
            no_results: () => '<div class="no-results">Aucun résultat</div>',
            option_create: (data, echapper) => `<div class="create">Utiliser « <strong>${echapper(data.input)}</strong> »</div>`,
        },
    });
}

function synchroniser(select) {
    const ts = select.tomselect;
    if (!ts) return equiper(select);
    select.classList.add(...CACHE);
    // Options et valeur telles que le serveur vient de les rendre.
    ts.clearOptions();
    ts.sync();
}

export function equiperTout(racine = document) {
    racine.querySelectorAll('select').forEach((s) => (s.tomselect ? synchroniser(s) : equiper(s)));
}

document.addEventListener('DOMContentLoaded', () => equiperTout());

document.addEventListener('livewire:init', () => {
    // Le bloc de Tom Select n'est pas dans le HTML du serveur : Livewire ne doit pas l'enlever.
    window.Livewire.hook('morph.removing', ({ el, skip }) => {
        if (el.classList?.contains('ts-wrapper') || el.classList?.contains('ts-dropdown')) skip();
    });
    // Après chaque échange : nouvelles listes équipées, anciennes resynchronisées.
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => equiperTout())));
});

document.addEventListener('livewire:navigated', () => equiperTout());
