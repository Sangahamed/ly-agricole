# Décisions d'architecture

Chaque décision : ce qui est choisi, pourquoi, et ce qui la ferait changer.
Ajouter les nouvelles en bas, datées ; ne jamais réécrire une décision passée —
en ajouter une qui la remplace.

---

## D1 — Laravel 13 + Livewire 3 + Tailwind 4 pour le back-office (2026-09-25)

**Choix.** Application web unique pour la direction, la comptabilité, l'agronome et
les investisseurs.

**Pourquoi.** C'est la pile maîtrisée par le développeur (Masadora / VistaResidence) :
rôles, politiques, PDF, files d'attente, tests PHPUnit — rien à réapprendre, et les
pièges connus sont documentés (voir `CLAUDE.md`). PHP 8.3 est déjà installé sur le
poste (XAMPP).

**Ce qui la ferait changer.** Rien de prévisible en phase 1.

## D2 — L'appli terrain n'est PAS du Livewire (2026-09-25)

**Choix.** Une application séparée, **SvelteKit en mode statique + IndexedDB (Dexie) +
Capacitor (Android)**, qui parle à Laravel par une API (Sanctum) et un point de
synchronisation `/api/sync`.

**Pourquoi.** Livewire a besoin du serveur à chaque clic. Mesuré sur Masadora :
sans réseau, un écran Livewire **ne bouge simplement pas** — aucune erreur, aucun
message (voir le piège « In Livewire, losing the network produces NO signal at all »
dans le CLAUDE.md de VistaResidence). Or les agents achètent et pèsent dans des
villages sans couverture. L'appli terrain doit enregistrer en local et envoyer plus
tard. SvelteKit : déjà utilisé par le développeur (module OpenPlan3D de Masadora) ;
Capacitor : déjà utilisé aussi (`capacitor.config.ts` de VistaResidence) et donne GPS,
appareil photo et Bluetooth (balance, phase 2).

**Ce qui la ferait changer.** Si le pilote montre que tous les points de collecte ont
du réseau — peu probable.

## D3 — Les identifiants sont créés sur le téléphone (UUID v7) (2026-09-25)

**Choix.** Toute ligne créable hors ligne (producteur, parcelle, achat, visite, photo,
dépense terrain) reçoit son UUID v7 sur le téléphone. Le serveur l'accepte tel quel.

**Pourquoi.** Deux envois du même achat (réseau coupé au milieu de la réponse) doivent
donner **un seul** achat : l'UUID rend l'envoi idempotent. Un identifiant auto-incrémenté
n'existe qu'après passage au serveur, donc impossible hors ligne.

## D4 — L'argent est en entiers de FCFA ; les poids en grammes (2026-09-25)

**Choix.** `montant_fcfa` en `BIGINT` (le FCFA n'a pas de centimes). Poids stockés en
**grammes** `BIGINT`, affichés en kg. Prix au kilo en FCFA entier. Devises étrangères
(phase 2) : montant en unités mineures + taux figé à la date d'encaissement.

**Pourquoi.** Jamais de `FLOAT`/`DOUBLE` pour de l'argent : 0,1 + 0,2 ≠ 0,3, et un
rapport investisseur faux d'un franc est un rapport contestable. Les grammes évitent
les décimales sur les poids et donc les arrondis cumulés d'une pesée à l'autre.

## D5 — Registres immuables : on ne modifie pas, on contre-passe (2026-09-25)

**Choix.** Les mouvements de trésorerie, les mouvements de stock et les
remboursements ne sont **jamais** modifiés ni supprimés. Une erreur se corrige par un
mouvement inverse (contre-passation) qui référence l'original, avec un motif.
Soldes et stocks = somme des mouvements (avec cache recalculable, jamais source de
vérité).

**Pourquoi.** C'est ce qui rend le rapport de campagne vérifiable par un investisseur
(contrat art. 18) et ce qui empêche un agent d'effacer une trace. Un solde stocké
dans une colonne modifiable peut diverger en silence ; une somme de mouvements non.

**Complément (2026-10-01, demande du responsable projet : « la direction doit pouvoir
supprimer »).** Le bouton « Supprimer » de la direction **annule** : une opération qui a déjà
eu un effet (achat validé, dépense payée) est contre-passée sur tous ses registres d'un coup,
avec motif, et reste visible marquée « annulée » ; une opération en attente passe « annulée »
sans effet. Seule une **fiche qui n'a encore servi à rien** (producteur sans prêt, achat,
visite…) est vraiment effacée, trace gardée au journal. Choix fait par le responsable projet
parmi trois options ; « tout effacer vraiment » a été écarté.

