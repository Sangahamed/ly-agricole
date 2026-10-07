// Menu latéral : sur téléphone il glisse par-dessus la page ; sur grand écran il est fixe.
const menu = document.querySelector('[data-menu]');
const voile = document.querySelector('[data-menu-voile]');
const bouton = document.querySelector('[data-menu-bouton]');

function basculerMenu(ouvert) {
    if (!menu) return;
    menu.classList.toggle('-translate-x-full', !ouvert);
    voile?.classList.toggle('hidden', !ouvert);
    bouton?.setAttribute('aria-expanded', String(ouvert));
}

bouton?.addEventListener('click', () => basculerMenu(menu.classList.contains('-translate-x-full')));
voile?.addEventListener('click', () => basculerMenu(false));

// Menus déroulants (<details>) : un clic ailleurs ou Échap les referme.
document.addEventListener('click', (e) => {
    document.querySelectorAll('details[data-deroulant][open]').forEach((d) => {
        if (!d.contains(e.target)) d.removeAttribute('open');
    });
});
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    basculerMenu(false);
    document.querySelectorAll('details[data-deroulant][open]').forEach((d) => d.removeAttribute('open'));
});

// Recherche dans toutes les listes déroulantes : voir recherche-listes.js.
import './recherche-listes';

// Bandeaux de succès et d'erreur : voir notifications-flash.js.
import './notifications-flash';

// Avis en direct (Reverb) pour un utilisateur connecté : voir echo.js.
import './echo';
