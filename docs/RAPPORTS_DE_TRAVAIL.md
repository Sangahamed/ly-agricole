# Rapports de travail

Une entrée par session, la plus récente en haut : ce qui a été fait, ce qui a été
**vérifié en l'exécutant** (et comment), ce qui reste, ce qui a surpris.

---

## 2026-10-06 (soir) — Direction sans validation, modifier / supprimer pour chacun, apport investisseur en Trésorerie — FAIT, TESTÉ, PAS ENCORE VU DANS LE NAVIGATEUR NI DÉPLOYÉ

**Demande.** « La direction doit pouvoir modifier ou supprimer tout ; quand elle crée un prêt ou
autre, pas besoin de validation, c'est le compte supérieur » ; « permettre à chaque utilisateur
de modifier ou supprimer ce qu'il fait » ; « dans Trésorerie, Nature : ajouter investisseur » ;
une explication de chaque onglet (comment créer, dépendances) et de l'onglet Apports, en
document (doc Claude « Les onglets de la Direction »).

**Fait (DECISIONS D5 et D6 complétés).**

- **Direction = validée dès la saisie** : `Prets::demander` (statut validé, 1 validation requise,
  `ValidationPret` à son nom), `Depenses::saisir` (payée, `valide_par = cree_par`),
  `Achats::enregistrer` et `Ventes::enregistrer` (`executer(..., validation: true)`). Fiche du prêt :
  « accordé directement par la direction, sans 2e accord ». Formulaires : phrase adaptée.