**Complément (2026-10-06, demande : « permettre à chaque utilisateur de modifier ou supprimer
ce qu'il fait »).** « Supprimer » est ouvert à l'**auteur** de l'opération en plus de la
direction (droit `annuler-operation`, sur la ligne), pour les prêts, ventes, achats, dépenses
et fiches producteur ; même mécanique d'annulation. « Modifier » = formulaire pré-rempli
(`?corrige=<id>`) qui, à l'enregistrement et dans la même transaction, annule l'ancienne
saisie puis enregistre la nouvelle (nouvelle référence, l'ancienne garde « Modifié : remplacé
par … »). Un prêt ne s'annule que tant que rien n'a été remis. Mouvements de trésorerie,
apports, stock, intrants : contre-passation inchangée (direction et comptable). Effacer
vraiment reste écarté : solution la plus prudente, à confirmer par le responsable projet.

## D6 — Séparation des tâches appliquée par le code (2026-09-25)

**Choix.** Les validations (prêt, dépense au-dessus du seuil, clôture de campagne)
sont refusées si `valide_par == cree_par`. Testé.

**Pourquoi.** Exigence du cahier des charges (§2) et réponse directe aux faiblesses
relevées dans le contrat (art. 9, 17.3).

**Exception (2026-10-06, demande : « la direction n'a pas besoin de validation, c'est le
compte supérieur »).** Ce que saisit un compte **direction** — prêt, dépense, achat, vente —
est validé dès la saisie, à son nom : prêt « validé » avec une `ValidationPret` de l'auteur
(fiche : « accordé directement par la direction, sans 2e accord ») ; dépense, achat, vente
avec `valide_par = cree_par`. C'est la trace du contournement, lisible et requêtable. Les
autres rôles restent soumis à D6 (testé). Reste vrai : accord écrit d'une partie liée
(art. 17.3), plafonds, caisse jamais négative. **Conséquence à signaler aux investisseurs** :
le contrôle à deux personnes (art. 9, 17.3) ne couvre plus les opérations de la direction.

## D7 — Pas de multi-entreprise en phase 1 (2026-09-25)

**Choix.** Une seule entreprise (LY AGRICOLE). Pas de `company_id` partout.

**Pourquoi.** Sur Masadora, l'isolation multi-entreprise a coûté cher (portées
globales, contextes dans les files d'attente, fuites). Rien ne la justifie ici tant
que la plateforme n'est pas proposée à d'autres coopératives. Si cela arrive, ce sera
une décision nouvelle, avec migration.

## D8 — Noms métier en français dans le code (2026-09-25)

**Choix.** Modèles, tables et méthodes métier en français sans accents :
`Producteur`, `Parcelle`, `Pret`, `Decaissement`, `Achat`, `Lot`, `MouvementStock`,
`Depense`, `CompteTresorerie`. Le vocabulaire du framework reste en anglais
(`created_at`, `User`, contrôleurs `index/store`…).

**Pourquoi.** Le métier n'a pas de bonne traduction (bord-champ, pisteur, KOR,
grainage, campagne, warrantage) et toute la documentation est en français : un nom
traduit oblige à traduire dans sa tête à chaque lecture.

## D9 — L'IA conseille, elle ne décide pas (2026-09-25)

**Choix.** Le LLM ne produit jamais un nom de produit ni une dose qui ne vienne pas du
référentiel validé par l'agronome ; tout diagnostic est « proposé » jusqu'à
confirmation humaine. Voir la skill `ly-agricole-ia-conseil`.

**Pourquoi.** Un LLM peut inventer une réponse plausible ; une dose de pesticide
inventée peut intoxiquer un producteur ou faire refuser un lot à l'export.

## D10 — SMS derrière une interface, fournisseur choisi plus tard (2026-09-25)

**Choix.** `App\Services\Sms\EnvoyeurSms` (interface) avec un pilote `journal` (écrit
dans les logs) pour le développement ; le pilote réel (fournisseur ivoirien ou
agrégateur) sera branché quand le fournisseur est choisi. Même principe pour Mobile
Money : saisie manuelle de la référence en phase 1, API en phase 2.

**Pourquoi.** Le choix du fournisseur n'est pas fait (question ouverte) et ne doit
pas bloquer le développement.

## D11 — Le serveur de l'appli terrain est en ligne, en HTTPS (2026-09-28)

**Choix.** Dès le pilote (sem. 11), l'appli terrain parle à un serveur **en ligne en
HTTPS**. Le http n'est accepté que pour le développement : l'appli le refuse sauf
pour `localhost`, les adresses privées (10.x, 192.168.x, 172.16-31.x) et les noms en
`.test` / `.local` (`terrain/src/lib/serveur.ts`) ; la build Android n'autorise le
trafic en clair que si `LY_TERRAIN_DEV=1` (`capacitor.config.ts`).

**Pourquoi.** Réponse à la question 27, décidée par l'utilisateur « selon la
recommandation, en vue du projet ». Les agents envoient depuis les villages par les
données mobiles : un serveur sur le réseau local du bureau ne leur serait pas
joignable. Et le jeton, les poids et les montants ne doivent pas circuler en clair.

**Ce qui la ferait changer.** Rien pour la production. Pour un essai au bureau, une
build de dev suffit.
