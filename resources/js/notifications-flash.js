// Notifications « flash » (demande du 2026-10-07) : un bandeau qui apparaît en haut à droite
// et disparaît seul, pour chaque succès et chaque erreur — sans toucher aux écrans.
//
// - Succès : la propriété `statut` d'un composant Livewire (convention du projet) qui change,
//   ou le message de session `statut` / `erreur` après une redirection (data-flash du <body>).
// - Erreurs de saisie : les erreurs de validation renvoyées par le serveur (le détail reste
//   sous chaque champ).
// - Panne : une requête Livewire en erreur (500, session expirée…) affiche un bandeau au lieu
//   de la fenêtre de Livewire.

const DUREE = { succes: 4500, erreur: 8000 };
const vus = new Map(); // id du composant → { statut, erreurs } déjà montrés

function pile() {
    let p = document.getElementById('notifications-flash');
    if (!p) {
        p = document.createElement('div');
        p.id = 'notifications-flash';
        p.setAttribute('aria-live', 'polite');
        p.className = 'pointer-events-none fixed inset-x-4 top-4 z-[70] flex flex-col items-end gap-2 sm:left-auto sm:right-4 sm:w-96';
        document.body.appendChild(p);
    }
    return p;
}

export function notifier(texte, type = 'succes') {
    if (!texte) return;
    const n = document.createElement('div');
    n.setAttribute('role', type === 'erreur' ? 'alert' : 'status');
    n.className = 'pointer-events-auto flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg transition duration-300 '
        + (type === 'erreur'
            ? 'border-red-200 bg-red-50 text-red-900'
            : 'border-emerald-200 bg-emerald-50 text-emerald-900');
    const corps = document.createElement('p');
    corps.className = 'flex-1';
    corps.textContent = texte;
    const fermer = document.createElement('button');
    fermer.type = 'button';
    fermer.className = 'shrink-0 opacity-60 hover:opacity-100';
    fermer.setAttribute('aria-label', 'Fermer');
    fermer.textContent = '×';
    n.append(corps, fermer);

    const retirer = () => {
        n.classList.add('opacity-0');
        setTimeout(() => n.remove(), 300);
    };
    fermer.addEventListener('click', retirer);
    setTimeout(retirer, DUREE[type] ?? DUREE.succes);
    pile().appendChild(n);
}

function premiereErreur(erreurs) {
    const messages = Object.values(erreurs ?? {}).flat().filter(Boolean);
    if (messages.length === 0) return null;
    return messages.length === 1 ? messages[0] : `${messages[0]} (et ${messages.length - 1} autre${messages.length > 2 ? 's' : ''} erreur${messages.length > 2 ? 's' : ''})`;
}

function etat(component) {
    const data = component.snapshot?.data ?? {};
    return {
        statut: typeof data.statut === 'string' ? data.statut : '',
        erreurs: JSON.stringify(component.snapshot?.memo?.errors ?? {}),
        brutes: component.snapshot?.memo?.errors ?? {},
    };
}

document.addEventListener('DOMContentLoaded', () => {
    // Message de session posé avant une redirection.
    const corps = document.body.dataset;
    if (corps.flashErreur) notifier(corps.flashErreur, 'erreur');
    else if (corps.flashStatut) notifier(corps.flashStatut);
});

document.addEventListener('livewire:init', () => {
    const L = window.Livewire;

    // État de départ : pas de bandeau pour ce qui était déjà affiché au chargement.
    L.hook('component.init', ({ component }) => {
        const e = etat(component);
        vus.set(component.id, { statut: e.statut, erreurs: e.erreurs });
    });

    L.hook('commit', ({ component, succeed }) => {
        succeed(() => queueMicrotask(() => {
            const avant = vus.get(component.id) ?? { statut: '', erreurs: '{}' };
            const e = etat(component);
            if (e.erreurs !== avant.erreurs && e.erreurs !== '{}' && e.erreurs !== '[]') {
                notifier(premiereErreur(e.brutes), 'erreur');
            } else if (e.statut !== '' && e.statut !== avant.statut) {
                notifier(e.statut);
            }
            vus.set(component.id, { statut: e.statut, erreurs: e.erreurs });
        }));
    });

    // Panne côté serveur : bandeau au lieu de la fenêtre de Livewire.
    L.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            preventDefault();
            if (status === 419) {
                notifier('Votre session a expiré : rechargez la page (F5) puis recommencez.', 'erreur');
            } else if (status === 403) {
                notifier('Accès refusé : votre rôle ne permet pas cette action.', 'erreur');
            } else {
                notifier(`Erreur du serveur (${status || 'réseau'}) : rien n'a été enregistré. Réessayez ; si cela continue, prévenez l'administrateur.`, 'erreur');
            }
        });
    });
});