- **Supprimer par l'auteur ou la direction** : droit `annuler-operation` (sur la ligne, `cree_par`),
  utilisé par `Achats::annuler`, `Depenses::annuler`, `SuppressionFiches::producteur` (et sa route),
  et deux nouveaux : `Prets::annuler` (seulement si rien n'a été remis) et `Ventes::annuler`
  (encaissements contre-passés, kilos rendus via `Stock::annulerSortieVente`, lot « vendu » →
  « ouvert »). Statuts `StatutPret::Annule`, `StatutVente::Annule` ; migration
  `annule_par / annule_at / motif_annulation` sur `prets` et `ventes`. Plafond par producteur :
  un prêt annulé ne compte plus. `Encaissements::encaisser` refuse une vente refusée ou annulée
  (trou existant avant : une vente refusée pouvait être encaissée).
- **Modifier = remplacer** : `?corrige=<id>` sur les formulaires prêt, vente, achat, dépense :
  pré-rempli ; à l'enregistrement, dans une transaction, annulation de l'ancien puis nouvelle
  saisie (nouvelle référence ; motif de l'ancien « Modifié : remplacé par … »). Liens « Modifier »
  sur les fiches prêt et vente et dans les listes achats et dépenses.
- **Trésorerie → Entrée → Nature « Apport d'un investisseur (campagne) »** : choix de
  l'investisseur (ou LY) ; passe par `Apports::enregistrer` (même registre que /apports, compte
  dédié à la campagne obligatoire, refus sinon).

**Vérifié en l'exécutant.** Suite : 818 tests (806 + 12 de `DroitsDirectionEtAuteurTest`), 7
anciens tests adaptés à la nouvelle règle (ils encodaient « direction seule » ou « la direction
attend un 2e accord »), puis 817/818 : le seul échec, `RendementsTest::la_carte_classe_les_parcelles_par_cinquiemes`,
passe seul et au premier passage — il indexe par **nom** de producteur Faker (doublon possible) :
test instable préexistant, à corriger (indexer par id). Larastan : 0 erreur sur les fichiers
touchés. Pint propre. Migration jouée sur la base MySQL locale.

**Pas vérifié.** Le parcours dans Chrome : l'extension ne transmettait pas la saisie au formulaire
Livewire (texte tapé non reçu, clic sans requête) — à refaire à la main : direction → nouveau
prêt → « Validé » ; agent → supprimer / modifier son achat ; Trésorerie → apport investisseur.
`php artisan serve` relit le `.env` (qui pointe sur Neon) dans son processus enfant : servir en
local avec `php -S` depuis `public/` et les `DB_*` MySQL dans l'environnement, ou remettre MySQL
dans `.env`. Pas déployé sur ylagro.com (fusion dans `Ly-agro/ly-agricole` à faire).

**À décider (responsable projet).** Informer les investisseurs que le contrôle à deux personnes
ne couvre plus les opérations de la direction (contrat art. 9, 17.3) ; faut-il un montant
au-delà duquel même la direction doit avoir un 2e accord ?

---

## 2026-10-05 / 06 — Mise en production sur Vercel + Neon (ylagro.com) — EN LIGNE, CONNEXION RÉELLE À ESSAYER

**Demande.** Le site déployé sur Vercel (domaine OVH `ylagro.com`) tombait en
`QueryException`. Le remettre en marche, puis constater sur le site et corriger.

**Architecture de production constatée.** Vercel (runtime `vercel-php@0.9.0`, PHP 8.5, entrée
`api/index.php`) ; base **Neon PostgreSQL** (`eu-central-1`, et non MySQL) ; Vercel déploie le dépôt
**`Ly-agro/ly-agricole`**, pas `Sangahamed/ly-agricole` : chaque push doit y être fusionné (PR ;
le compte `Sangahamed` peut ouvrir une PR mais **pas la fusionner**).

**Fait (commits `d8138f2` → `12672e2`, PR Ly-agro #3, fusion `5d07457`).**

- **Connexion Neon** : la libpq du runtime Vercel n'envoie pas le SNI → « Endpoint ID is not
  specified ». L'astuce `endpoint=…;` dans le mot de passe a échoué sur Vercel (et fait REFUSER le
  mot de passe avec une libpq récente, XAMPP). Retenu : `App\Support\ConnecteurPostgresNeon` ajoute
  `options='endpoint=ep-…'` au DSN, seulement si `DB_NEON_ENDPOINT` (posé par `api/index.php`).
  `config/database.php` lit aussi les `POSTGRES_*` de l'intégration Neon, décompose une `DB_URL`
  (sinon Laravel l'applique par-dessus à la connexion), `sslmode=require` pour un hôte Neon.
- **Sécurité** : `APP_DEBUG=true` dans les réglages Vercel publiait la page d'erreur détaillée
  (cookies, en-têtes, jeton OIDC Vercel). `api/index.php` **impose** `APP_DEBUG=false`.
- **Journaux** : Vercel ne garde que la fin d'une ligne trop longue → le message de l'exception
  disparaissait derrière la pile. Canal `vercel` (une ligne, sans pile), imposé par `api/index.php`.
- **Divers Vercel** : `BROADCAST_CONNECTION=log` par défaut (pas de Reverb en serverless) ;
  région **`fra1`** à côté de Neon (au lieu de `iad1`) ; Vercel Cron `/cron/alertes` à 7 h
  (`TacheCronController`, `CRON_SECRET` en `Bearer`, liste fermée de tâches).
- **`/prix` en 504** : `tableauCampagnes` lisait les prix une fois par campagne et par produit
  (centaines de requêtes × latence distante). Deux requêtes pour le tableau, 27 pour la page.
- **Comptes et données** : `php artisan ly:creer-compte --role=admin|direction` (saisie masquée) ;
  `ProductionSeeder` = cultures + prix bord-champ PUBLIÉS des campagnes passées (seeders existants,
  sources), aucun compte. Lancés sur Neon par le développeur (37 produits, 119 prix, 2 comptes).
- **« Mon mot de passe »** (menu du compte, `ChangerMotDePasse`) : mot de passe actuel exigé,
  5 essais, journalisé masqué (trait `Journalise`), jeton « rester connecté » renouvelé.
- `.env.example` rétabli (supprimé par un commit `mkp`) avec la liste des variables de production.

**Vérifié en l'exécutant.**

- 798 tests verts (786 → 798), Pint propre. Tests lancés avec `.env` basculé sur sqlite
  (le `.env` local pointait sur Neon = production).
- Connexion à Neon depuis le poste, avec et sans l'option `endpoint` : OK.
- En ligne après fusion : `/` 200 en 1,5 s, `/prix` 200 en 1,9 s (avant : 504), `/actualites`,
  `/connexion`, `/up` 200 ; `/mon-mot-de-passe` → connexion ; en-tête `x-vercel-id` en `fra1` ;
  CSS et JS 200 ; `/diagnostic/base` (route temporaire, retirée) 404.

**Pas vérifié.** Connexion avec un vrai compte et changement de mot de passe en ligne ; le cron
avec le bon secret (403 au dernier essai : `CRON_SECRET` absent de Vercel à ce moment-là) ;
notifications push (clés VAPID à recopier dans Vercel, `QUEUE_CONNECTION=sync`).

**Reste à faire (développeur).** Recopier dans Vercel `CRON_SECRET`, `PUSH_VAPID_*`,
`QUEUE_CONNECTION=sync`, puis supprimer `secrets-vercel.txt`, `cles-vapid.txt`, `env.sauvegarde`
(dans `C:\xampp\htdocs`) ; retirer `APP_DEBUG=true` des réglages Vercel ; remettre MySQL dans le
`.env` local. Sauvegardes : `ly:sauvegarder` (mysqldump) ne marche ni sur Vercel ni avec Neon —
à repenser (historique de restauration Neon, selon l'offre). `notifications:alertes` est la seule
tâche planifiée branchée : `vitrine:actualites` ne tourne pas en production.

**Ce qui a surpris.** Le mot de passe Neon s'est affiché en clair deux fois pendant la session
(URL complète collée dans `DB_HOST`, puis un script de contrôle mal masqué) : régénéré depuis.
La page Logs de Vercel fige l'onglet de l'extension Chrome : lire les journaux à la main.

---

## 2026-10-02 — Suivi des agents en direct (Reverb), comptes d'agents, « Supprimer », anacarde — FAIT, À VOIR DANS LE NAVIGATEUR

**Demande.** Chaque action d'un agent → notification push au responsable (Reverb) ; le responsable
crée les comptes d'agents ; il peut « supprimer » ; l'anacarde mise en avant sur la vitrine, avec
des images animées de la noix à la transformation.

- **Décisions demandées et prises** : « Supprimer » = annuler (contre-passation, sauf ce qui n'a
  encore rien changé) ; la direction crée les comptes d'**agents seulement** (DECISIONS D5).
- **Suivi des agents** (`SuiviAgents`, branché sur la création d'une ligne du journal) : toute
  action d'un agent (sauf connexion/déconnexion) envoie un avis « Activité agent » à la direction :
  liste, push, et diffusion en direct. Titre sans nom de producteur ni montant (écran verrouillé).
- **Reverb** : `laravel/reverb` 1.12 (Guzzle redescendu de 8.2 à 7.15, seule combinaison possible),
  `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, Echo + pusher-js ; canal
  `broadcast` ajouté à `AvisLy` seulement si un diffuseur réel est configuré ; bandeau et cloche mis
  à jour sans recharger. Clés locales générées dans `.env` (pas dans le dépôt).
- **Comptes d'agents** : droits `gerer-agents` / `ouvrir-comptes` ; écran « Comptes des agents » pour
  la direction (ne voit, ne crée, ne modifie que des agents ; refus testé même en forçant l'id).
- **Supprimer** : `Achats::annuler` (stock, remboursement en kilos et caisse contre-passés ensemble,
  refus si le lot n'a plus les kilos, tout ou rien), `Depenses::annuler`, `SuppressionFiches::producteur`
  (vraie suppression seulement sans prêt/achat/visite/SMS/décision ; photo effacée après commit).
  Migration `annule_par`, `annule_at`, `motif_annulation` sur `achats` ; statut « Annulé ».
- **Vitrine** : section `#anacarde` après l'accueil, 9 étapes illustrées en SVG animé (semis,
  croissance, floraison, pomme et noix, récolte, séchage, pesée, stockage, transformation), lecture
  automatique quand la section est visible, pause, flèches, chemin cliquable ; dernier prix bord-champ
  publié affiché (jamais inventé) ; bouton « Découvrir l'anacarde » dans l'accueil. Respect des
  retraits faits à la main sur la vitrine (liste repliée et « autres cultures ») : tests adaptés.
- **Vérifié** : 779 tests (761 → 779), Pint. **Pas encore vu dans le navigateur** : le serveur a
  été arrêté faute de mémoire sur le poste ; animation de la vitrine et avis en direct à regarder.

---

## 2026-09-30 (soir) — Prix sur la vitrine repliés, « Voir tous » immédiat, test d'avis stabilisé, fusion — FAIT

- **Vitrine** : 6 cartes de prix d'emblée (anacarde, karité, tomate, cacao, café, hévéa), les
  27 autres derrière « Afficher les autres cultures » (tableau replié), bouton « Voir tous les prix ».
- **`/prix`** : recherche d'une culture dans le tableau à chaque lettre (sans accents) ; filtres
  des courbes appliqués dès le choix, sans bouton ni rechargement (zone des courbes remplacée,
  adresse mise à jour, bouton gardé sans JavaScript) ; campagnes proposées : celles du produit
  choisi seulement (116 sinon) ; dates visibles seulement pour « Dates personnalisées ».
  Vu dans Chrome : « ba » → 2 cultures ; Hévéa puis campagne 2023-2024 → courbe et info-bulle.
- **Actualités : NewsAPI écarté** (décision de l'utilisateur, 2026-09-30) : le plan gratuit est
  réservé au développement (interdit sur un site en ligne, articles d'au moins 24 h) ; on reste sur
  les flux RSS. Clé fournie non enregistrée.
- **ngrok** : jeton du compte LY accepté ; tunnel `https://onward-singer-creme.ngrok-free.dev`
  (domaine fixe du compte) vérifié : pages en https, filtres de `/prix` à travers le tunnel.
- **Test d'avis instable** : `notifications()` trie déjà du plus récent au plus ancien, `oldest()`
  ne faisait qu'ajouter un second critère ; le test ne passait que par hasard dans une même
  seconde. `reorder()->oldest()` et une seconde d'écart (NotificationsTest, AlertesTest).

---

## 2026-09-30 (suite) — Prix relevés des autres cultures, courbe refaite, contact, ngrok — FAIT, À FAIRE RELIRE

- **Prix** (`HistoriquePrixCulturesSeeder`, d'après `docs/Prixrelever1-3`) : 95 prix, 29 cultures,
  un par année de campagne, tous avec lien. Coton, karité, canne : prix officiels ; hévéa, palmier :
  moyennes des prix mensuels/par période (APROMAC, CHPHC) ; riz, maïs, vivriers : ANADER, année
  civile Y rangée dans (Y-1)-Y. Chargé dans MySQL (24 → 119 prix).
- **Écartés** : prix de marché 2025-2026 sans lien de Prixrelever1 (N'kalo, Fratmat… dont des prix
  de détail à Abidjan) ; « proxies » ANADER de Prixrelever2 pour hévéa (283/312/272), palmier
  (207/244/225, invraisemblable pour le régime payé 65-80) et riz (194/238/302), contredits par
  APROMAC, CHPHC et ADERIZ (Prixrelever3). Sans source : sésame, piment, hibiscus, poivre.
- **À relire** : palmier 2022-2023 (77) et 2023-2024 (66) contiennent un mois estimé (≈ 71) ;
  canne 2024-2025 arrondie (20 250 F/t → 20 F/kg) ; coton 2020-2021 : 300 (KOACI dit 270).
- **Courbe** (`CourbeSvg` + `prix/evolution`) : aire en dégradé sous l'escalier, grille en filets,
  repères de temps sur dates rondes (« 2024 », « oct. 2025 »), pastille du dernier prix, réticule
  qui suit le pointeur et info-bulle (prix en vigueur, date, source) ; en-tête prix en grand +
  variation. Vu dans Chrome (hévéa, survol à 2022-2023 → 295, source APROMAC).
- **Contact** : direction@ylagro.com, 07 78 15 58 78 (`config/vitrine.php`, « Nous trouver » et
  pied de page, liens mailto/tel) ; `MAIL_FROM_ADDRESS` et sujet VAPID mis à jour.
- **ngrok** : `ngrok.yml` (tunnel `ly-agricole` → localhost:8000, sans jeton ; jeton du compte LY dans
  `%LOCALAPPDATA%/ngrok/ly-agricole.yml`, pas celui de Tharamotors) ; `trustProxies`
  limité à 127.0.0.1/::1. Config validée par `ngrok config check` ; en-têtes X-Forwarded simulés →
  ressources en https. Tunnel non lancé.

---

## 2026-09-30 — Prix des campagnes passées (cacao, café, anacarde) — FAIT, À FAIRE RELIRE

- 37 cultures (`CulturesSeeder`) ; tableau d'ensemble de `/prix` : cultures × 7 dernières
  années de campagne (octobre à septembre : l'anacarde de février 2024 est dans 2023-2024).
- `HistoriquePrixSeeder` : 24 prix relevés dans la presse ivoirienne (Abidjan.net, KOACI,
  AIP, Fraternité Matin, Financial Afrik, Journal d'Abidjan, Conseil du Café-Cacao), chacun avec
  son lien ; 20 campagnes créées **clôturées**, sans prix officiel. Relançable sans doublon.
- **Réserves** : source = presse, pas le site du Conseil du Café-Cacao ; date d'effet = annonce ou
  lancement ; non vérifiés au jour près : cacao intermédiaire 2020-2021 (avril 2021) et
  2024-2025 (avril 2025) ; café 2020-2021 (550, relevé indirectement). Rien avant 2019 ni pour les
  autres cultures (karité, tomate, maïs…) : pas de source trouvée. Prix 2026-2027 (cacao 1 200,
  café 1 300) non saisis : ils entrent en vigueur le 1er octobre.

---

## 2026-09-29 — Courbes d'évolution des prix et actualités depuis internet — FINI

**Demande.** « Les actualités et les prix bord-champ ne peuvent pas être récupérés depuis
internet, avec courbe d'évolution par campagne et période ? »

**Fait.**

- **Courbes** : page publique `/prix` (lien depuis la vitrine). Un graphique par produit
  (café, cacao, anacarde…), courbe en escalier (un prix vaut jusqu'au changement suivant),
  filtre par produit et par période (tout, 6 mois, 12 mois, une campagne, dates libres),
  tableau des valeurs et tableau « campagne par campagne ». SVG fait côté serveur
  (`Support/CourbeSvg`), infobulle au survol et au clavier, une seule échelle par graphique.
- **Actualités depuis internet** : flux RSS / Atom choisis par la direction (onglet
  « Sources d'actualités », bouton « Récupérer maintenant », commande
  `vitrine:actualites` planifiée à 06:00). Tout arrive en **brouillon « à relire »** avec
  titre, court extrait en texte simple et lien vers l'article d'origine ; rien n'est public
  sans publication par la direction. Pas de doublon (empreinte source + lien), adresses
  internes refusées (localhost, réseau privé, métadonnées cloud), pas de redirection, flux
  avec déclaration d'entités refusé, une source en panne n'arrête pas les autres.
- **Prix : pas de récupération automatique.** Aucun flux officiel n'existe (les sites
  consultés refusent les robots ou n'ont pas de flux) et un prix faux affiché publiquement
  est pire que pas de prix : les prix restent saisis avec source et date (question 39).

**Vérifié.** 730 tests verts (+ 11 courbe, 18 évolution, 23 récupération), Larastan 0, Pint.
Courbe vue dans Chrome sur `/prix?produit=1`.

**Reste à décider (question 39).** Quelles sources de flux ajouter ; si une saisie assistée
d'un prix à partir d'un article est souhaitée.

---

## 2026-09-29 — Vitrine : prix bord-champ et actualités — FINI

> Branche `phase-2-suite`. Demande du responsable projet : « un onglet actualités et l'affichage des prix
> bord-champ (café, cacao, anacarde et autres), par récupération ou par saisie ».

**Fait : la saisie.** Registre immuable `prix_marche` (produit, prix au kilo, **date d'effet**, **source
obligatoire**, lien http(s) facultatif, note) et table `actualites` (texte simple, brouillon / publiée).
`App\Services\Publications` ; écran de la direction `/publications` (onglets Prix et Actualités ; droit
`gerer-publications`, **direction seule**). La vitrine affiche, sans connexion, une section « Prix
bord-champ » (prix, date, source cliquable, écart signé au prix précédent) et une section « Actualités »
(3 dernières + page `/actualites` et une page par actualité), avec liens « Prix » et « Actualités » dans le
menu. Les brouillons et les actualités datées du futur n'existent pas pour le public (404) ; le texte est
échappé (jamais de HTML) ; un lien de source doit commencer par http:// ou https://.

**Un prix affiché n'est pas le prix officiel.** Il ne change rien aux achats : le plancher reste
`campagnes.prix_officiel_kg_fcfa`, qui n'est jamais publié tel quel. L'écran et la vitrine le disent ; le
bouton « Reprendre le prix officiel de la campagne ouverte » ne fait que pré-remplir le formulaire. (Point
confirmé avec l'autre session : ses alertes concernent le prix de campagne, pas ces prix affichés.)

**Pas fait : la récupération automatique.** Aucune source n'est choisie, les sources officielles n'ont pas
d'interface stable connue, et un prix faux affiché publiquement engage LY. Question ouverte n° 39.

**Au passage.** La vitrine est découpée en morceaux partagés (`resources/views/vitrine/*` et le composant
`x-vitrine.page`) pour que les pages d'actualités aient le même en-tête, style et pied ; rendu inchangé.
`php artisan migrate` a été lancé sur la base de dev (9 migrations en attente, ajouts seulement).

**Vérifié en l'exécutant.** 34 tests (validations, source obligatoire, lien dangereux refusé, immuabilité,
prix en vigueur par date d'effet, écart signé, droits, vitrine vide / remplie, aucun montant hors de la section
des prix, brouillons et futur invisibles, texte échappé, liens du menu, écran de gestion) ; deux défauts
trouvés et corrigés (comparaison de dates en SQLite, message inversé au basculement de publication).
**Vu dans Chrome** sur une base de contrôle (supprimée) avec des données d'essai : menu, section des prix,
page des actualités. Pas vu : la section « Actualités » de l'accueil ni la largeur téléphone.

**Reste.** Question 39 (sources à suivre, validation avant publication, flux externe).

---

## 2026-09-29 — Commission des pisteurs (question 6, repris de B) — FINI

> Branche `phase-2-suite`. Dernier des cinq blocs repris de B.

**Décision du responsable projet (question 6).** Les achats via pisteurs sont possibles ; « la commission
et son mode de calcul / paiement » restent à définir ; proposition : « prévoir dès maintenant le champ pisteur
et la commission dans le modèle d'achat, même si le calcul automatique est activé ultérieurement ».

**Fait, et seulement cela.** Règle de commission **par pisteur** (`ModeCommission` : FCFA par kilo, ou pour mille
du montant), saisie dans Référentiels › Pisteurs, **vide** tant que la direction ne l'a pas choisie (mode et
valeur vont ensemble ; entier ; ≤ 1 000 ‰). Colonne `achats.commission_pisteur_fcfa` (nullable). **Calcul
automatique désactivé par défaut** : paramètre `calcul_commission_pisteur` (aucun / automatique), à activer par
la direction. `App\Services\CommissionsPisteur` : arrondi au franc le plus proche en entiers, exact au-delà de
2^31 ; seul un achat dont le **vendeur est un pisteur** porte une commission ; `due()` = somme des achats
**validés** (jamais « à valider » ni refusés). Affichée par pisteur et dans la liste des achats.
**C'est une somme due, pas un paiement** : quand et comment elle est payée n'est pas défini, et elle n'entre
pas dans le résultat de campagne tant qu'elle n'est pas payée par une dépense (contrat art. 10.2).

**Vérifié en l'exécutant.** 13 tests (calcul par kilo et en pour mille, arrondis, très gros montant, sans règle
complète, calcul non activé, achat au pisteur, achat à un producteur, achats validés seulement, formulaire et
ses refus, affichage, paramètre). Pas vu dans un navigateur.

**Reste.** Question 6 : montant, moment et mode de paiement ; imputation comptable.

---

## 2026-09-29 — Langue par producteur (question 12, repris de B) — FINI

> Branche `phase-2-suite`. Quatrième des cinq blocs repris de B.

**Décision du responsable projet (question 12).** Français d'abord ; préférence de langue par producteur à
prévoir ; langues locales à confirmer ; messages courts et sans accent pour les SMS ; message vocal plus tard.

**Fait.** Table `langues` (code unique en minuscules, nom, actif) et `producteurs.langue_id` (nullable = français).
**Aucune langue locale n'est pré-remplie** : la direction ou l'administrateur les ajoutent (Référentiels ›
Langues) quand elles sont confirmées ; l'écran donne le nombre de producteurs par langue, pour choisir lesquelles
traduire d'abord. Le champ est dans le formulaire producteur du bureau (facultatif, « Français (par défaut) »), sur
la fiche, et **sur le téléphone** (`terrain/` : table locale `langues` en **Dexie version 4**, téléchargée avec
les référentiels, liste affichée seulement si elle n'est pas vide). `/api/sync` accepte `langue_id` (inconnue ou
désactivée ⇒ opération rejetée avec motif ; absente ⇒ français). **Aucun message n'est traduit** : tout part encore
en français ; ce bloc n'enregistre que la préférence.

**Vérifié en l'exécutant.** PHP : 12 tests (aucune langue par défaut, code unique et en minuscules, colonne des
producteurs, droits, formulaire, langue refusée si inactive ou inconnue, fiche, synchro, référentiels). Terrain :
4 tests vitest (version 4, langues gardées avec celles désactivées, complet / delta, ancien serveur) ; **72 tests**
au total, `svelte-check` et build OK. Pas vu sur un téléphone.

**Reste.** Traductions des messages (SMS) : à faire quand les langues seront confirmées.

---

## 2026-09-29 — Appareils et jetons (question 25, repris de B) — FINI

> Branche `phase-2-suite`. Troisième des cinq blocs repris de B.

**Décision du responsable projet (question 25).** Pas d'expiration automatique des jetons ; un compte
désactivé ne peut plus envoyer ; prévoir la liste des appareils, la dernière synchronisation et la
désactivation à distance d'un téléphone perdu.

**Fait.** `App\Services\Appareils` : liste des jetons Sanctum (un appareil = un jeton « LY Terrain
xxxxxxxx »), rattaché à sa **dernière synchronisation** par la fin de l'identifiant de l'appareil (même
utilisateur seulement) ; `revoquer()` (un appareil) et `revoquerTous()` (tous ceux d'un utilisateur),
avec **motif obligatoire** et une ligne au journal (`ActionJournal::RevocationAppareil` : qui, quand,
quel appareil, pourquoi). Écran `/appareils`, droit `gerer-appareils` (**direction et administrateur** ;
pas le comptable ni l'agent). Aucune migration. Côté téléphone, seul le message de session expirée
mentionne désormais « appareil coupé par le bureau ».

**Vérifié en l'exécutant.** 14 tests, dont le point crucial : **le jeton d'un appareil coupé est refusé
(401) à la requête suivante** sur `/api/referentiels` et `/api/sync`, alors que les autres appareils du
même utilisateur et ceux des autres continuent de marcher ; journal ; motif et droits ; liste ; écran.
Ce que le téléphone perdu n'avait pas encore envoyé reste sur lui, inatteignable pour le bureau.

**Reste.** Aucune alerte « appareil sans synchronisation depuis N jours » (aucun seuil fixé, pas de seuil
inventé) ; à voir avec B, dont les alertes sont le bon endroit.

---

## 2026-09-29 — Balance Bluetooth (repris de B) — FINI, NON VÉRIFIÉ SUR MATÉRIEL

> Branche `phase-2-suite`. Deuxième des cinq blocs repris de B.

**Ce qui est fait.** Côté téléphone, `terrain/src/lib/balance.ts` : lecture des trames texte des
indicateurs de pesage courants (« ST,GS,+ 12.50kg », « 12,5 kg », sans unité = unité choisie),
profil Bluetooth standard « Weight Scale » (0x181D / 0x2A9D, 5 g l'unité), deux services série usuels,
découpage en trames, **attente d'un poids stable** (ST de la balance, sinon 5 lectures identiques ;
jamais « stable » à vide), conversion en **grammes entiers** (jamais de flottant ; plus de décimales
que l'unité ⇒ refusé ; négatif ⇒ refusé), source du poids. Réutilise la méthode de B
(`imprimante.ts` : plugin `@capacitor-community/bluetooth-le`, Web Bluetooth dans Chrome). Composant
`PeseeBalance.svelte` dans le formulaire d'achat : poids en direct, boutons « → Poids brut » et
« → Tare » actifs seulement quand le poids est stable ; un poids tapé à la main reste possible.
**Source du poids** : `balance` seulement si le champ vaut exactement ce que la balance a donné.
Côté serveur : colonne `achats.poids_source` (nullable, enum `SourcePoids`), reçue par `/api/sync`
(valeur inconnue ⇒ opération rejetée avec motif), affichée dans la liste des achats. **Trace, pas
un blocage** ; pas de nouveau contrôle.

**Non vérifié.** Aucune balance ni téléphone ici : le modèle de balance et son format sont inconnus
(question ouverte n° 38). Le Bluetooth classique (SPP) n'est pas géré. Table locale : aucune (Dexie
reste en version 3, la version 4 est libre).

**Vérifié en l'exécutant.** Terrain : **68 tests** vitest (dont 32 nouveaux : trames, stabilité,
profil standard, source), `svelte-check` 0 erreur, `npm run build` OK ; il a fallu `npm install` dans
`terrain/` (les plugins Bluetooth et push de B n'y étaient pas). PHP : 7 tests (source gardée, non
inventée, refusée si inconnue, sync, liste). Pas vu sur un téléphone.

**Reste.** Question 38 : essai sur la vraie balance avant le pilote.

---

## 2026-09-29 — Groupes et caution solidaire (repris de B) — FINI

> Branche `phase-2-suite`, sur `phase-2-alertes`. Premier des cinq blocs repris de B.

**Choix de conception.** Les groupes existaient déjà (`groupes_producteurs`, écran de gestion,
`groupe_id` du producteur). Le cahier ne dit pas COMMENT le groupe se porte caution : rien n'est
inventé. Nouveau paramètre à choix `regle_caution_solidaire` (`RegleCautionSolidaire` : aucune /
avertir / bloquer), **désactivé tant que la direction n'a rien choisi** (les prêts ne changent pas).
`App\Services\CautionSolidaire::controle()` : quand un AUTRE membre du groupe a un prêt en retard (même
définition que la fiabilité), un prêt à un membre est signalé ou refusé (à la demande ET à la validation,
puisque le retard peut apparaître entre les deux). Message **générique**, jamais le nom ni les montants
d'un autre producteur ; le détail nominatif est réservé à la direction et à la comptabilité.
Question ouverte n° 37.

**Écrans.** `/fiabilite/groupes` (liste : membres, remis, restant dû, membres en retard) et
`/fiabilite/groupes/{groupe}` (situation nominative, règle en vigueur), droit `voir-fiabilite` ;
avertissement dans le formulaire de prêt de l'agent ; lien « Groupes » depuis `/fiabilite`. Aucune table
nouvelle.

**Vérifié en l'exécutant.** 14 tests (inactif par défaut, avertir, bloquer à la demande et à la
validation, message sans nom, membre seul / autre groupe / propre retard, totaux, paramètre, droits, écrans).
Pas vu dans un navigateur.

**Reste.** Question 37 (responsabilité du groupe, limites, exceptions).

---

## 2026-09-29 — Décisions des questions 32 et 35 : valorisation du stock et décision de plafond — FINI

> Branche `phase-2-suite` (depuis `fusion-phase-2`). Le responsable projet a rempli les décisions des
> questions 35 et 32 dans son fichier de réponses.

**Question 35 (fiabilité).** Règle prudente **confirmée comme provisoire** (aucune augmentation
automatique, rien sans historique favorable) ; l'écran le dit. Nouveau registre immuable
`decisions_plafond` + `App\Services\DecisionsPlafond` : la direction (seule) enregistre le plafond
**retenu**, le logiciel garde sa **proposition**, sa raison, les **données utilisées** (synthèse et
prêts), la **date du calcul**, le motif et l'auteur. Un motif est exigé si la décision diffère de la
proposition ; jamais au-dessus du plafond des Paramètres ; 0 est permis (« pas de nouveau prêt »). Le
comptable lit l'historique, ne décide pas ; l'agent et le producteur ne voient rien. Trace seulement :
le plafond retenu n'est pas appliqué automatiquement à la création d'un prêt.

**Question 32 (résultat).** Arrondi et avances hors résultat : **confirmés** (déjà codés).
(c) Perte supérieure aux fonds : libellé « **Perte non imputée — traitement à décider** », rien
attribué automatiquement. (d) Stock invendu : la saisie libre de l'écran `/resultat` est **remplacée** par
un formulaire à **deux offres écrites de deux fournisseurs différents** + valeur retenue par la direction
(registre immuable `valorisations_stock` + `ValorisationsStock`) ; `ResultatCampagne::etat()` prend
la valorisation enregistrée (0 sans elle). **Conditions de clôture** affichées sur l'écran
(`ResultatCampagne::conditionsDeCloture`) : stock valorisé, perte au-delà des fonds traitée, avances
présentées ; tant qu'une manque, « résultat provisoire, non définitif ». Toujours rien de montré aux
investisseurs.

**Vérifié en l'exécutant.** Tests des deux registres, des conditions de clôture, des écrans et des
droits : voir le comptage de la fin de session. Pas vu dans un navigateur.

**Reste.** Traitement d'une perte au-delà des fonds (règle métier) ; rapport final (art. 18.2) ;
politique d'information du producteur.

## 2026-09-30 — IA : sources de données et script d'entraînement — FINI, branche `phase-3-ia`

> Décision de l'administration : un agronome **dans 3 mois environ** ; on démarre sans lui,
> il valide tout avant la mise à disposition réelle. LY trouve elle-même données et photos.

**Recherche des jeux publics** (licences lues sur les pages officielles) : **CCMT**
(Ghana, photos de terrain, anacarde 5 classes et tomate 5 classes, validé par des
virologues, **CC BY 4.0** : citer les auteurs) retenu comme point de départ ;
PlantVillage (CC0 sur Mendeley, CC BY 3.0 selon une autre source) en complément seulement
(fond uni de labo, biais connu) ; **aucun jeu public pour le karité**. Détails, attribution,
correspondance des classes et protocole photo des agents : `docs/DONNEES_IA.md`.

**Script d'entraînement** (`ia/entrainement/`) : MobileNetV3-small, pour une machine louée
avec carte graphique ; lit le jeu CCMT brut et l'export LY (`ia:exporter-jeu`), classes
traduites par une table (une classe inconnue **arrête** le script), jeu de test fixe,
classes rares pesées, export ONNX + `classes.json` + `rapport.json` (rappel et précision
par maladie, matrice de confusion). **Décision de remplacement** : refusée si une seule
maladie recule au-delà de 3 points ou si une classe a moins de 20 photos de test.

**Vérifié.** 22 tests Python (outils d'entraînement : lecture CCMT et export LY, classe
inconnue, provisoires seulement sur demande, répartition fixe d'environ 1/10, mesures par
classe, refus sur recul d'une maladie) ; `entrainer.py` compile. **Non exécuté** :
l'entraînement PyTorch (ni PyTorch ni carte graphique sur ce poste, 1 Go de mémoire
libre) ; le jeu CCMT n'est pas téléchargé (1,2 Go brut, à faire sur la machine louée).

**Reste.** Télécharger CCMT sur la machine louée et faire un premier modèle **d'essai**
(anacarde) ; brancher le modèle ONNX dans `ia/app/diagnostic.py` ; photos de visites.

---

## 2026-09-29 — IA : VPS sans carte graphique, annotations provisoires — FINI, branche `phase-3-ia`

> Réponses du responsable projet : agronome « pas pour l'instant » (53) ; serveur IA « non,
> si possible sur un VPS » (54) ; données d'entraînement « en session Claude en attendant un
> agronome » (55).

**Fait.** Docker Compose **sans carte graphique par défaut** (VPS), fichier
`docker-compose.gpu.yml` à ajouter sur une machine NVIDIA ; petit modèle par défaut
(`qwen2.5:3b-instruct`), délai 300 s, écoute sur 127.0.0.1 si la plateforme est sur le même
VPS. Guide §1 bis (VPS 8 vCPU / 16 Go, données envoyées au VPS : hébergeur, chiffrement,
consentement ; entraînement sur une machine louée à l'heure). **Annotations
provisoires** : colonnes `annotation_*` sur `diagnostics`, commande `ia:annoter` (liste
les photos à annoter avec le chemin du fichier, sans nom de producteur ; annote), écran
« Diagnostics IA » (pastille « Annotation provisoire »), export `--avec-provisoires`
(colonne `source`, jamais au jeu de test).

**Choix.** Une annotation faite en session Claude n'est **pas** une validation : Claude
n'est pas agronome, une maladie mal nommée entraînerait un modèle faux. Elle ne change
pas le statut, ne déclenche aucun conseil, et un agronome qui tranche la remplace.

**Vérifié.** 560 → **561 tests** PHP, 15 Python ; Larastan 0 ; Pint propre. En vrai :
migration sur `ly_agricole_b`, `php artisan ia:annoter` → « Aucune photo à annoter »
(l'unique photo de démo a été validée pendant le parcours agronome, et c'est une image
d'essai, pas une plante).

**Reste.** Des photos réelles de visites à annoter ; VPS à louer ; licence CCMT et
consentement pour l'entraînement.

---

## 2026-09-29 — Phase 3 : socle IA (service ia/, référentiel, diagnostics) — FINI, branche `phase-3-ia`

> Session `ly-agricole-45` (B), branche `phase-3-ia` depuis `phase-2-alertes` (`bb1c0a3`),
> base `ly_agricole_b`. Demande de l'utilisateur : « fais l'ajout de LLM » ; les blocs
> restants de B ont été confiés à la session A. Réponses : préparer l'installation du
> serveur ; **pas d'agronome pour l'instant**.

**Contexte.** Poste de dev : 6 Go de mémoire (1,2 libres), i3 de 2011, pas de carte
graphique, pas d'Ollama — aucun vrai modèle n'y tourne. Le service est donc écrit pour
le serveur IA de LY et testé ici avec un **faux modèle** ; le serveur conseillé est décrit
dans `docs/INSTALLATION_IA.md` (NVIDIA 12 Go au moins, 32 Go, onduleur, chez LY, VPN).

**Service `ia/` (Python FastAPI).** `/sante`, `/conseil`, `/diagnostic`, jeton obligatoire
(fermé par défaut). Le modèle ne reçoit des fiches que leur repère, type, cible et
intitulé — **jamais le nom commercial ni la dose** — et doit citer `[FICHE-n]`.
**Contrôle après génération** (`app/controle.py`) : rejette une dose écrite, un nom de
produit ou de matière active en clair (même retiré ou interdit), une fiche non fournie,
« fongicide / insecticide… » sans fiche chimique citée, un produit chimique avant ou
sans les pratiques et solutions biologiques fournies, une fiche chimique incomplète.
Diagnostic : « incertain » tant qu'aucun modèle de vision n'est entraîné. Docker Compose
(Ollama + service, carte NVIDIA, écoute sur le VPN seulement) ; les tests tournent à la
construction de l'image.

**Plateforme.** Tables `fiches_traitement`, `diagnostics` ; `Referentiel` (agronome
seul, daté, fiche chimique incomplète non proposable), `Diagnostics` (demande par
direction / agronome / agent, traitement en file, validation par l'**agronome seul**,
brouillon de conseil après validation, rendu des repères par le texte validé, brouillon
rejeté si une fiche non fournie est citée). Écrans `/ia/diagnostics` et `/ia/referentiel`,
bouton « Demander un avis IA » sur les visites, commande `ia:exporter-jeu` (photos
validées seulement, sans donnée personnelle, jeu de test fixe). Droits et alias dans
`IaServiceProvider` (pas dans `AppServiceProvider`, fichier partagé).

**Vérifié.** Python : **15 tests** (contrôle et API) ; PHP : 550 → **560 tests** (`IaTest`,
10) ; Larastan 0 ; Pint propre. **En vrai** sur ce poste : service lancé (faux modèle) —
`/sante` répond, `/conseil` sans jeton → 401 ; en **direction**, « Demander un avis IA »
sur la visite avec photo → « 1 photo(s) confiée(s) », worker → `POST /diagnostic` 200 →
diagnostic « **incertain** » en base avec son motif ; en **agronome**, `/ia/referentiel`
vide avec son explication et le bouton « Nouvelle fiche ».

**Parcours agronome** (« tu es l'agronome pour l'instant », compte de démonstration, sans
Chrome ni serveur web faute de mémoire : vraies fonctions de la plateforme, vrai service
par HTTP, vraie file d'attente) : fiche d'**ESSAI** « désherber autour des arbres »
(pratique, sans produit) → proposable ; diagnostic « incertain » validé « sain » →
« corrigé » ; brouillon demandé → worker → `POST /conseil` 200 → statut « brouillon »,
fiche 1 citée, repère remplacé par le texte validé. Fiche d'essai ensuite **retirée**
(plus proposable). **Aucune vraie fiche de produit ni de dose n'a été saisie** : le
référentiel ne se remplit pas de mémoire (skill IA, règle 6) ; il attend un agronome.
Défaut vu au rendu et corrigé : le faux modèle écrivait l'intitulé ET le repère
(intitulé en double) ; il n'écrit plus que le repère.

**Pas vérifié.** Aucun vrai modèle (Ollama) : pas de serveur IA. Les processus lancés
plus tôt (service, plateforme, worker) avaient été arrêtés faute de mémoire sur le poste.

**Reste.** Questions 53 (agronome), 54 (serveur IA), 55 (jeu CCMT,
consentement pour l'entraînement) ; brancher un modèle de vision (ONNX) quand il y aura
des photos confirmées.

---

## 2026-09-29 — Phase 2 : alertes quotidiennes et avis de campagne — FINI, branche `phase-2-alertes`

> Session `ly-agricole-45` (B), branche `phase-2-alertes`, au-dessus de `phase-2-notifications`
> **après fusion de `fusion-phase-2`** (commit `e851c4e` : 544 tests, conflits des fichiers
> partagés résolus en gardant les deux côtés). Base `ly_agricole_b`. Bloc réclamé auprès
> de la session A, qui a posé deux garde-fous : push générique, et alertes d'argent
> seulement aux rôles qui voient déjà ces écrans.

**Pourquoi.** Réponses du responsable projet : « tous les avis sont les bienvenus », à
« toute personne ayant les permissions nécessaires » (question 52) ; imprimante : pas de
modèle précis, Bluetooth basse énergie (question 50, déjà codé).

**Fait.** `App\Services\Alertes` + commande `notifications:alertes` (planifiée à 7 h) :
saisies à valider depuis plus de 48 h (un rappel par personne et par jour, sans ce que la
personne a saisi elle-même) ; prêts en retard (définition de `FiabiliteProducteur`, sans
nom ni montant) → `voir-prets` ; poste de budget dépassé → `voir-budget` (une fois par
poste et montant prévu) ; écarts de poids et sauvegardes, repris du rapport « Alertes » de
`Rapports` (semaine 10, non modifié) → `voir-rapports`. Table `alertes_envoyees` : une
alerte ne part qu'une fois, même si la commande tourne deux fois en parallèle.
`DeclencheursCampagne` : campagne ouverte, prix officiel annoncé ou changé →
`saisir-achats`. **Push générique** pour tous les avis (`AvisLy::pourPush()`, texte
« Ouvrir LY AGRICOLE pour voir le détail. » par défaut) : le détail reste dans
l'application.

**Vérifié.** 544 → **550 tests** verts ; Larastan 0 ; Pint propre. **En vrai** sur
`ly_agricole_b` : migration, `notifications:alertes` → 3 alertes « sauvegardes » (copie
hors site absente, restauration non vérifiée, archives non chiffrées) à la direction et
au comptable, file traitée (18 envois, 0 échec), push parti vers le navigateur abonné ;
relancée aussitôt → 0 partout. Attente, retard et budget à 0 sur ces données (achats en
attente depuis moins de 48 h, aucune échéance dépassée, budget des prêts non dépassé) :
ces cas sont couverts par les tests (`AlertesTest`).

**Choix.** Pas d'avis à la **création** d'une campagne déjà ouverte : elle naît « en
préparation » et c'est l'action « Ouvrir » qui prévient (sinon chaque campagne créée par
un import ou un test envoyait un avis).

**Reste.** Question 51 (projet Firebase pour les téléphones). Tâche planifiée du serveur
(`schedule:run` chaque minute) à installer en production, comme pour les sauvegardes.

---

## 2026-09-29 — Phase 2 : notifications push, tickets 58 mm, motif du budget — FINI, branche `phase-2-notifications`

> Session `ly-agricole-45` (B), branche `phase-2-notifications` (au-dessus de `078bc8b`,
> visites), worktree `../ly-agricole-budget`, **base `ly_agricole_b`** (copie de
> `ly_agricole` : une base par session, voir `docs/REPARTITION_DES_TACHES.md`). Blocs
> demandés par le responsable projet (hors cahier) et réclamés auprès de la session A
> avant la première ligne.

**Notifications.** `App\Services\Notifications::envoyer()` (seul point d'entrée, ouvert
aux autres blocs), notification `AvisLy` (liste dans l'application + push, en file, après
le commit), canaux `WebPushCanal` (navigateurs du bureau, VAPID, `minishlink/web-push` 11)
et `FcmCanal` (appli terrain, Firebase HTTP v1, pilote `journal` par défaut). Déclencheurs
par observateur en lecture seule (`DeclencheursNotifications`) : achat, dépense, prêt,
vente « à valider » → ceux qui valident, sauf l'auteur ; validé / refusé → l'auteur
(motif compris). Bureau : cloche avec compteur dans l'en-tête, page `/notifications`
(filtres toutes / non lues / à valider, regroupées par jour, couleur par sorte d'avis),
activation sur l'appareil (service worker `public/sw-ly.js`). Téléphone : `/api/push`
(jeton Firebase déclaré à la connexion, retiré à la déconnexion), `terrain/src/lib/push.ts`.
Commande `notifications:cles-vapid`. Tables `notifications`, `abonnements_push`.

**Tickets 58 mm.** Bureau : `App\Support\Ticket58` (32 colonnes), `App\Services\Tickets`
(bon d'achat, reçu de remise argent / intrants : mêmes informations que les PDF, que je
n'ai pas modifiés), page `/tickets/...` à imprimer avec le pilote de l'imprimante, liens
« Ticket 58 mm » à côté des PDF. Terrain : `ticket.ts` (même mise en page), `escpos.ts`
(ESC/POS, table PC437 : accents du français gardés), `imprimante.ts` (Bluetooth basse
énergie : plugin Capacitor sur Android, Web Bluetooth dans Chrome), bon de pesée
**provisoire** imprimable juste après l'achat, réimpression depuis « À envoyer », choix de
l'imprimante et essai sur l'accueil. Phomemo M832 : A4, PDF existants via son pilote.

**Budget.** Motif facultatif d'une modification (`lignes_budget.motif_modification`),
entré au journal avec l'ancien et le nouveau montant (réponse 29 du questionnaire).

**Vérifié.** PHP : 404 → **423 tests** verts (budget +1, notifications 13, tickets 5) ;
Larastan 0 ; Pint propre ; terrain : 29 → **36 tests vitest**, svelte-check 0, build OK.
**Dans Chrome** (localhost:8001, base `ly_agricole_b`) : compte comptable → `/notifications`
→ « Activer » (clic et autorisation par l'utilisateur) → abonnement enregistré ; achat
envoyé par l'agent via `/api/connexion` + `/api/sync` (même chemin que le téléphone) →
worker de file : 4 envois `AvisLy`, 0 échec ; avis « Achat ACH-000008 à valider » pour
comptable et direction, **rien pour l'agent auteur** ; push accepté par le service de
Chrome (`dernier_envoi_at` rempli, 0 échec) et **notification Windows affichée**
(confirmé par l'utilisateur). Validation par le comptable dans la liste des achats →
l'agent reçoit « Achat ACH-000008 validé ». Ticket 58 mm d'ACH-000007 affiché à la bonne
largeur (35 kg × 425 = 14 875 FCFA, « en attente de validation » en tête) ; ticket de
remise : restant dû ; 7 liens « Ticket 58 mm » dans la liste des achats. Appli terrain :
bloc « Imprimante 58 mm » sur l'accueil ; « Réimprimer » sans imprimante → « Choisir
d'abord l'imprimante ».

**Pas vérifié.** Impression Bluetooth sur une vraie imprimante (aucune sous la main,
question 50) ; notifications sur le téléphone (ni projet Firebase ni APK, questions 26 et
51) ; Phomemo M832.

**Surprises.**
1. **OpenSSL de XAMPP sans `openssl.cnf`** : impossible de créer une clé EC — donc ni
   clés VAPID, ni **aucun envoi Web Push**, sans message visible. Seule la variable
   d'environnement du processus corrige (`OPENSSL_CONF`), ni `putenv()` ni l'option
   `config`. Noté dans CLAUDE.md ; le canal n'accuse plus les appareils d'une panne du
   serveur (sinon ils étaient tous oubliés au bout de 5 avis) — test ajouté.
2. La pastille de la cloche était décalée : CSS construite avant l'ajout de la cloche
   (classes absentes) et lien « en ligne » contenant un bloc. `npm run build` et
   `inline-flex`.
3. Le bouton « Valider » de la liste des achats ouvre une confirmation du navigateur
   (`wire:confirm`) : elle a bloqué l'onglet piloté ; l'utilisateur a répondu.
4. `sed` a encore mangé des antislashs (`\s` dans une expression régulière de test) :
   corrigé à l'outil d'édition.

**Reste.** Question 51 (projet
Firebase). Réponses 50 (BLE, pas de modèle précis) et 52 (tous les avis, à qui a les droits) : bloc « alertes » à suivre. Fusion : après `fusion-phase-2` de la session A.

---

## 2026-09-29 — Fusion des branches de la phase 1 et de la phase 2 — FINI, à pousser

> Branche `fusion-phase-2`, créée depuis `origin/main` (`450446e`). Demande du responsable projet :
> « fais la fusion, je vais faire le push ». Plan : `docs/PLAN_DE_FUSION.md`.

**Fusionné, dans l'ordre :** `semaine-12` (semaines 6 à 12 : terrain, rapports de gestion,
sauvegardes) → `phase-2-reventes` (A) → `phase-2-visites` au commit `078bc8b` (B : budget, visites).
Conflits : uniquement ceux prévus (`routes/web.php` ; menu `layouts/app` ; `QUESTIONS_OUVERTES.md` et
`RAPPORTS_DE_TRAVAIL.md`), tous résolus en gardant les deux côtés. **Numérotation des questions
adoptée** : celle du fichier de réponses du responsable projet (28 activités, 29 budget, 30 visites,
31 rendement, 32 résultat, 33 exports, 34 écart de poids, 35 fiabilité, 36 qui voit les rapports) ;
les anciennes 28, 29, 30 de `semaine-12` sont devenues 33, 34, 36 (références corrigées).

**Pas fusionné :** `phase-2-notifications` (notifications push, tickets 58 mm de B, en cours), et
`main` local n'a pas été déplacé.

**Vérifié en l'exécutant, sur l'arbre fusionné :**
- `php artisan test` : **525 tests, tous verts** (462 côté A + 26 propres à `semaine-12` = 488 après la
  2e fusion ; + les tests de B). Pint propre ; Larastan 0 erreur.
- Terrain : vitest 29/29, `svelte-check` 0 erreur, `npm run build` OK ; base Dexie versions 1 à 3.
- **Base de contrôle** `ly_agricole_fusion` (créée pour l'occasion, supprimée ensuite) : `migrate` complet
  sans erreur, 0 migration en attente ; rendu de `/`, `/connexion`, `/tableau-de-bord`, `/resultat`,
  `/rapport-campagne`, `/fiabilite`, `/rendements`, `/rapports`, `/budget`, `/visites`, `/apports`,
  `/ventes`, `/prets`, `/producteurs` : tous en 200 pour la direction (`/mon-investissement` : 403, normal,
  réservé à l'investisseur).
- Pas vu dans un navigateur ; l'APK et le mode avion restent à tester sur un vrai téléphone.

**À faire par le responsable projet :** pousser `fusion-phase-2` et ouvrir la pull request vers `main`
(le dépôt fonctionne par PR). La base de dev `ly_agricole` n'a PAS les tables `lignes_budget`, `visites`,
`visite_photo` : `php artisan migrate` avant d'utiliser ces écrans avec elle.

---

## 2026-09-29 — Note de fiabilité du producteur — FINI

> Branche `phase-2-reventes`, après `3e11c9a`. Bloc réclamé par A (`REPARTITION_DES_TACHES.md`).

**Choix de conception.** Le cahier (§10) dit seulement « propose un plafond pour la campagne
suivante ; la direction décide ». Comme il s'agit de personnes réelles et qu'aucune règle n'est
donnée, **aucun score n'est inventé** : ni note, ni « bon » ou « mauvais » payeur, ni coefficient.
`App\Services\FiabiliteProducteur::fiche()` donne l'**historique objectif** des prêts versés (remis,
remboursé en ‰ entier, soldé à temps ou avec X jours de retard, en cours, en retard) et un plafond
**proposé** par une règle prudente : le plus gros prêt soldé à l'échéance ou avant, borné par le
plafond par producteur des Paramètres ; rien sans historique, sans prêt soldé à temps, ou si un prêt
est en retard ; jamais d'augmentation. La direction reste seule à décider (aucune action sur l'écran).
Les remboursements contre-passés ne comptent pas. Question ouverte n° 35.

**Écrans.** `/fiabilite` (producteurs ayant reçu un prêt, recherche, pagination) et
`/fiabilite/{producteur}` (synthèse, plafond proposé et sa raison, prêts, lien vers l'évolution du
rendement). Droit `voir-fiabilite` : **direction et comptable seulement** (jamais l'agent ni l'investisseur).
Ni `ProducteurController` ni la fiche producteur ne sont touchés (fichiers de B).

**Vérifié en l'exécutant.** 18 tests (soldé à temps, plus gros prêt, retard, soldé en retard, contre-
passation, plafond des Paramètres, droits, écrans) au vert du premier coup. Sur la vraie base MySQL :
`/fiabilite` 200 avec 7 producteurs (aucun plafond proposé : aucun prêt soldé en dev), fiche 200.
Pas vu dans un navigateur.

**Reste.** Question 35 (règle de progression, qui voit, information du producteur).

---

## 2026-09-29 — Rapport de campagne : point d'étape (contrat art. 18.1) — FINI, commité

> Branche `phase-2-reventes`, après `a5f6cdc`. Bloc réclamé par A dans
> `docs/REPARTITION_DES_TACHES.md`.

**Fait.** `App\Services\RapportCampagne::pointEtape()` relit les registres (fonds collectés et
apport de LY, kilos achetés / vendus / en stock, achats et charges par catégorie, avances versées
et non remboursées, ventes facturées / encaissées / reste, solde des comptes de la campagne) et
`pointEtapePdf()` en fait un PDF A4 d'**une page**. Les « principaux événements » sont un texte
libre de la direction (3 000 caractères), jamais rempli automatiquement. La note ne contient
**ni résultat net, ni quote-part, ni nom de producteur** (test) : le contrat ne les demande pas à
l'art. 18.1, et un résultat provisoire lu comme définitif tromperait les investisseurs. Écran
`/rapport-campagne` (aperçu + formulaire POST → PDF), droit `voir-rapport-campagne` (direction,
comptable), lien « Rapport de campagne » sous Argent. Noms distincts de `/rapports`, `voir-rapports`
et `resources/views/rapports/` de la branche `semaine-10` (fusion sans collision).

**Vérifié en l'exécutant.** 12 tests ajoutés : 432 → **444** ; Larastan 0 erreur ; Pint propre.
Vrai PDF généré avec les données MySQL de dev : `%PDF`, 1 page (comptée), **vu dans Chrome**
(première version, en-tête, sections 1 à 3) ; deux défauts corrigés après coup (accord « 1 vente
validée » ; signature qui débordait sur une 2e page). Après ces corrections, la relecture dans
Chrome a échoué (rendu figé, poste court en mémoire) : la page unique n'est vérifiée que par le
compte de pages.

**Instabilité corrigée (mon erreur).** `RendementsTest` échouait de temps en temps : ses codes de
campagne explicites (2030-2031…) pouvaient coïncider avec ceux, aléatoires (2030 à 2099), de la
fabrique. Codes déplacés en 1990-1993 ; 3 exécutions de suite au vert.

**Reste.** Rapport final (art. 18.2) : attend la question 32. Les exports PDF/Excel sont déjà dans
`semaine-10` (répartition mise à jour).

---

## 2026-09-29 — Vitrine publique, logo et partage du résultat (contrat art. 10 à 14) — FINI, commité

> Branche `phase-2-reventes`, après `bacfdf5`. Deux demandes du responsable projet : une
> page d'accueil publique (« pour ne pas atterrir direct sur la connexion »), et le calcul
> des articles 10 à 14 maintenant que le contrat est dans `docs/`.

**Contrat.** `docs/CONTRAT_CAMPAGNE_LY_AGRICOLE (1).pdf` est bien le bon document : les
articles 10 à 14 y sont complets, avec les deux exemples chiffrés de l'art. 14. Question
15b **répondue**. (Le PDF contient le RIB de la société : ne jamais le recopier ailleurs.)

**Vitrine.** `/` n'est plus une redirection : page publique (`resources/views/vitrine.blade.php`),
logo `public/images/logo-yl-agro.png`, bouton « Se connecter » (ou « Mon espace » si déjà
connecté). Le logo est aussi dans la barre latérale et sur la page de connexion. Contenu :
uniquement ce que le projet établit (mission, vision, produits, traçabilité, siège). **Rien sur
l'opération d'investissement** : art. 2.3 du contrat, « aucune publicité » ; un test le garde
(mots interdits : investisseur, souscription, FCFA…). Pas de téléphone ni d'e-mail publiés : non
fournis. « Élevage » et « pisciculture » y figurent sur la parole du responsable projet (question 28).

**Partage du résultat.** `App\Services\PartageResultat` (pur, entiers) : art. 12 (40 % / 60 %,
quote-part au prorata investi), art. 13 (perte au prorata des apports ; art. 13.4 faute de
gestion = décision cochée par la direction, jamais déduite), art. 14 repris **tels quels** en
tests. Arrondi (le contrat n'en dit rien) : part globale au plus proche, LY prend le reste,
plus fort reste entre investisseurs ⇒ aucun franc créé ni perdu. `App\Services\ResultatCampagne` :
recettes = encaissements de ventes ; charges = achats validés + dépenses payées non exclues
(art. 10.3) ; valeur du stock invendu (11.3) donnée à la main, jamais devinée ; avances non
remboursées et stock en information seulement. Écran `/resultat` (direction, comptable),
**provisoire**, rien n'est enregistré ni montré aux investisseurs. Question 32.

**Vérifié en l'exécutant.** 390 → **432 tests** (vitrine +2, partage 24, résultat 16), tous verts ;
Larastan 0 erreur ; Pint propre ; `npm run build` OK. Sur la vraie base MySQL : `/` en HTTP → 200,
logo 200, `/tableau-de-bord` anonyme → 302 vers la connexion ; `/resultat` en direction → 200 avec
les vrais chiffres de la campagne 2026-2027. **Dans Chrome** : vitrine vue à l'écran (ordinateur).
Pas vu : largeur téléphone, ni `/resultat` dans un navigateur.

**Pas fait, volontairement.** Résultat visible des investisseurs et rapport final art. 18 (attendent
la question 32) ; contrôles de l'art. 3 (minimum 500 000 FCFA, plafond 10 M, 10 investisseurs au
plus, période de souscription) et échéances de l'art. 6 à 8 : jamais codés, à faire si voulu.

**Fusion des branches.** Numérotation des questions : 29 (budget) et 30 (visites) sont réservées à
`phase-2-visites`/budget ; ici rendement = 31, résultat = 32. `routes/web.php` et le menu seront à
fusionner à la main (ajouts seulement).

---

## 2026-09-29 — Phase 2 : évolution du rendement d'un producteur — FINI, commité

> Suite de `6487feb` (branche `phase-2-reventes`). Dernier élément « tableaux de bord
> de rendement » du cahier §4 réalisable sans les visites.

**Fait.** `Rendements::evolution($producteur)` : une ligne par campagne où il a un
rendement, de la plus ancienne à la plus récente ; l'écart (kg/ha, entier) se mesure à
la campagne précédente **du même produit** — un produit ne se compare pas à un autre,
et la première campagne d'un produit n'a pas d'écart. Page `/rendements/producteurs/{producteur}`
(`EvolutionProducteur`, même droit `voir-rendements`), atteinte par le nom du producteur
dans le classement. Aucune table nouvelle : tout est recalculé.

**Vérifié en l'exécutant.** 4 tests ajoutés : 386 → **390 tests** verts, Larastan 0
erreur, Pint propre. Sur la vraie base MySQL (transaction annulée, comptes 8/1/7 avant
et après) : producteur fictif à 500 puis 650 kg/ha sur deux campagnes du même produit →
page 200, « première campagne » puis « +150 kg/ha ». Pas vu dans un navigateur.

**Limite.** Coût : le classement de chaque campagne est recalculé pour trouver la
ligne du producteur — sans importance à quelques campagnes, à revoir si elles se
comptent par dizaines. Question 31 toujours ouverte.

**Reste du bloc rendements.** Comparaison des pratiques des meilleurs et des moins bons :
attend la saisie des visites et pratiques (module 2), pas encore codée.

---

## 2026-09-29 — Phase 2 : carte des parcelles par rendement — FINI, commité

> Suite de `4c2f383` (branche `phase-2-reventes`). Deuxième moitié du « carte des
> parcelles colorée par rendement » du cahier §4.

**Fait.** `Rendements::carte($campagne)` : parcelles financées avec contour, chacune
avec le rendement de **son producteur** (les kilos sont pesés par producteur, pas par
parcelle : deux parcelles du même producteur portent le même chiffre, dit sur l'écran),
classée par cinquièmes égaux de l'écart min–max (entiers). `Geo\CarteSvg::projeter()` :
projection SVG **commune** à toutes les parcelles, sans fond de carte ni réseau (comme
`Contour::pointsSvg`, qui projette une parcelle seule). Carte + légende + infobulle
(`<title>`) sous le classement de `/rendements`. Rien d'anacarde en dur : le produit
vient de la campagne (précision du responsable projet : plusieurs produits selon la
saison et le prix).

**Choix à connaître.** Un producteur financé qui n'a rien livré a **0 kg/ha** (vrai
rendement, classe la plus faible), pas « inconnu » ; le gris « pas de rendement » ne
sert que si un producteur manquait au classement (cas quasi impossible : contour ⇒
surface > 0).

**Vérifié en l'exécutant.** 5 tests ajoutés : 381 → **386 tests** verts, Larastan 0
erreur, Pint propre. Sur la vraie base MySQL (transaction annulée ensuite, comptes
8/2/7 avant et après) : 3 producteurs fictifs à 200 / 600 / 1 000 kg/ha → page 200,
3 polygones, couleurs clair / moyen / foncé, viewBox `0 0 600 202`, trois carrés côte à
côte d'ouest en est avec les bons écarts. **Non vu dans un navigateur** (extension
Chrome non connectée) : la lisibilité réelle (contraste, taille sur téléphone) reste à
regarder.

**Reste.** Évolution d'un producteur d'une campagne à l'autre ; comparaison des
pratiques (dépend de la saisie des visites). Question 31 toujours ouverte.

---

## 2026-09-29 — Phase 2 : comparaison des rendements — FINI, commité

> Branche `phase-2-reventes`, après `ad360cd`. Bloc « comparaison des rendements » du
> plan de phase 2 (cahier §3 et §4). Ne dépend d'aucune question ouverte bloquante.

**Fait.** `App\Services\Rendements::classement($campagne)` (lecture seule, entiers) :
kg/ha par producteur = poids net des achats **validés** de la campagne ÷ hectares
des parcelles des prêts accordés (validé, décaissé, soldé), chaque parcelle comptée
une fois ; classement, 20 % meilleurs / 20 % moins bons (à partir de 5 producteurs),
moyenne pondérée par la surface. Un producteur sans contour relevé n'a **pas** de
rendement : listé à part, jamais classé. Écran `/rendements` (`ClassementRendements`),
droit `voir-rendements` (direction, comptable), lien « Rendements » sous Terrain,
`Format::entier()`. Fichiers : `app/Services/Rendements.php`,
`app/Livewire/Rendements/`, vue `rendements/classement-rendements`,
`tests/Feature/Rendements/RendementsTest.php` (14 tests).

**Vérifié en l'exécutant.** `php artisan test` : 367 → **381 tests**, tous verts ;
Larastan 0 erreur ; Pint propre. Sur la vraie base MySQL, requête HTTP via le noyau
Laravel : `/rendements` → 302 pour un anonyme, 200 pour la direction, page rendue.
**Non vérifié dans Chrome** (extension non connectée) ; et la base de dev n'a aucune
parcelle rattachée à un prêt, donc l'écran n'a été vu **que vide** en réel — le
classement rempli n'est vérifié que par les tests.

**Reste.** Carte des parcelles colorée par rendement ; évolution d'un producteur d'une
campagne à l'autre ; comparaison des **pratiques** (les pratiques ne sont pas encore
saisies — visites, module 2). Question 31 ci-dessous.

## 2026-09-29 — Phase 2 : visites de parcelle — FINI, commité

> Session `ly-agricole-45`, branche `phase-2-visites` (depuis `92dbebd`), worktree
> `../ly-agricole-budget`. **Travail en double** : la session `ly-agricole-05` codait
> aussi les visites dans `ly-agricole/`. Découvert à la migration (« table visites
> already exists »). L'utilisateur a gardé cette version-ci ; l'autre a été retirée
> (tables vides supprimées, patch gardé dans `%TEMP%/sauvegarde-visites/`). Depuis, les
> deux sessions s'annoncent leur bloc avant de commencer.

**Fichiers nouveaux.** `app/Enums/PratiqueCulturale.php`, `app/Models/Visite.php`,
`database/migrations/2026_12_26_000001_create_visites_tables.php`,
`app/Livewire/Visites/ListeVisites.php` + sa vue, `tests/Feature/Api/VisitesTest.php`
(16 tests) ; appli terrain : `src/routes/visite/+page.svelte`, `src/lib/pratiques.ts`,
`src/lib/photos.test.ts`.

**Fichiers modifiés.** `Synchronisation` (type `visite`), `TerrainController`
(parcelles dans les référentiels, sans contour ; accès avec `saisir-visites`),
`PhotoTerrainController` (photo de visite visible de qui voit les visites),
`Parcelle`/`PhotoTerrain` (relations), `AppServiceProvider` (droits `saisir-visites` :
direction, agent, agronome ; `voir-visites` : direction, agent, comptable, agronome ;
morph map), `ProducteurController` + fiche (« Dernière visite » par parcelle), route et
menu `/visites` ; terrain : `db.ts` (version 3, table `parcelles`), `synchro.ts`
(parcelles téléchargées, parcelles relevées sur le téléphone gardées), page parcelle
(visitable avant l'envoi), `photos.ts` (délai GPS), menu Saisir. `ApiTerrainTest` mis à
jour : l'agronome reçoit désormais les référentiels (il saisit des visites).

**Vérifié.** PHP : 388 → **404 tests**, tous verts ; Larastan 0 erreur (avec
`-d opcache.enable_cli=0`, voir CLAUDE.md) ; Pint propre ; terrain : **29 tests
vitest** (24 → 29), svelte-check 0 erreur, build OK. Migration appliquée sur MySQL.
**Dans Chrome**, appli terrain (localhost:4173 → API localhost:8001, compte agent) :
connexion, téléchargement complet (passage Dexie v2 → v3), Saisir → Visite, producteur
Coulibaly Awa → sa parcelle téléchargée « Champ du marigot — 2,25 ha » choisie
d'office, 2 pratiques, observation, photo ; « Enregistrer » → dans la file ; « Envoyer
maintenant » → « 1 nouveau, 0 rejeté, 2 photos ». En base : la visite, ses pratiques,
son auteur, `cree_at` du téléphone, la photo rattachée. Au bureau en **agronome** : menu
Producteurs + Visites, la visite avec pratiques, observation et photo affichée ; photo
d'un reçu de dépense → 403 ; fiche producteur → « 29/09/2026 · 1 visite(s) ».

**Bogues trouvés par le vrai parcours (invisibles aux tests).**
1. La visite ne s'enregistrait pas, **sans aucun message** : IndexedDB refuse les
   tableaux réactifs de Svelte 5 (`DataCloneError`). Corrigé par `$state.snapshot`, et
   l'erreur est maintenant affichée à l'agent.
2. Photo bloquée en « Compression… » **pour toujours** tant que la question « Autoriser
   la position ? » reste sans réponse (le délai du GPS ne court pas pendant la question).
   Touchait aussi la photo de pesée des achats. Corrigé : délai à nous, photo gardée sans
   position ; test vitest ajouté.

**Limites / surprises.** Dans Chrome, les clics et la frappe n'atteignaient plus l'onglet
après la connexion (même extension d'émulation qu'au budget) : parcours mené en
JavaScript dans la page (mêmes événements, vrai code de l'appli). Une photo prise puis
abandonnée (tentative ratée) part quand même au bureau, sans fiche : orpheline, sans
effet, mais elle occupe le disque. `vite preview` servait l'ancienne build (voir
CLAUDE.md). Pas testé sur un vrai téléphone (question 26).

**Reste.** Commit ; question 30 (liste des pratiques, saisie par l'agronome).

---

## 2026-09-29 — Phase 2 : budget de campagne, prévu contre réel — FINI, branche `phase-2-budget`

> Session `ly-agricole-45`, branche `phase-2-budget` (depuis `ad360cd`), dans un
> **worktree séparé** `../ly-agricole-budget` : une autre session (`ly-agricole-05`)
> codait les rendements au même moment dans `ly-agricole/`. Pour lancer ce worktree :
> `vendor/` et `node_modules/` y ont été copiés, `.env` aussi, `npm run build` fait ;
> servi sur le port **8001**.

**Fichiers nouveaux.** `app/Enums/PosteBudget.php`, `app/Models/LigneBudget.php`,
`app/Services/Budgets.php`, `database/migrations/2026_12_19_000001_create_lignes_budget_table.php`,
`app/Livewire/Budget/SuiviBudget.php` + sa vue, `tests/Feature/Budget/{BudgetsTest,EcranBudgetTest}.php`
(21 tests).

**Fichiers modifiés (additifs).** `AppServiceProvider` (droits `voir-budget` : direction
et comptable, `gerer-budget` : direction ; morph map `ligne_budget`), `routes/web.php`
(+ `/budget`), menu (+ Budget sous Argent, icône `budget`), `MODELE_DE_DONNEES.md`,
question ouverte n° 29.

**Choix.** Le prévu est saisi par poste : chaque catégorie de dépense, plus « achats »
et « prêts », qui ne passent pas par les dépenses. Le réel **n'est pas stocké** : il est
recalculé depuis les registres, en **argent sorti** pour ne rien compter deux fois —
dépenses payées rattachées à la campagne ; espèces payées sur les achats validés (la
part retenue sur un prêt a déjà été comptée au décaissement) ; décaissements des prêts
non contre-passés (les intrants remis à crédit sont déjà comptés à leur achat). Une
catégorie dépensée sans budget apparaît en « Non prévu » ; ce qui attend une validation
est montré à part. Une ligne se modifie (l'ancien montant reste au journal), jamais sur
une campagne clôturée ; une catégorie exclue par l'art. 10.3 ne se budgète pas.

**Vérifié.** `php artisan test` : 367 → **388 tests**, tous verts ; Larastan 0 erreur ;
Pint propre ; `npm run build` OK ; migration appliquée sur la base MySQL partagée
(table nouvelle seulement).
**Dans Chrome** (localhost:8001, base MySQL partagée, compte direction) : sans budget,
les réels affichés recoupent la base à la main (SQL) — achats validés 85 000 FCFA payés
en espèces (valeur 255 000, dont 170 000 retenus sur des prêts : pas comptés deux fois),
159 340 FCFA d'achats à valider en attente, 21 630 000 FCFA décaissés sur les prêts ;
les dépenses existantes, sans campagne, n'y sont pas. Prêts prévus à 20 000 000 →
« 108,1 % », « Dépassé de 1 630 000 FCFA » en rouge ; « Modifier » reprend montant et
note ; passé à 22 000 000 → « 98,3 % », reste 370 000 FCFA ; une seule ligne en base,
journal « 20000000 → 22000000 ». **Pas fait dans Chrome** : la vue du comptable (lecture
seule) et le refus pour l'agent — la déconnexion n'a pas abouti (menu du profil
inaccessible, une extension d'émulation de téléphone s'est ouverte par-dessus) ; ces
droits sont couverts par `EcranBudgetTest`.

**Corrigé après Chrome.** « Réel » n'est plus en rouge quand rien n'est prévu ; le
message « Budget enregistré. » disparaît quand on rouvre le formulaire.

**Surprises.** `php artisan serve` répond lentement ici (22 s pour la connexion) : un
clic sans effet visible est souvent une requête en cours, pas un bug. Dans le worktree,
`vendor/bin/pint` échoue (conflit d'archive phar avec la copie de `ly-agricole/`) : le
lancer depuis `ly-agricole/` sur les fichiers du worktree.

**Précision du responsable projet** (pendant la session) : l'anacarde n'est pas seul,
plusieurs produits entrent selon la période et le prix. Le budget est déjà par campagne,
donc par produit ; plusieurs campagnes ouvertes en même temps ont chacune le leur. Un
budget **global toutes campagnes** (ou par période) n'existe pas : ajouté à la question
29.

**Reste.** Vue comptable dans Chrome ; question 29 ; fusion avec `phase-2-reventes` (conflit attendu dans `QUESTIONS_OUVERTES.md` : garder la 28 et la 29).

---

## 2026-09-29 — Phase 2 : apports de campagne et portail investisseur — FINI, commit `ad360cd`

> Session `ly-agricole-fb`, branche `phase-2-reventes` (suite du commit `a3dc68f`,
> poussé sur `origin`). Deuxième bloc de la phase 2, après les reventes.

**Pourquoi ce périmètre.** Le bloc naturel suivant était le portail investisseurs et le
calcul du résultat/quotes-parts (contrat art. 10 à 14). **Le texte exact de ces
articles n'est pas disponible** (demandé au responsable projet, réponse : « j'ai pas
les articles ») : coder un partage financier destiné aux investisseurs à partir d'un
souvenir approximatif (« 40 % / 60 % ») aurait été irresponsable. Le périmètre a donc
été réduit à ce qui ne dépend pas du texte du contrat : tracer les apports et donner à
chaque investisseur sa part de l'ensemble des apports — objectif, calculable, sans
supposer la formule de partage. Noté en question ouverte n° 15 bis.

**Fichiers nouveaux.** `app/Models/Apport.php`, `app/Services/Apports.php`,
`database/migrations/2026_12_12_000001_create_apports_table.php`,
`app/Livewire/Investisseurs/{GestionApports,PortailInvestisseur}.php` + leurs vues,
`tests/Feature/Investisseurs/{ApportsTest,EcransApportsTest}.php` (21 tests).

**Fichiers modifiés (additifs).** `NatureMouvement` (+ `ApportCampagne`),
`Tresorerie::enregistrerApport()` (mirroir de `encaisserVente()`, garde de
contre-passation), `AppServiceProvider` (droits `gerer-apports`,
`voir-portail-investisseur` ; morph map), `routes/web.php` (+ `/apports`,
`/mon-investissement`), menu latéral (+ lien Apports sous Argent ; section
« Investisseur » à part, avec « Mon investissement », pour le rôle `Investisseur` qui
n'avait jusqu'ici **aucun écran**).

**Modèle.** `apports` 🔒 (montant **signé**, contre-passable) impose le **compte dédié
de la campagne** (art. 5) : un apport sur un autre compte est refusé. `investisseur_id`
nullable = apport de LY elle-même (art. 9, facultatif). Piège technique rencontré :
l'enregistrement en une transaction demande de créer le mouvement de trésorerie
**avant** la ligne immuable (elle ne peut pas être mise à jour après coup pour y ajouter
`mouvement_id`) ; comme l'apport n'existe pas encore à ce moment, le mouvement prend la
**campagne** comme source (elle existe déjà), pas l'apport — différent du schéma des
achats/ventes/remboursements, qui utilisent l'entité déjà mutable comme source.

**Ce que `Apports::repartition()` donne, et ce qu'il ne donne PAS.** Pour chaque
investisseur d'une campagne : son apport net et sa part en millièmes de l'ensemble des
apports **d'investisseurs** (l'apport de LY n'entre pas dans ce total). Ce n'est PAS une
quote-part du résultat : les deux écrans (gestion des apports, portail investisseur)
l'affichent en toutes lettres pour ne rien laisser croire de plus que ce qui est garanti.

**Portail investisseur.** Premier écran du rôle `Investisseur` (créé en semaine 1, resté
sans aucun écran jusqu'ici). Lecture seule, un investisseur ne voit que ses propres
apports (`investisseur_id = auth()->id()`), jamais ceux des autres ni les données
personnelles des producteurs.

**Vérifié.** `php artisan test` : 346 tests avant ce bloc → **367 tests** (346 + 21 :
`ApportsTest` 9, `EcransApportsTest` 12), tous verts ; Larastan 0 erreur sur tout le
projet (un faux positif Larastan croisé en route : `nullsafe.neverNull` sur un
paramètre pourtant `?User` — contourné avec un `===  null ? ... : ...` explicite plutôt
qu'un `?->`/`??`, sans changer le comportement) ; Pint propre ; `npm run build` OK.
**Dans Chrome**, sur la vraie base MySQL partagée : compte « Fonds campagne Anacarde
2026-2027 » créé (dédié, type banque), apport de 3 000 000 FCFA enregistré pour
l'investisseur de démo par la direction, répartition affichée (100 %, un seul
investisseur), connexion avec le compte investisseur → menu réduit à « Mon
investissement » seul, apport et part affichés, aucune donnée d'un autre investisseur
visible (il n'y en avait qu'un, donc pas testé à plusieurs — à refaire avec un second
investisseur si on veut vérifier l'isolation pour de vrai).

**Reste.** Commit. Le calcul du résultat net et des quotes-parts (art. 10 à 14) reste
entièrement à faire, dès que le texte du contrat sera fourni — c'est la pièce qui manque
pour le rapport de campagne final (art. 18).

---

## 2026-09-29 — Phase 2 : reventes, encaissements, marge par lot — FINI, commit `a3dc68f`

> Session `ly-agricole-fb`, branche `phase-2-reventes` (depuis `66f10d2`, avant les
> semaines 6 à 10 de `ly-agricole-f9`, restées sur ses propres branches). À la demande
> de l'utilisateur : « laisse l'autre s'occuper de la phase 1, toi fais la phase 2 ».
> Premier bloc choisi : reventes et marge par lot (cahier §7, module 5, stade Revente +
> Encaissement).

**Fichiers nouveaux.** `app/Enums/{TypeAcheteur,StatutVente}.php`,
`app/Models/{Vente,Encaissement}.php`, `app/Services/{Ventes,Encaissements}.php`,
`database/migrations/2026_12_05_000001_create_ventes_tables.php`,
`app/Livewire/Ventes/{ListeVentes,FormulaireVente,FicheVente}.php` + leurs vues,
`tests/Feature/Ventes/{VentesTest,EcransVentesTest}.php` (23 tests).

**Fichiers modifiés (peu, tous additifs).** `StatutLot` (+ `Vendu`),
`TypeMouvementStock` (+ `SortieVente`), `NatureMouvement` (+ `EncaissementVente`),
`CleParametre` (+ `SeuilValidationVente`), `Lot` (relations `achats()`/`ventes()`),
`Stock::sortieVente()` (mirroir de `entreeAchat()`, garde de contre-passation),
`Tresorerie::encaisserVente()` (mirroir de `encaisserRemboursement()`),
`AppServiceProvider` (droits `voir/saisir/valider-ventes`, `encaisser-ventes` ; morph
map), `routes/web.php` (+ `/ventes`), menu latéral (+ lien Ventes, sous Argent).

**Modèle.** `ventes` (statut `a_valider`/`valide`/`refuse`, comme les achats) et
`encaissements` 🔒 (montant **signé**, une contre-passation est négative) sont deux
tables séparées, comme le cahier le prévoit déjà (stade Revente ≠ stade Encaissement) :
la question 15 (acheteur, paiement à la livraison ou à terme) reste ouverte, mais le
modèle n'a pas besoin de trancher — une vente peut être encaissée en une fois, en
plusieurs, ou pas encore ; le reste à encaisser est sur sa fiche. `mouvements_stock`
gagne une colonne `vente_id` (nullable, ajoutée après coup par migration : la table est
de la semaine 6). Un lot dont le stock (tous magasins) tombe à 0 après une vente passe
automatiquement `vendu`.

**Marge par lot** (`Ventes::margeLot()`) = revenu des ventes validées − coût des achats
validés. **Limite documentée** (dans le modèle de données et à l'écran) : les frais de
transport, taxes et commissions à la revente ne sont pas rattachés au lot (pas de
`lot_id` sur `depenses`) — la marge affichée est une borne haute, pas le résultat net
exact du contrat (art. 10 à 14, qui restent à coder pour le rapport de campagne).

**Vérifié.** `php artisan test` : 323 tests avant ce module → **346 tests** (323 + 23 :
`VentesTest` 12, `EcransVentesTest` 11), tous verts ; Larastan 0 erreur sur tout le
projet ; Pint propre ; `npm run build` OK.
**Dans Chrome**, sur la vraie base MySQL partagée : vente VTE-000001 de 400 kg sur
LOT-00001 (600 → 200 kg), créée en comptable (« à valider »), validée en direction
(séparation des tâches respectée), encaissement partiel de 200 000 FCFA sur la caisse
centrale, contre-passé (motif obligatoire) → reste à encaisser revenu à 360 000 FCFA ;
marge du lot affichée (105 000 FCFA, frais non compris, l'écran le précise).

**Piège rencontré.** Le bouton « Valider » d'une vente porte un `wire:confirm` (boîte de
dialogue native) : un clic direct bloque l'automatisation du navigateur. Contournement
en Chrome : appeler la méthode Livewire par JS (`Livewire.find(id).call(...)`) plutôt
que de cliquer le bouton. Sans rapport avec le code : quelques captures d'écran ont
expiré (30 s) juste après un clic réel, alors que la page répondait normalement en JS —
lenteur ponctuelle de l'outil de capture, pas un blocage de l'application.

**Coordination.** `ly-agricole-f9` fait la phase 1 (semaines 6 à 10, branches
`semaine-6` à `semaine-10`, worktree séparé `ly-agricole-phase1`) ; je n'ai touché à
aucun de ses fichiers. Elle confirme que `sortie_vente` est bien exclu de son calcul
d'écart de poids (semaine 10) et n'est pas compté comme une anomalie.

**Reste.** Commit (proposé : un commit sur `phase-2-reventes`, à faire pointer sur
`main` ou à fusionner avec les branches `semaine-*` selon ce que décide l'utilisateur —
les deux travaux sont indépendants en fichiers mais partagent le même schéma de base).
Écran dédié « Reventes » sur la fiche du lot (`FicheLot`, propriété de la session f9) :
pas fait, pour ne pas toucher à son fichier sans coordination — la marge est visible
depuis la fiche de la vente à la place.

## 2026-09-29 — Semaine 12 (suite) : copie hors site et mot de passe des archives — FINI, COMMITÉ

> Même worktree, branche `semaine-12`. Demande de l'utilisateur : « fais copie des …
> et mot de passe des archives ».

**Fait.**

- **Copie hors site** (`copierHorsSite`, lancée par `ly:sauvegarder`, relançable par
  `ly:copier-sauvegarde`) : vers un disque Laravel (`SAUVEGARDE_HORS_SITE_DISQUE`, ex. S3)
  ou un dossier (`SAUVEGARDE_HORS_SITE_DOSSIER`). La copie est **relue** et son SHA-256
  comparé ; différente ⇒ supprimée, signalée, commande en échec (l'archive locale
  reste). Refus du dossier des sauvegardes locales comme « hors site ». Conservation
  hors site 90 jours, 7 dernières gardées, autres fichiers du stockage jamais touchés.
- **Mot de passe** : `ly:mot-de-passe-sauvegardes` (32 lettres et chiffres, écrit dans
  `.env`, affiché une fois ; refuse d'écraser sans `--remplacer`). Sauvegarde refusée
  sous 16 caractères. Empreinte PBKDF2 (200 000 tours) du mot de passe dans le
  manifeste : la vérification dit tout de suite « mot de passe différent de celui de
  l'archive (empreinte attendue …) » ou « archive chiffrée : définir … ».
- Rapports → Alertes : copie hors site absente / en échec / de plus de 2 jours,
  sauvegardes non chiffrées.
- `docs/MISE_EN_PRODUCTION.md` (mot de passe en deux exemplaires hors du serveur, deux
  façons de copier selon l'hébergeur, restauration « serveur perdu » depuis la copie),
  `.env.example`.

**Vérifié en l'exécutant.**

- 349 tests (340 → 349), Larastan 0, Pint propre. Dont : copie relue différente
  (disque simulé qui rend autre chose) ⇒ copie supprimée + alerte ; mauvais mot de
  passe ; commande de mot de passe sur un `.env` jetable (le vrai `.env` n'a pas été
  touché : vérifié).
- **Réel, MySQL** : mot de passe de dev généré **sans affichage** (sert aux essais
  seulement ; celui de production sera généré sur le serveur), copie vers un dossier
  temporaire jouant le hors site. `ly:sauvegarder --verifier` ⇒ archive chiffrée de
  100 Ko, « copie hors site relue et identique », « restauration vérifiée : 290 lignes,
  22 fichiers ». Contre-vérifications : SHA-256 local = hors site ; `base.sql` illisible
  sans mot de passe (manifeste lisible) ; **restauration directement depuis la copie
  hors site** réussie (scénario « serveur perdu »). Alertes de sauvegarde disparues des
  rapports.
- Un test dépendait du `.env` du développeur (copie hors site désormais définie) :
  configuration neutre imposée dans le test.

**Reste.** Choisir la cible hors site réelle avec l'hébergeur (question 10) ; générer le
mot de passe de production sur le serveur et le mettre au coffre.

---

## 2026-09-29 — Semaine 12 (préparation) : sauvegardes vérifiées par restauration, procédure de mise en production — FINI, COMMITÉ

> Session `ly-agricole-f9`, worktree `ly-agricole-phase1`, branche `semaine-12` (depuis
> `semaine-10`). Semaine 11 (pilote) non commencée : il faut un téléphone (question 26,
> en suspens). La mise en ligne réelle attend l'hébergement (question 10) et le domaine
> (question 18).

**Fait.**

- `App\Services\Sauvegardes` + commandes `ly:sauvegarder [--verifier]` et
  `ly:verifier-sauvegarde [archive]` : archive datée = `base.sql` (mysqldump
  `--single-transaction`, mot de passe par `MYSQL_PWD`, pas en ligne de commande) +
  fichiers privés + `manifest.json` (lignes par table, **sommes des registres**, SHA-256
  de chaque fichier). Empreinte prise avant et après le dump : si la base a bougé,
  nouvel essai (3 au plus). Chiffrement AES-256 si `SAUVEGARDE_MOT_DE_PASSE`.
  Conservation 30 jours, les 7 dernières toujours gardées.
- Vérification : refus d'une archive altérée (SHA-256) **avant** toute restauration ;
  restauration réelle dans `ly_agricole_verif` (refus si c'est le nom de la base de
  production) ; comparaison des comptes et des sommes ; chaque fichier cité par la base
  (justificatifs, accords, photos) doit être dans l'archive ; base jetable supprimée.
  Résultat dans `storage/sauvegardes/derniere-verification.json`.
- Planification : sauvegarde chaque nuit 02:00, restauration vérifiée chaque dimanche
  03:00. **Rapports → Alertes** : « Sauvegarde non vérifiée » (jamais, ou plus de
  8 jours) et « Sauvegarde en échec ».
- `docs/MISE_EN_PRODUCTION.md` : serveur, installation, `.env` de production, processus
  permanents (file, planificateur), sauvegarde et **restauration pas à pas**, appli
  terrain, comptes réels, gel. `.env.example` : clés `SAUVEGARDE_*`. Archives exclues
  de git (données personnelles).

**Vérifié en l'exécutant.**

- 340 tests (330 → 340 : 10 sauvegardes, sur sqlite avec un faux mysqldump / import ;
  archive, chiffrement, SHA, comptes, sommes, fichiers cités, conservation réels),
  Larastan 0, Pint propre.
- **Vraie sauvegarde et vraie restauration sur MySQL** (base de dev `ly_agricole`,
  fichiers privés de dev copiés dans le worktree) : `ly:sauvegarder --verifier` ⇒
  archive de 99 Ko, « Restauration vérifiée : 290 lignes, 22 fichiers : identiques » ;
  `ly_agricole_verif` bien supprimée ; production intacte (19 mouvements de trésorerie).
- **Contre-épreuve** : archive sabotée (l'INSERT de `remboursements` retiré du dump,
  SHA recalculé pour tromper le contrôle d'intégrité) ⇒ « remboursements = 0, attendu
  1 ; remboursements_fcfa = 0, attendu 170000 », code retour 1. La restauration attrape
  ce que l'empreinte de fichier ne voit pas.
- **Défaut trouvé en vrai et corrigé** : sur MySQL, `getTableListing()` sans schéma
  liste les tables de **toutes** les bases du serveur (ici un autre projet XAMPP :
  `acquisition_opportunities`) ; limité à la base courante.

**Reste.** Semaine 11 (pilote, téléphone) ; mise en ligne réelle (questions 10, 18) ;
copie hors site des sauvegardes (avec la question 10) ; choisir et garder hors serveur
le mot de passe des archives.

---

## 2026-09-29 — Semaine 10 (en avance) : rapports de la direction et exports — FINI, COMMITÉ

> Session `ly-agricole-f9`, **worktree `C:\xampp\htdocs\ly-agricole-phase1`**, branche
> `semaine-10` (depuis `semaine-9`, 6ecd725). L'autre session (`ly-agricole-fb`) fait la
> phase 2 (reventes, marge) dans le dossier principal, branche `phase-2-reventes` :
> accord pour ne pas toucher à `Indicateurs.php` / `TableauDeBord.php` (les siens) ;
> ses migrations seront datées après 2026-12-05.

**Fait.**

- Section **Rapports** (droit `voir-rapports` : direction, comptable ; lien dans le
  menu) : page de synthèse avec les trois questions — **combien reste dû, combien en
  stock (par produit), combien en caisse** — et cinq rapports : portefeuille de prêts
  (remis, remboursé, restant dû, échus, filtre par campagne), stock par lot et magasin,
  caisses et comptes (solde, dernier mouvement), **écarts de poids** par lot, alertes
  (prêts échus, prêts / achats / dépenses à valider, photos de pesée attendues, écarts).
- Chaque rapport en **PDF** (dompdf, A4 portrait ou paysage, sous-ensemble de police)
  et en fichier pour **Excel** (CSV `;`, UTF-8 avec BOM, nombres bruts additionnables,
  kilos à virgule) — question 33.
- `App\Support\Tableau` : un seul format de tableau (valeurs brutes FCFA / grammes /
  ‰ / dates) rendu à l'écran, en PDF et en CSV ; mise en forme en entiers.
- Paramètre `SeuilAlerteEcartPoids` (‰) : non défini ⇒ tout écart est signalé
  (question 34). Aucune table : tout est recalculé à partir des registres.

**Vérifié en l'exécutant.**

- 330 tests (323 → 330 : 7 rapports), Larastan 0, Pint propre — **dans le worktree,
  sur son propre code** (voir « Surpris »).
- Chrome, `localhost:8001` (code du worktree, MySQL partagée) : synthèse **21 830 000
  FCFA restant dû, 600 kg d'anacarde, 3 270 000 FCFA en caisse** — les trois recalculés
  à la main en SQL (décaissements non contre-passés + intrants − remboursements ; Σ
  grammes ; Σ entrées − sorties des comptes actifs) : **identiques**. Alertes : 5 achats
  à valider (ceux de la semaine 8) + un écart. PDF du portefeuille (8 prêts, total
  21 830 000) et des caisses ouverts dans Chrome.
- **Défaut trouvé en vrai et corrigé** : l'autre session a enregistré dans la base de dev
  une vente de 400 kg (`sortie_vente`, phase 2) ; mon premier calcul (écart = stock −
  achats) la comptait comme **−400 kg d'écart (−66,6 %)**. Écart désormais = pertes +
  inventaires + corrections ; les ventes ont leur colonne. Test ajouté. À l'écran après
  correction : 600 kg achetés, −400 kg vendus, stock 200 kg, écart 0.
- En-têtes des colonnes chiffrées du PDF alignés à droite (règle CSS trop faible).

**Surpris.**

- **Les worktrees des semaines précédentes vérifiaient peut-être le mauvais code** :
  avec `vendor` en jonction, l'autoloader charge `App\` depuis le dossier d'origine.
  Ici le premier passage « 323 verts » testait le code du dossier principal. Corrigé
  (vendor copié, opcache CLI coupé) ; piège ajouté à `CLAUDE.md`. Les commits des
  semaines 5 et 6 ont depuis été couverts par les suites complètes des semaines
  suivantes sur leur propre dossier.
- Blade ne compile pas `mot@if` ; le `@endif` si.

**Données de dev** : aucune ajoutée par cette semaine (lecture seule).

**Reste.** Semaine 11 (pilote terrain : dépend du téléphone, question 26 en suspens) ;
semaine 12 (mise en production, sauvegardes testées par restauration) ; questions 33, 34 et 36 (anciens 28, 29, 30 de cette branche).

---

## 2026-09-28 — Semaine 9 (en avance) : parcelle au GPS, photos, dépense terrain, rejets — FINI, COMMITÉ

> Session `ly-agricole-f9`, branche `semaine-9` (depuis `semaine-8`). Décisions de
> l'utilisateur : **question 26 en suspens** (pas d'APK pour l'instant) ; **question 27
> « selon la recommandation »** ⇒ serveur en ligne en HTTPS (décision D11).

**Fait — serveur.**

- Migration `2026_11_14_000001` : table `photos_terrain` (UUID du téléphone, disque
  privé, heure et position de la prise) ; `parcelles.contour_origine` (`import` posé
  par le modèle pour tout contour du bureau, `gps` pour un relevé du téléphone).
- `POST /api/photos` (multipart, image ≤ 5 Mo, **idempotent** : renvoi ⇒ `deja_recu`).
- `/api/sync` : types `parcelle` (contour Polygon revérifié par `Contour` — fermé, en
  Côte d'Ivoire —, **surface recalculée au serveur**, celle du téléphone ignorée) et
  `depense` (même service que le bureau ; justificatif = photo déjà reçue, du même
  utilisateur ; montant entier strict) ; `achat.photo_pesee` (UUID, la photo peut
  arriver après). Référentiels : `categories_depense`.
- Bureau : fiche producteur « relevé GPS en marchant » ; liste des achats « Photo
  pesée » ou « photo attendue » ; `GET /photos-terrain/{photo}` (valideur d'achats,
  trésorerie, ou l'agent auteur).

**Fait — appli terrain.**

- Onglets Accueil · Achat · **Saisir** · À envoyer. Écrans : **Relevé de parcelle**
  (suivi GPS, point gardé si précision ≤ 15 m et à ≥ 3 m du précédent, « point ici »
  aux coins, pause, périmètre et surface en aperçu), **Dépense terrain** (photo du reçu
  obligatoire), **Nouveau producteur** (accord obligatoire ; fiche utilisable hors ligne
  tout de suite), **photo de pesée** facultative sur l'achat. `ChoixProducteur` et
  `PrisePhoto` partagés.
- Photos compressées sur le téléphone (JPEG, 1600 px, qualité 0,7) avec position ;
  envoyées **avant** les opérations, puis le fichier quitte le téléphone.
- Rejets : « Renvoyer » (même UUID), « Confirmer (même famille) et renvoyer » pour un
  doublon de téléphone, « Abandonner » (gardé pour la trace, statut `abandonne`).
- Base locale v2 : la montée de version force un téléchargement **complet** (sinon la
  nouvelle table des catégories resterait vide) ; un téléchargement complet garde les
  producteurs créés sur le téléphone et pas encore envoyés.
- Question 27 : l'appli refuse une adresse `http://` publique (http permis seulement
  localhost / réseau privé / `.test` / `.local`) ; build Android en HTTPS sauf
  `LY_TERRAIN_DEV=1` ; permissions de position ajoutées au manifeste.

**Vérifié en l'exécutant.**

- Laravel : **323 tests** (315 → 323), Larastan 0, Pint propre. Terrain : **24 tests**
  vitest, svelte-check 0, build OK.
- **Livrable, dans l'émulateur de téléphone de Chrome lancé par l'utilisateur** (appli
  `localhost:4173`, API `localhost:8000`, MySQL) : base locale v1 de la veille ⇒ passée
  en v2, « mis à jour : jamais », téléchargement complet ; relevé de parcelle pour Soro
  Yacouba avec un **GPS simulé** (marche autour d'un carré de 80 m, 82 relevés dont 2 à
  ± 40 m) ⇒ **80 points gardés, périmètre 320 m, 0,64 ha** ; dépense Carburant 15 000
  avec photo (36 Ko → 28 Ko, position gardée) ; envoi ⇒ « 2 nouveau(x), 1 photo » ;
  MySQL : parcelle `gps`, **surface 6 401 m² recalculée par le serveur**, dépense
  `payee` avec la photo comme justificatif ; **fiche du producteur au bureau :
  « Champ relevé GPS — 0,64 ha — relevé GPS en marchant »**. Rejet : Coulibaly Adama
  créé avec le téléphone de Coulibaly Awa ⇒ « Rejeté — À confirmer : le téléphone
  0711223344 figure déjà… » ⇒ « Confirmer et renvoyer » ⇒ accepté, LYP-000009,
  `doublon_confirme` au journal.
- **Pas vérifié** : vrai GPS en marchant, vraie caméra (photo injectée par script),
  vrai téléphone (question 26 en suspens). Le navigateur bride les minuteries de
  l'onglet : la marche simulée a dû livrer ses points d'un coup.

**Données de dev ajoutées** : parcelle « Champ relevé GPS » (LYP-000006), dépense
Carburant 15 000 (caisse agent), photo terrain, producteur LYP-000009.

**Reste.** Semaine 10 (tableaux de bord, exports) ; correction d'un achat rejeté =
nouvelle saisie (pas d'édition d'une opération sur le téléphone, par principe) ;
questions 22, 24, 25, 26 (en suspens).

---

## 2026-09-28 — Semaine 8 (en avance) : appli terrain hors ligne — FINI, COMMITÉ (APK non construit)

> Session `ly-agricole-f9`, branche `semaine-8` (depuis 66f10d2). Tout est dans
> `terrain/` ; aucun fichier Laravel de code modifié cette semaine.

**Fait.**

- `terrain/` : SvelteKit 2 statique (Svelte 5, adapter-static, SPA), Tailwind 4, Dexie 4
  (IndexedDB), uuid v7, qr-scanner, Capacitor 8 (`ci.lyagricole.terrain`, projet
  `android/` généré, permission CAMERA, http autorisé pour le pilote). TypeScript 6
  (svelte-check refuse le 7).
- `src/lib/mesure.ts` : saisies en entiers par lecture du texte (miroir de
  `App\Support\Mesure` / `Montant`), montant en BigInt `(net × prix + 500) / 1000`,
  affichage comme `Format`.
- `src/lib/db.ts` : référentiels + **file d'envoi** (`operations` : uuid v7, type,
  cree_at, donnees, statut `en_attente`/`envoye`/`rejete`, motif, résumé lisible).
- `src/lib/synchro.ts` : connexion (jeton par appareil, `appareil_id` v7 gardé),
  référentiels complets puis delta (`depuis`), comptes et prêts toujours remplacés ;
  `envoyer()` par paquets de 100 dans l'ordre de saisie ; échec réseau ⇒ tout reste en
  attente ; `accepte`/`deja_recu` ⇒ « Au bureau » ; `rejete` ⇒ motif affiché, pas
  renvoyé tout seul (correction : semaine 9) ; 401 ⇒ reconnexion, la file reste.
- Écrans : Connexion (adresse du serveur), Accueil (campagne, prix officiel,
  téléchargement, déconnexion refusée s'il reste des saisies), **Achat** (recherche
  nom/code/téléphone ou **scan QR** de la carte, lot, caisse, point de collecte, pesée,
  humidité, prix pré-rempli au prix officiel et refusé en dessous, prêt en cours avec
  kilos retenus par défaut pour solder, aperçu net / valeur / espèces), **À envoyer**
  (liste, statuts, motifs, « Envoyer maintenant »). Badge « N à envoyer » partout.

**Vérifié en l'exécutant.**

- `npm test` : 13 tests (saisies, montants au-delà de 2⁵³ en intermédiaire, file :
  hors réseau ⇒ rien ne part ; réponse perdue puis renvoi ⇒ 5 « deja_recu », le faux
  serveur n'a chaque achat qu'une fois ; rejet isolé avec motif ; ordre UUID v7 ; 401 ;
  delta des référentiels). `npm run check` 0 erreur ; `npm run build` OK.
- **Livrable, dans Chrome** (appli sur `localhost:4173`, API sur `localhost:8000`,
  MySQL) : connexion `agent@` ⇒ 8 producteurs téléchargés ; **serveur arrêté** ;
  5 achats saisis (Coulibaly Awa sous prêt : 120 kg tous retenus, espèces 0 ; Soro
  Yacouba 79 kg × 430 = 33 970 ; Coulibaly Mariam 55 kg ; Ouattara Siaka 198 kg × 440 =
  87 120 ; Traoré Mariam 35 kg) ; « Envoyer » ⇒ « Serveur injoignable : rien n'est
  perdu », 5 en attente ; **page rechargée : toujours 5** ; serveur relancé ⇒
  « Envoyé : 5 nouveau(x), 0 déjà reçu(s), 0 rejeté(s) » ; MySQL : ACH-000003 à
  ACH-000007, montants identiques à l'aperçu du téléphone, `a_valider` (seuil non
  défini) ; synchronisation n° 3 : 5/5 acceptées ; **écran Achats du bureau** : les 5,
  « À valider », avec Bon PDF.
- **Pas vérifié** : APK et vrai téléphone en mode avion (veille, redémarrage) — Android
  Studio absent du poste (question 26) ; scan QR réel (caméra) ; « mode avion » simulé
  en arrêtant le serveur, pas en coupant le réseau du navigateur.

**Données de dev ajoutées** : achats ACH-000003 à 007 (à valider), synchronisation 3.

**Reste.** Semaine 9 (relevé GPS, photos, dépense terrain, correction des rejets) ;
fiche producteur créée sur le téléphone (l'API l'accepte déjà, pas encore d'écran) ;
producteurs limités à la zone de l'agent (question 22) ; questions 26, 27.

---

## 2026-09-28 — Semaine 7 (en avance) : API terrain, `/api/sync` idempotent, SMS, bon d'achat — FINI, COMMITÉ

> Session `ly-agricole-f9`, branche `semaine-7` (depuis `semaine-6`, c66136a). La
> modification de `.claude/skills/ly-agricole-metier/SKILL.md` est celle de
> l'utilisateur : **hors commit**, à lui de décider.

**Fait.**

- **Sanctum 4.3** (`composer require`, ~15 min en tâche de fond) ; `HasApiTokens` sur
  `User` ; migration `personal_access_tokens` renommée `2026_11_07_000002`.
- `routes/api.php` : `POST /api/connexion` (email, mot de passe, nom de l'appareil ⇒ un
  jeton ; mêmes règles que l'écran : compte actif, 5 essais puis blocage, même message
  pour compte désactivé et mauvais mot de passe ; connexion journalisée), `POST
  /api/deconnexion` (révoque le jeton), `GET /api/referentiels` (villages, groupes,
  produits, campagnes, lots, points de collecte, pisteurs, producteurs — **delta par
  `depuis`**, désactivés renvoyés avec `actif=false` ; comptes d'où l'utilisateur peut
  payer ; prêts en cours toujours en entier avec leur restant dû), `POST /api/sync`.
  `CompteActif` ajouté au groupe `api` : compte désactivé ⇒ 401 même avec un jeton.
- `App\Services\Synchronisation` : opérations `producteur` et `achat`, **dans l'ordre
  reçu, chacune dans sa transaction** ; l'UUID du téléphone **est** l'id créé ;
  `operations_recues` (une ligne par UUID) : acceptée ⇒ `deja_recu` au renvoi, rejetée ⇒
  renvoyable corrigée ; UUID déjà servi pour un autre type ⇒ rejet ; collision
  simultanée (contrainte unique) ⇒ `deja_recu`. Revalidation par les **mêmes services**
  que le bureau (`Achats::enregistrer`, doublons, consentement) ; poids et prix en
  **entiers stricts** (`integer:strict` : `505000.5` ou `"425"` refusés, jamais
  arrondis). Doublon « alerte » (même téléphone) ⇒ rejet « À confirmer » jusqu'à
  `doublons_confirmes: true`, puis journal `doublon_confirme`. Erreur imprévue ⇒ rejet
  avec motif + log, jamais perdue en silence. Trace de chaque appel dans
  `synchronisations`.
- **SMS (D10)** : interface `App\Services\Sms\EnvoyeurSms`, pilote `journal`
  (`SMS_PILOTE`, `?:` pour une clé vide) ; `ConfirmationsSms` écrit la ligne dans la
  transaction de l'achat (à l'exécution : donc à la validation s'il était à valider),
  du décaissement et du remboursement en espèces ; job `EnvoyerConfirmationSms`
  **`afterCommit`**, 3 essais, statut `envoye`/`echec` + erreur. Texte sans accents
  (question 24).
- **Bon d'achat PDF** (A5, dompdf, sous-ensemble de police) : `GET /achats/{achat}/bon`,
  lien « Bon PDF » dans la liste des achats ; pesée, qualité (sans float), règlement,
  kilos retenus, restant dû, signatures ; « EN ATTENTE DE VALIDATION » / « ACHAT
  REFUSÉ » en rouge. Visible par qui valide les achats, ou par l'agent qui l'a saisi.
- Docs : `MODELE_DE_DONNEES.md` (confirmations_sms, synchronisations, operations_recues,
  personal_access_tokens), `QUESTIONS_OUVERTES.md` (24 texte des SMS, 25 expiration des
  jetons), `CLAUDE.md` (commande `queue:work`, API, 2 pièges).

**Vérifié en l'exécutant.**

- `php artisan test` : **315 tests** verts (294 → 315 : 9 synchronisation, 5 API, 6 SMS,
  1 bon PDF), Larastan 0, Pint propre.
- **Livrable de la semaine 7** (`SynchronisationTest`) : le même lot (2 producteurs +
  2 achats) envoyé deux fois ⇒ 4 `deja_recu`, producteurs, achats, stock, mouvements de
  trésorerie, SMS et caisse **identiques** ; envoi coupé en deux puis renvoyé en entier
  ⇒ même résultat ; une opération invalide (prix sous le prix officiel) ⇒ les trois
  autres passent, puis corrigée et renvoyée avec le même UUID ⇒ acceptée.
- Mutation : sans `afterCommit()` dans le job, le test « aucun SMS avant le commit ni
  pour une opération annulée » **échoue** (vérifié, puis remis).
- **Parcours réel (MySQL + `artisan serve`, à la demande de l'utilisateur)** :
  migrations 2026_11_07 lancées ; script PHP/curl : connexion `agent@` ⇒ jeton ;
  référentiels : 7 producteurs, comptes = « Caisse agent de terrain » seule, 8 prêts en
  cours ; delta immédiat = 0 producteur ; `/api/sync` (producteur Traoré Mariam + achat
  100 kg à 425) ⇒ `accepte` ×2, **renvoi ⇒ `deja_recu` ×2** ; MySQL : producteurs 7 → 8,
  achats 1 → 2 (pas 3) ; achat `a_valider` (seuil non défini) ⇒ stock, caisse et SMS
  inchangés ; déconnexion ⇒ le jeton rend 401. Chrome (comptable) : ACH-000002 « À
  valider » ⇒ Valider ⇒ **lot 500 → 600 kg, caisse agent 957 500 → 915 000**, SMS
  `en_attente` puis `queue:work --once` ⇒ `envoye`, texte dans `laravel.log`. Bon PDF
  ACH-000002 ouvert dans Chrome : en-tête, fournisseur (carte LYP-000008), pesée
  101 − 1 = 100 kg, humidité 7,5 %, règlement 42 500, une page.
- Données de dev ajoutées par ce parcours : producteur LYP-000008 (Traoré Mariam),
  achat ACH-000002, 2 lignes `synchronisations`, 1 SMS.

**Surpris.**

- `Queue::fake()` ignore `afterCommit` (le job apparaît même après un rollback) : test
  refait avec la file `sync` et un faux envoyeur.
- Un `php -r … preg_replace` raté a vidé `TerrainController.php` (0 octet) : réécrit ;
  piège ajouté à `CLAUDE.md`.

**Reste.** Semaine 8 : l'appli terrain (SvelteKit + Dexie) qui
consomme cette API ; questions 22, 24, 25.

---

## 2026-09-28 — Semaine 6 (en avance) : achats bord-champ, lots, stock, remboursements — FINI, COMMITÉ

> Session `ly-agricole-f9`, branche `semaine-6`. Le tableau de bord qui lit ces tables
> est de la session `ly-agricole-fb` (entrée juste en dessous), commité à part.

**Fait.**

- Tables `pisteurs`, `lots`, `achats` 📱, `mouvements_stock` 🔒, `remboursements` 🔒 ;
  natures de trésorerie `achat_bord_champ`, `remboursement_pret` ; statut de prêt `solde`.
- `App\Services\Achats` : pesée en grammes (brut − tare), qualité en entiers (humidité ‰,
  KOR centièmes de lbs, grainage), montant `intdiv(net × prix + 500, 1000)` ; **prix sous
  le prix officiel de la campagne ⇒ refus** ; lot ouvert de la campagne ouverte ;
  l'agent paie depuis **sa** caisse. Au-dessus du seuil **ou seuil non défini** :
  `a_valider`, **rien ne bouge** avant la validation par un autre (stock, remboursement,
  paiement en une transaction à ce moment).
- Producteur sous prêt : kilos retenus (par défaut ce qu'il faut pour solder, arrondi au
  gramme supérieur) → remboursement en nature ; reste payé en espèces.
- **Question 3 non tranchée par le code** : paramètre « Valorisation des remboursements
  en kilos » (prix de l'achat du jour / prix de référence du prêt), nouveau type de
  paramètre « choix » ; **sans choix, un achat ne rembourse pas de prêt** (l'écran
  l'explique). La règle est figée sur chaque remboursement.
- `App\Services\Remboursements` : en nature (plafonné au restant dû : **invariant 1**) et
  en espèces (entrée de trésorerie) ; contre-passation d'un remboursement en espèces
  (et de son mouvement) ; `solde` ↔ `decaisse` / `valide`. La trésorerie **refuse** de
  contre-passer seule un paiement d'achat ou un remboursement (corrigés depuis leur
  origine) ; une contre-passation de remboursement ne rouvre plus le « reste à
  décaisser » (seuls les versements le font).
- `App\Services\Stock` : entrée d'achat, transfert (deux jambes liées), perte,
  **inventaire = poids compté** (l'écart devient un mouvement motivé), contre-passation ;
  stock ≥ 0 par magasin (**invariant 2**). Table `inventaires` du modèle remplacée.
- `App\Support\Mesure` : « 500,250 » kg → 500 250 g, « 8,5 » % → 85 ‰, sans float ;
  plus de décimales que prévu = refus.
- Écrans : Achats (liste + validation / refus, formulaire avec aperçu en direct : net,
  valeur, retenu sur le prêt, espèces), Lots (liste, création), fiche de lot
  (transfert, perte, inventaire, contre-passation, achats du lot avec qualité), Pisteurs
  (référentiel), section Remboursements de la fiche prêt (encaisser, contre-passer).
  Menu : « Achats » (Terrain), « Lots » (Stock), « Achat bord-champ » dans « Nouveau ».
- Droits : `saisir-achats` (+ agent), `valider-achats`, `gerer-stock`,
  `encaisser-remboursements` (direction, comptable).

**Vérifié en l'exécutant.**

- `php artisan test` : **290 tests** (266 → 290 pour la semaine 6 seule ; 294 avec le
  tableau de bord de `fb`), Larastan 0, Pint propre ; invariants 1, 2, 5, 6 ; sommes de
  grammes au-delà de 2³¹ (3,1 × 10⁹ g).
- **Livrable de la semaine 6, dans Chrome** (MySQL) : direction → règle « prix de l'achat
  du jour » (**valeur d'essai**, question 3) ; comptable → « Caisse agent de terrain »,
  avance 1 000 000, lot LOT-00001 ; agent → achat à Coulibaly Awa (prêt LYPR-000001
  proposé d'office, restant dû 3 000 000), 505 kg brut, 5 kg de tare, 425 FCFA/kg,
  400 kg retenus : aperçu « 500 kg · 212 500 · retenu 400 kg · 170 000 · espèces
  42 500 » ; enregistré **à valider** (seuil non défini) — MySQL : stock 0, aucun
  remboursement, caisse 1 000 000 ; comptable → Valider. Résultat, à l'écran et dans
  MySQL : **restant dû 3 000 000 → 2 830 000**, **lot 0 → 500 kg**, **caisse de l'agent
  1 000 000 → 957 500**.

**Surprise.** Le serveur de dev met ~3 s à répondre : une lecture de la page juste après
un clic peut tomber au milieu du rafraîchissement Livewire (vu sur la création d'un lot,
vérifié en relisant à 0,5 / 1,5 / 3 s : pas un bug). Un lot de contrôle LOT-00002 reste
dans la base locale.

**Reste.** Questions 3, 5 et 6 ; photo de la pesée et GPS de l'achat (appli terrain,
semaines 8-9) ; SMS de confirmation au producteur (semaine 7).

---

## 2026-09-28 — Tableau de bord branché sur la semaine 6 (achats, stock, remboursements) — FINI, commité

> Session `ly-agricole-fb`, à la demande de `f9` (qui a écrit la semaine 6 : ne pas la
> retoucher). Je n'ai modifié **que** le tableau de bord ; à commiter **après** le
> commit de la semaine 6, en un commit séparé.

**Fichiers modifiés.** `app/Services/Indicateurs.php`, `app/Livewire/TableauDeBord.php`,
`resources/views/livewire/tableau-de-bord/accueil.blade.php`,
`resources/views/components/graphiques/barres.blade.php` (format `kg`).
**Nouveau :** `tests/Feature/TableauDeBordFiliereTest.php` (4 tests).

**Ce qui change.** Les quatre cases « Bientôt » sont remplacées par des chiffres réels :
kilos achetés (achats **validés** seulement), stock en magasin (Σ `mouvements_stock`,
réservé à `gerer-stock`), remboursé (argent + kilos rendus) et restant dû
(`max(0, remis − remboursé)`), graphique des kilos achetés par mois, colonne « Remboursé »
dans le bilan par campagne, « achats à valider » dans les actions à mener, achats dans les
dernières nouvelles. Seule « Marge par lot » reste en « Bientôt » (reventes, phase 2).

**Choix à connaître.**

- Lecture par `DB::table()` de `achats`, `mouvements_stock`, `lots`, `remboursements` :
  **si `f9` renomme ces colonnes, `Indicateurs` casse** (`statut`, `poids_net_g`,
  `montant_fcfa`, `grammes`, `type`, `annule_id`, `cree_par`, `campagne_id`, `lot_id`).
- Les remboursements sont signés (contre-passation négative) : la somme se compense.
  Les **grammes** ne sont pas signés : les kilos rendus excluent les remboursements en
  nature annulés (`NOT EXISTS` sur `annule_id`).
- « Revenus » n'inclut plus une entrée d'argent **contre-passée** (défaut trouvé en
  écrivant les tests : un remboursement annulé restait compté).
- Les prêts `solde` comptent dans « prêts accordés » (ils disparaîtraient sinon).

**Vérifié.** `php artisan test` : **294 tests** verts ; Larastan 0 erreur sur mes deux
fichiers ; Pint propre. **Dans Chrome** (`localhost:8000`, base réelle, sans achat ni
remboursement) : la section s'affiche, restant dû 22 000 000 FCFA = 21 000 000 (7 prêts)
+ 1 000 000 (prêt mixte de `f9`), menu avec Achats et Lots, aucune exception.

**Pas vérifié dans Chrome.** Les chiffres non nuls (aucun achat en base) : couverts par les
tests avec les vrais services (`Achats`, `Remboursements`), pas à l'écran.

---

## 2026-09-28 — Front : menu latéral, barre du haut, page d'accueil analytique — FINI, COMMITÉ (`3c36505`)

> Session `ly-agricole-fb`. Le front est **terminé et vérifié** ; ne pas le refaire. Il
> reste à le commiter (voir « Reste »).

**Fichiers (tous nouveaux sauf mention).**

- `resources/views/components/layouts/app.blade.php` — **réécrit** : menu latéral
  groupé (Terrain / Argent / Stock / Administration), barre du haut (titre, prix de la
  campagne ouverte, bouton « Nouveau » filtré par droit, menu utilisateur). Les liens
  gardent `href="{{ route(...) }}"` sous leur `@can` : les tests d'accès les cherchent.
  Le lien « Intrants » de la session `f9` y est conservé.
- `resources/views/components/nav-lien.blade.php` — un lien du menu (icône, état actif).
  Un nouvel écran = une ligne `<x-nav-lien route=… motif=… icone=…>` + un tracé
  d'icône dans le tableau `$traces`.
- `resources/js/app.js` — menu qui glisse sur téléphone, menus déroulants (`<details>`).
  Pas d'Alpine : les pages non Livewire n'en ont pas.
- `app/Livewire/TableauDeBord.php` + `resources/views/livewire/tableau-de-bord/accueil.blade.php`
  — remplacent `Route::view` et l'ancienne vue `tableau-de-bord.blade.php` (**supprimée**).
  `routes/web.php` : une ligne changée + un `use`.
- `app/Services/Indicateurs.php` — tous les chiffres, en lecture seule, entiers.
- `app/Support/Graphique.php` + `resources/views/components/graphiques/{flux,barres,anneau}.blade.php`
  — graphiques en SVG/HTML rendus côté serveur, **aucune bibliothèque JS**.
- `tests/Feature/TableauDeBordTest.php` — 5 tests.

**Ce que montre la page.** Prix officiel bord-champ de la campagne choisie (écart avec
la précédente, historique par campagne) ; prêts accordés, remis (argent + intrants),
dépenses payées, trésorerie ; entrées/sorties sur 6 mois ; « Revenus » (entrées d'argent
par origine) ; dépenses par catégorie ; avancement des prêts ; actions à mener ; dernières
nouvelles ; bilan campagne par campagne.

**Choix à connaître.**

- **Rien d'inventé** : achats, stock, remboursements, reventes n'existent pas encore.
  « Revenus » = entrées de trésorerie (apports…), **contre-passations et virements
  exclus**, et la page le dit ; quatre cases pointillées « Bientôt sur cette page »
  marquent ce qui viendra. Les brancher = ajouter une méthode dans `Indicateurs`.
- « Actualité » = les derniers faits enregistrés (prêts, versements, dépenses,
  producteurs), pas le journal d'activité.
- **Droits** : totaux de dépenses réservés à `valider-depenses` (un agent saisit, il ne
  voit pas le total — trouvé par le test) ; trésorerie à `gerer-tresorerie` ; prêts à
  `voir-prets`.
- Les agrégats passent par `DB::table()` (pas de modèle : Larastan refuse les colonnes
  calculées) et lisent `mouvements_intrants` directement pour la valeur des intrants.
  **Si la session `f9` renomme cette table ou ses colonnes, `remisParCampagne()` casse.**

**Vérifié.** `php artisan test` : **251 tests** verts (246 + 5), 1136 assertions ;
Larastan 0 erreur (les 12 signalées par `f9` étaient dans `Indicateurs.php`, corrigées) ;
Pint propre ; `npm run build` OK. **Dans Chrome** (`localhost:8000`, compte Comptable de
dév.) : menu actif, prix « Pas encore annoncé » (aucun prix saisi en base), 7 prêts =
21 000 000 FCFA accordés et remis, trésorerie 4 000 000 FCFA, graphique des flux sur
septembre, revenus « Apport de fonds 25 000 000 » sans la contre-passation, fil des
versements, tableau de bilan.

**Téléphone (vérifié ensuite).** Dans un iframe de 390 px (la fenêtre Chrome ne se
redimensionne pas ici) : menu caché hors écran, bouton burger visible, aucun défilement
horizontal (`scrollWidth` = `clientWidth`), grille des chiffres sur 2 colonnes, graphique
lisible. **Vrais clics** : burger → menu ouvert avec voile ; clic sur le voile → fermé.
Par le code : Échap ferme, le burger rouvre. Piège de test : les mesures de position
(`getBoundingClientRect`) retardent d'une étape à cause de la transition de 200 ms ; lire
l'état par les classes ou attendre.

**Pas vérifié.** Les rôles autres que Comptable n'ont été vus qu'en test ; les menus
déroulants « Nouveau » et utilisateur n'ont pas été ouverts sur téléphone.

**Reste.** Commit. Proposition : `f9` commite d'abord la semaine 5 sur `semaine-5`,
puis le front en un commit séparé (les deux touchent `routes/web.php` et le layout :
`git add -p` pour séparer les hunks).

---

## 2026-09-28 — Semaine 5 (en avance) : intrants et prêts en nature — FINI, COMMITÉ

> **Note pour une autre session de travail sur ce dossier.** La semaine 5 a été écrite,
> testée, vérifiée dans Chrome et commitée par la session `ly-agricole-f9` (branche
> `semaine-5`) ; **ne pas la refaire**. Travail fait en parallèle de la session
> `ly-agricole-fb` (front) : deux commits séparés, voir plus bas.

**Fichiers de la semaine 5 (à ne pas retoucher sans se coordonner).**

- Nouveaux : `database/migrations/2026_10_24_000001_create_intrants_tables.php`,
  `app/Enums/UniteIntrant.php`, `app/Enums/TypeMouvementIntrant.php`,
  `app/Models/Intrant.php`, `app/Models/MouvementIntrant.php`,
  `app/Services/StockIntrants.php`, `app/Services/RecuRemise.php`,
  `app/Livewire/Intrants/StockIntrant.php`, `app/Livewire/Referentiels/Intrants.php`,
  `resources/views/livewire/intrants/stock-intrant.blade.php`,
  `resources/views/prets/recu-remise.blade.php`, `tests/Feature/Intrants/*`.
- Modifiés : `app/Models/Pret.php` (`valeurIntrantsRemis`, `montantRemis`,
  `resteARemettre`, `restantDu` ; `resteADecaisser` **supprimée**),
  `app/Enums/FormePret.php` (`accepteArgent`, `accepteIntrants` ; `disponible()`
  supprimée), `app/Services/Prets.php` (`marquerSiToutRemis`), `FichePret` + sa vue
  (remise d'intrants, mode de versement pour un prêt mixte, reçus PDF), `ListePrets` +
  vue (« Remis (argent + intrants) »), `PretController::recuPdf`, onglet Intrants dans
  `EcranReferentiel::onglets()`, droit `gerer-intrants` et morph map dans
  `AppServiceProvider`, tests de prêts et `AccesReferentielsTest` mis à jour,
  `CLAUDE.md` (piège apostrophe + `assertDontSee`).
- **Partagés avec la session tableau de bord** : `routes/web.php` (mes routes :
  `/intrants`, `referentiels.intrants`, `prets.recu-pdf`) et
  `resources/views/components/layouts/app.blade.php` (mon lien « Intrants », repris
  dans le nouveau menu latéral : ne pas le retirer).

**Fait.** Stock d'intrants en unités de conditionnement (sac, bidon, pièce, rouleau ;
pas de kg en vrac, D4), registre `mouvements_intrants` 🔒 ; stock = Σ ≥ 0 par magasin ;
entrée, perte, ajustement, contre-passation motivée. Prêts `intrants` et `mixte`
activés (question 2 toujours ouverte) : distribution à crédit depuis la fiche du prêt,
valeur **figée au prix du jour** ; remis = argent + intrants ≤ montant ; **restant dû =
remis** (pas d'intérêt, question 4 ; remboursements en semaine 6). Reçu PDF A5 de chaque
remise (argent ou intrants) à faire signer.

**Vérifié.** 246 tests verts (223 → 246) **avant** les changements de menu de l'autre
session ; livrable en test (prêt mixte 1 000 000 = 20 sacs NPK à 18 500 + 630 000 en
espèces → restant dû 1 000 000, stock 100 → 80). Larastan : 0 erreur dans mes
fichiers ; les 12 erreurs actuelles sont dans `app/Services/Indicateurs.php` (session
tableau de bord). Migration appliquée sur MySQL.

**Vérifié ensuite sur le code combiné avec le front de `ly-agricole-fb`.** 251 tests
verts, Pint propre, Larastan 0. **Livrable de la semaine 5, dans Chrome** (MySQL,
nouveau menu latéral) : comptable → fiche « NPK 15-15-15 — sac de 50 kg » à 18 500 FCFA,
entrée de 100 sacs au Magasin Chrome ; agent → prêt **mixte** LYPR-000008 de
1 000 000 ; direction → validé ; comptable → remise de 20 sacs (aperçu « 370 000 FCFA »,
restant dû 370 000, reste à remettre 630 000), puis 630 000 en espèces avec reçu →
**Décaissé, remis 1 000 000 (argent 630 000 · intrants 370 000), restant dû
1 000 000**. MySQL : stock NPK 80, argent 630 000 + intrants 370 000. Reçu PDF de la
remise dessiné avec pdf.js **dans la page** (sans fichier temporaire) et regardé.

**Corrigé en vérifiant.** Reçu PDF : la date de l'en-tête passait sous le filet (bloc
flottant que dompdf faisait déborder → tableau) ; parenthèses doublées « (Mixte (argent
+ intrants)) ».

**À savoir pour les scripts de vérification.** Avec le nouveau menu, « Se déconnecter »
est dans un `<details>` fermé : son `innerText` est vide. Soumettre directement
`form[action$="/deconnexion"]`.

**Commits.** Semaine 5 seule sur `semaine-5` ; le front de `ly-agricole-fb` dans un
commit séparé juste après (dans `routes/web.php`, le layout et ce fichier, chaque
commit ne contient que les lignes de sa session). La modification de
`.claude/skills/ly-agricole-metier/SKILL.md` (faite par l'utilisateur) n'est dans aucun
des deux.

---

## 2026-09-28 — Semaine 4 (en avance) : prêts de campagne

**Fait.**

- Tables `prets`, `validations_pret` 🔒, `pret_parcelle`, `decaissements` 🔒 ;
  nature de mouvement `decaissement_pret` ; paramètres `plafond_pret_producteur_fcfa`,
  `plafond_pret_hectare_fcfa`.
- `App\Services\Prets` : demande (producteur actif, campagne non clôturée, échéance
  future, parcelles du producteur, plafonds s'ils sont définis, art. 17.3 avec accord
  écrit) ; validation par la **direction**, jamais l'auteur, **deux validateurs
  distincts au-dessus du seuil ou seuil non défini** ; refus motivé ; décaissement par
  tranches (≤ reste, même forme que le prêt, bon type de compte, reçu signé en espèces,
  référence en Mobile Money), sous verrou ; `decaisse` quand tout est versé ; une
  contre-passation du versement rouvre le reste (`valide`).
- Écrans : portefeuille (demandes, accordé, décaissé, reste, kilos attendus) et liste ;
  demande (parcelles cochées, partie liée) ; fiche (validations, refus, versements,
  pièces sur disque privé).
- Droits : `voir-prets`, `saisir-prets` (direction, comptable, agent), `valider-prets`
  (direction), `decaisser-prets` (direction, comptable).

**Décisions prudentes en attendant les questions 2, 3, 4, 5** : seulement espèces et
Mobile Money ; prix de référence = **estimation** des kilos, pas la règle de
valorisation ; **aucun intérêt** ; seuil non défini = double validation ; plafond non
défini = pas de plafond automatique (la validation reste).

**Vérifié en l'exécutant.**

- `php artisan test` : **223 tests** (194 → 223), 1 024 assertions, dont le livrable en
  test (7 × 3 M → caisse − 21 M). Larastan 0, Pint propre.
- **Livrable de la semaine 4, dans Chrome, de bout en bout** (MySQL) : agent → 5
  producteurs de plus (7) ; direction → seuil de prêt 5 000 000 (**valeur d'essai**,
  question 5) ; comptable → apport de 20 000 000 (caisse 25 000 000) ; agent → 7
  demandes de 3 000 000 à 400 FCFA/kg ; direction → 7 validations (bouton) ; comptable
  → versement sans reçu refusé (« joindre le reçu signé »), puis 7 versements avec reçu.
  Portefeuille : 7 accordés · 21 000 000, décaissé 21 000 000, reste 0, 52 500 kg
  attendus. **MySQL : caisse 4 000 000, 21 000 000 décaissés en 7 versements**, auteur
  (agent) ≠ validateur (direction) sur les 7.

**Surprise.** Un versement (LYPR-000006) n'était pas parti : clic pendant l'envoi du
reçu ; vu dans MySQL, pas à l'écran, et refait. Même comportement qu'en semaine 2
(bouton désactivé le temps d'une requête) : un utilisateur doit attendre la fin de
l'envoi du fichier.

**Reste.** Réponses aux questions 2, 3, 4, 5 ; prêts en intrants (semaine 5) ;
remboursements et statuts `en_cours` / `solde` / `perte` (semaine 6).

---

## 2026-09-28 — Semaine 3 (en avance) : trésorerie et dépenses

**Fait.**

- `comptes_tresorerie` (caisse, banque, Wave, Orange Money, MTN, Moov ; caisse
  d'agent ; compte dédié à une campagne), `mouvements_tresorerie` 🔒 (trait
  `Immuable`), `categories_depense`, `depenses` 📱 (UUID v7).
- `App\Services\Tresorerie`, seule porte des mouvements : entrée, virement (deux jambes
  liées, tout ou rien), avance agent, paiement de dépense, **contre-passation**
  motivée (un virement : ses deux jambes ; une dépense payée passe « annulée »). Comptes
  verrouillés dans l'ordre des id ; **aucun solde négatif** ; date future, montant nul,
  compte désactivé refusés. Solde = somme SQL, pas de colonne.
- `App\Services\Depenses` : **seuil non défini ⇒ validation toujours exigée** ; sous
  le seuil, payée tout de suite ; au-dessus, l'argent sort **à la validation** ;
  `valide_par ≠ cree_par` vérifié dans le service (invariant 5) ; validation sous
  verrou (pas de double paiement) ; refus motivé ; art. 10.3 bloqué sur compte de
  campagne et dépense rattachée à une campagne ; un agent ne paie que depuis sa caisse.
- **Avances aux agents = virement vers leur caisse**, le reste à justifier est le
  solde de cette caisse (table `avances_agents` du modèle supprimée, documenté).
- Écrans : Trésorerie (comptes et soldes, entrée, virement, avance, nouveau compte),
  relevé avec solde courant et contre-passation, Dépenses (saisie avec justificatif
  obligatoire, liste, valider / refuser), catégories dans les Référentiels.
  `App\Support\Montant` : « 1 500 000 » accepté, « 1.500 » et « 1500,5 » **refusés**
  (ambigus).
- Droits : `gerer-tresorerie`, `valider-depenses` (direction, comptable) ;
  `saisir-depenses` (+ agent).

**Vérifié en l'exécutant.**

- `php artisan test` : **194 tests** (150 → 194), 872 assertions ; invariants 3
  (solde = somme, ≥ 0, y compris au-delà de 2³¹ FCFA), 5 et 6 sur les mouvements.
  Larastan 0 (3 corrigées), Pint propre.
- **Livrable de la semaine 3, dans Chrome** (MySQL) : comptable → compte « Caisse
  centrale », apport de 5 000 000 ; catégorie Carburant ; dépense de 600 000 (seuil à
  500 000) avec justificatif → « à valider par un autre », pas de bouton ; **appel
  forcé de `valider` depuis la console du navigateur → refusé par le serveur** (« Vous
  ne pouvez pas valider votre propre dépense ») ; direction → Valider → payée,
  relevé à 4 400 000 ; contre-passation motivée depuis le relevé → 5 000 000, dépense
  « annulée ». **Somme recalculée dans MySQL : 5 000 000**, `cree_par` 3 ≠
  `valide_par` 2.

**Bugs trouvés et corrigés en route.**

- Après un essai refusé (motif trop court), le message d'erreur **restait affiché**
  après l'essai réussi : `resetErrorBag()` en tête des actions.
- « Annulée par Direction » laissait croire que le validateur avait annulé :
  « validée par » / « refusée par ».

**Session interrompue** pendant la dernière série de vérifications. À la reprise,
6 tests échouaient (`RootTagMissingFromViewException` sur la liste des dépenses) :
vue Blade **compilée** tronquée par l'interruption, pas le code. `php artisan
view:clear` → 194/194, Larastan 0, Pint propre. Piège ajouté à `CLAUDE.md`.

**Reste en semaine 3.** Catégories réelles et charges exclues (question 23), seuil de
dépense à confirmer (question 5 ; 500 000 FCFA n'est qu'une valeur d'essai dans la base
locale).

---

## 2026-09-28 — Semaine 2 (en avance) : producteurs, groupes, parcelles, carte QR

**Fait.**

- `producteurs` (UUID v7, D3) : fiche au bureau (identité, pièce, téléphone et Mobile
  Money normalisés à 10 chiffres, village, groupe, photo). **Consentement obligatoire**
  à la création (qui, quand) ; texte provisoire → question 21.
- Code de carte `LYP-000001` attribué par le serveur (`compteurs`, verrou de ligne,
  pas de trou si la création échoue).
- Doublons (`DetectionDoublons`) : même pièce = **refus** ; même téléphone ou même
  Mobile Money (croisés) = **alerte** à confirmer, confirmation inscrite au journal
  (`doublon_confirme`).
- Photo sur le disque **privé**, servie par une route qui vérifie le droit ; l'ancienne
  est effacée au remplacement (minimisation) ; pas de photo orpheline si l'écriture échoue.
- Droits : `voir-producteurs` (direction, agent, comptable, agronome),
  `gerer-producteurs` (direction, agent). Ni admin ni investisseur (données personnelles).
- Liste avec recherche (nom, code, téléphone même tapé « +225 07 … ») et filtre village.
- Groupes de producteurs (écran commun `EcranReferentiel`, sans les onglets ;
  responsable = producteur du village ; nombre de membres).
- `parcelles` (UUID v7) : contour GeoJSON importé (fichier ou texte collé) ;
  `App\Services\Geo\Contour` lit strictement (fermé, ≥ 3 sommets, en Côte d'Ivoire, avec
  un message dédié si latitude/longitude inversées) et calcule la surface sur la sphère
  (méthode turf.js) ; `surface_m2` recalculée par le modèle, **non affectable**, contour
  verrouillé côté Livewire. Dessin SVG sans fond de carte. Sans contour : « non relevée ».
- Carte producteur PDF (dompdf) au format ID-1 sur A4, QR (php-qrcode, correction Q) ne
  contenant **que le code** ; pas de téléphone ni de numéro de pièce sur la carte ;
  chaque impression journalisée (`impression_carte`).
- `Format::hectares()` en entiers.

**Vérifié en l'exécutant.**

- `php artisan test` : **150 tests** (97 → 150), 706 assertions. Larastan 0, Pint propre.
- Le QR généré, **décodé** par le lecteur de php-qrcode, redonne le code (test).
- **Dans Chrome** (agent, MySQL) : fiche sans consentement → refus en français ; avec →
  `LYP-000001`, photo affichée par la route privée, téléphone `+225 07 11 22 33 44` →
  `0711223344` ; parcelle carrée de 150 m collée en GeoJSON → **2,25 ha**, carré dessiné,
  total « 1 ; 2,25 ha relevés » sur la fiche ; 2ᵉ fiche avec le même téléphone →
  alerte nommant `Coulibaly Awa (LYP-000001)`, confirmée → `LYP-000002` ; journal MySQL :
  créations, impression de carte, doublon confirmé, au nom de l'agent.
- Carte : PDF dessiné avec pdf.js (page temporaire, supprimée) et regardé ; puis le QR
  **découpé dans le rendu du PDF** et décodé côté serveur → `LYP-000001`.

**Bugs trouvés et corrigés en route.**

- Choisir un PDF comme photo faisait **planter** le formulaire (aperçu d'un fichier non
  image) : validation dès le choix + `isPreviewable()` ; test de non-régression.
- Carte à **1,1 Mo** (police entière embarquée) → sous-ensemble de police : 30 Ko.
- « LY AGRICOLE » coupé dans le bandeau de la carte (dompdf et `line-height`) → padding.

**Pas vérifié.** Le scan de la carte **imprimée** avec un vrai téléphone (livrable de la
semaine 2 : « la scanner au téléphone ») : à faire à la main. La saisie des 10 producteurs
réels de test attend les vraies zones (question 1).

**Surprise.** Un clic sur « Enregistrer » pendant la requête d'un `wire:model.live`
(choix du village) est ignoré : le bouton est désactivé le temps de la requête.
Normal, mais un utilisateur rapide devra recliquer.

---

## 2026-09-28 — Semaine 1 : installation et écran de connexion

**Fait.**

- MySQL (XAMPP) démarré, base `ly_agricole` créée (utf8mb4).
- Laravel 13.33 installé dans `../ly-agricole-installation` puis déplacé ici sans
  écraser `docs/`, `.claude/`, `CLAUDE.md`, `README.md`, `.editorconfig`. Le
  `CLAUDE.md`/`AGENTS.md` de Laravel (consignes d'installation génériques) et son
  README n'ont pas été repris.
- Livewire 3.8.9 **sans starter kit** (le kit tire Livewire 4 ; choix du développeur),
  Larastan 3.12 (niveau 6, `phpstan.neon`). Tailwind 4.3 était déjà configuré par
  Laravel 13 : seulement `npm install` + `npm run build`.
- `.env` : MySQL, `APP_LOCALE=fr`, `APP_FAKER_LOCALE=fr_FR`, nom « LY AGRICOLE ».
- Connexion : composant `App\Livewire\Auth\Connexion` (e-mail + mot de passe, « rester
  connecté »), 5 essais par e-mail + IP puis blocage temporaire, compte `actif = false`
  refusé avec le même message qu'un mauvais mot de passe. Pas d'inscription publique
  ni de « mot de passe oublié » : les comptes et les mots de passe passent par l'admin.
  Déconnexion en POST. `/` → `/tableau-de-bord` (page provisoire).
- `users` : `name` renommé `nom`, ajout de `telephone` (unique) et `actif`, en
  modifiant directement la migration de base (base neuve, vide).
- Messages en français : `lang/fr/auth.php`, `lang/fr/validation.php` (limité aux
  règles utilisées ; le reste retombe sur l'anglais).
- Seeder de développement : `admin@ly-agricole.test` (mot de passe de la fabrique).

**Vérifié en l'exécutant.**

- `php artisan test` : **11 tests** (0 → 11 ; les 2 exemples de Laravel supprimés),
  45 assertions, tous verts. Après la série, la base MySQL a gardé ses migrations :
  les tests tournent bien sur sqlite en mémoire.
- Parcours réel contre `php artisan serve` + MySQL, par HTTP (script qui rejoue
  exactement l'appel `/livewire/update` du navigateur) : page affichée → mauvais mot
  de passe, message français affiché → bon mot de passe, redirection
  `/tableau-de-bord` → « Bienvenue, Admin Développement. » → déconnexion → le
  tableau de bord renvoie à `/connexion`. `livewire.js` injecté et servi (200).
- Tailwind : les classes des vues (valeurs arbitraires, variantes) sont dans la CSS
  compilée. Pint : rien à corriger. Larastan niveau 6 : 0 erreur.

**Pas vérifié.** Le parcours **dans un vrai navigateur** : l'extension Chrome n'était
pas connectée. À refaire à l'écran (clic, rendu, état de chargement du bouton).

**Surprises.**

- `mysql_error.log` au démarrage : erreurs InnoDB (« log sequence number is in the
  future », tablespaces `immoconnect` introuvables). Elles viennent des autres bases de
  ce XAMPP partagé ; le serveur démarre et `ly_agricole` fonctionne. Non touché.
- Une page a mis 19 s pendant que PHPStan tournait (0,3 s ensuite) : ne pas mesurer
  les temps pendant une analyse.
- Extension PHP `intl` absente (non bloquant pour l'instant ; nécessaire pour
  `Number::format`).

**Premier commit** fait et poussé par le développeur (`4267663`, sur `origin/main`).

### Suite : rôles et gestion des utilisateurs

**Fait.**

- `App\Enums\Role` (admin, direction, comptable, agent, agronome, investisseur), colonne
  `users.role` (nouvelle migration, **sans valeur par défaut** : sans rôle = aucun
  droit, message sur le tableau de bord).
- Droits nommés dans `AppServiceProvider::definirLesDroits()` ; pour l'instant
  `gerer-utilisateurs` (admin). **Pas** de `Gate::before` pour l'admin (séparation des
  tâches). Matrice à valider : question ouverte n° 19.
- Écran `/utilisateurs` (`GestionUtilisateurs`) : liste, création, modification,
  désactivation, nouveau mot de passe. Droit revérifié dans **chaque** action Livewire.
  L'admin ne peut ni se désactiver ni changer son propre rôle.
- Middleware `CompteActif` : un compte désactivé perd sa session ouverte à la requête
  suivante.
- Menu affiché selon les droits ; page 403 en français.
- Seeder : un compte par rôle, `<role>@ly-agricole.test`.

**Vérifié en l'exécutant.**

- `php artisan test` : **28 tests** (11 → 28), 148 assertions. Larastan 0 erreur
  (il a trouvé les propriétés du modèle `User` non déclarées → `@property` ajoutés).
- **Dans Chrome** : mauvais mot de passe → message français ; connexion admin →
  lien « Utilisateurs », liste des 6 comptes ; création d'un compte (mot de passe trop
  court refusé en français, puis accepté) ; déconnexion ; connexion avec le nouveau
  compte agent → pas de lien, `/utilisateurs` → 403 « Accès refusé ».

**Surprises.**

- **Un service worker de VistaResidence contrôle `http://127.0.0.1:8000` dans Chrome**
  (caches `vistimmob-*`) et bloque les navigations. Non touché (il appartient à
  l'autre projet) : tester LY AGRICOLE sur **`http://localhost:8000`**, qui est une autre
  origine. À ajouter aux pièges de `CLAUDE.md`.
- L'outil d'automatisation de Chrome perdait ses frappes juste après un chargement de
  page (aucune requête n'atteignait le serveur, vérifié dans le journal de
  `artisan serve`). Contourné en remplissant les champs par JavaScript dans la page ;
  ce n'est pas un défaut de l'appli.
- Après un 403, l'objet de test Livewire ne peut plus rejouer d'appel : un composant
  neuf par action dans le test.

### Suite : journal d'activité

**Fait.**

- Table `journal_activite` 🔒 (user, action, objet_type/objet_id en texte pour les
  futurs UUID, avant/apres JSON, ip, appareil, at). Pas de `nullOnDelete` sur `user_id`
  (il réécrirait le journal).
- **Protection réutilisable des registres immuables**, pour les registres des semaines
  3 à 6 : trait `App\Models\Concerns\Immuable` (bloque `save`/`update`/`delete`, **y
  compris** `saveQuietly`/`deleteQuietly`, car il remplace `performUpdate` et
  `performDeleteOnModel` au lieu d'écouter des événements) +
  `#[UseEloquentBuilder(BuilderImmuable::class)]` (bloque `query()->update()`,
  `delete()`, `increment()`, `upsert()`…). Le trait refuse de démarrer si l'attribut
  manque. Reste possible : SQL brut via `DB::table()` — ne jamais en écrire sur ces
  tables.
- Trait `Journalise` (sur `User`) : création, modification (seulement les champs
  changés, avant → après), suppression. Attributs `$hidden` écrits « (masqué) ».
  Changement du seul `remember_token` / `updated_at` : pas de ligne (sinon chaque
  déconnexion en ferait une). Écrit dans la même transaction : une opération annulée
  n'a pas de trace.
- Connexion, déconnexion, échec (avec l'adresse tapée, même inconnue) et blocage après
  5 essais sont journalisés.
- `Relation::enforceMorphMap` : `objet_type` = nom court stable (`user`), pas un nom de
  classe. Tout nouveau modèle référencé devra y être déclaré.
- Écran `/journal` (admin et direction, droit `voir-journal`) : filtres action et
  utilisateur (gardés dans l'adresse), 50 lignes par page, valeurs lisibles
  (« oui/non », « (vide) »). Pagination en français.

**Vérifié en l'exécutant.**

- `php artisan test` : **59 tests** (28 → 59), 248 assertions. Invariant 6 testé sur
  10 manières de modifier ou supprimer une ligne. Larastan : 0 erreur (8 corrigées :
  types manquants, appel `static::` à une méthode privée, type générique du builder
  → réglé par l'attribut `UseEloquentBuilder`). Pint : propre.
- **Dans Chrome** (localhost:8000, base MySQL migrée) : déconnexion de l'agent, connexion
  admin, modification du téléphone d'un compte → le journal montre les trois lignes,
  avec auteur, IP et « (vide) → 0700000001 » ; filtre « Connexion » → une seule ligne,
  `?action=connexion` dans l'adresse ; compte direction → lien Journal, pas de lien
  Utilisateurs, `/utilisateurs` → « Accès refusé », `/journal` → sa propre connexion en
  tête.

**Surprise.** La première vérification des valeurs dans le journal a montré du JSON
brut (`"Ancien Nom"`, `true`) : illisible pour la direction ; remplacé par
`JournalActivite::valeurLisible()`.

### Suite : référentiels

**Fait.**

- Tables `zones`, `villages`, `produits`, `campagnes`, `magasins`, `points_collecte`,
  `parametres` (une migration). Pas de suppression : champ `actif`. Tous `Journalise`,
  tous déclarés dans le morph map.
- Écarts avec `MODELE_DE_DONNEES.md`, reportés dans le document : `magasins.capacite_g`
  (grammes, D4) au lieu de `capacite_kg` ; pas de `produits.unite` (tout se pèse).
- Écran commun `EcranReferentiel` (liste, ajout, modification, onglets selon les
  droits) ; sous-classes Zones, Villages, Produits, Magasins, PointsCollecte, Campagnes.
  `peutModifier()`/`actionsLigne()` protégées (une méthode publique Livewire est
  appelable depuis le navigateur). Route de la page gardée dans `$routePage` verrouillé
  (sinon l'onglet actif se perd après une action : la route devient `livewire.update`).
- Campagnes (direction seule) : code `AAAA-AAAA` unique par produit, fin ≥ début, prix
  officiel en FCFA entiers **facultatif** (non annoncé), action « Ouvrir », **une seule
  ouverte par produit** (verrou `lockForUpdate`), produit figé une fois ouverte,
  clôturée non modifiable. Pas de clôture : elle viendra avec le résultat et D6.
- Paramètres (direction seule) : 3 seuils de validation (dépense, achat, prêt), **aucune
  valeur par défaut**, « Non défini » affiché en orange. `Parametre::entier()` rend
  `null` si non défini — au code des semaines 3 à 6 d'exiger alors la validation.
- `App\Support\Format::fcfa()` / `::kg()` : affichage seulement, calcul **en entiers**
  (pas de division flottante), testé au-delà de 2³¹ g.
- Seeder de dev : produits anacarde, karité, tomate ; « Zone de test » / « Village de
  test ». Aucun prix, aucun seuil.

**Vérifié en l'exécutant.**

- `php artisan test` : **97 tests** (59 → 97), 460 assertions ; matrice des droits
  figée par `AccesReferentielsTest` (7 écrans × 6 rôles). Larastan 0 erreur, Pint propre.
- **Dans Chrome** (direction, base MySQL migrée) : zone créée ; village avec GPS ;
  produit Anacarde ; campagne 2026-2027 avec d'abord fin < début → message français,
  puis correcte, puis « Ouvrir » → « Ouverte », prix « Non annoncé » ; magasin de
  250 000 kg → « 250 000 kg » ; seuil de dépense 500 000 FCFA, les deux autres « Non
  défini ». Le journal montre chacune de ces opérations avec son auteur, et l'ouverture
  en « statut : preparation → ouverte ». L'onglet actif reste marqué après une action.

**À améliorer (non bloquant).** Le journal affiche les noms de colonnes et les valeurs
brutes (`capacite_g : 250000000`, `zone_id : 1`) : exact pour un audit, peu parlant pour
la direction. Messages « Zone Zone Chrome : créé(e). » quand le nom contient déjà le type.

**Semaine 1 : définition de « fini ».** Un utilisateur par rôle se connecte et ne voit
que ses écrans (tests + Chrome) ; toute création apparaît au journal (tests + Chrome) ;
le nombre de tests a augmenté (0 → 97). Commit `5b9ab56` sur la branche `semaine-1`.
Reste : les réponses aux questions 1, 5, 19, 20.

---

## 2026-09-25 — Création du dossier projet

**Fait.**

- Lecture de toutes les conversations claude.ai du compte « LY Agricole » (sauf DZTV) :
  cahier des charges du site vitrine, contrat de campagne et ses deux relectures, projet
  maraîchage / poules / bissap (volet France, distinct).
- Cahier des charges de la plateforme rédigé dans le document partagé
  <https://claude.ai/code/artifact/e20c4dc8-2b2f-4808-809a-da343a2a9222>, puis étendu
  (achats, stock, reventes, dépenses, trésorerie, comptabilité) et complété (§10 :
  confirmation SMS, carte QR, séparation des tâches, intrants, données personnelles…).
- Dossier créé : `docs/` (cahier, décisions, modèle de données, plan, questions),
  `CLAUDE.md`, quatre skills sous `.claude/skills/`, dépôt Git initialisé **sans
  commit**.

**Pas encore fait.** Aucun code : Laravel est installé en semaine 1 (samedi 26 sept.).

**À faire avant samedi.** Faire relire le cahier des charges au responsable projet
(le document partagé est privé tant qu'il n'est pas partagé depuis son menu) et obtenir
les réponses bloquantes de `docs/QUESTIONS_OUVERTES.md`.
