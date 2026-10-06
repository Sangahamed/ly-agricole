<?php

use App\Http\Controllers\AchatController;
use App\Http\Controllers\Auth\DeconnexionController;
use App\Http\Controllers\DepenseController;
use App\Http\Controllers\PhotoTerrainController;
use App\Http\Controllers\PretController;
use App\Http\Controllers\PrixController;
use App\Http\Controllers\ProducteurController;
use App\Http\Controllers\RapportCampagneController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\ReferencementController;
use App\Http\Controllers\TacheCronController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\VitrineController;
use App\Livewire\Achats\FormulaireAchat;
use App\Livewire\Achats\ListeAchats;
use App\Livewire\Appareils\ListeAppareils;
use App\Livewire\Auth\ChangerMotDePasse;
use App\Livewire\Auth\Connexion;
use App\Livewire\Budget\SuiviBudget;
use App\Livewire\Depenses\FormulaireDepense;
use App\Livewire\Depenses\ListeDepenses;
use App\Livewire\Fiabilite\FicheFiabilite;
use App\Livewire\Fiabilite\ListeFiabilite;
use App\Livewire\Fiabilite\ListeGroupes;
use App\Livewire\Fiabilite\SituationGroupe;
use App\Livewire\Ia\ListeDiagnostics;
use App\Livewire\Ia\ReferentielTraitements;
use App\Livewire\Intrants\StockIntrant;
use App\Livewire\Investisseurs\GestionApports;
use App\Livewire\Investisseurs\PortailInvestisseur;
use App\Livewire\Journal\ConsultationJournal;
use App\Livewire\Notifications\ListeNotifications;
use App\Livewire\Prets\FichePret;
use App\Livewire\Prets\FormulairePret;
use App\Livewire\Prets\ListePrets;
use App\Livewire\Producteurs\FormulaireParcelle;
use App\Livewire\Producteurs\FormulaireProducteur;
use App\Livewire\Producteurs\Groupes;
use App\Livewire\Producteurs\ListeProducteurs;
use App\Livewire\Publications\GestionPublications;
use App\Livewire\RapportCampagne\PointEtape;
use App\Livewire\Referentiels\Campagnes;
use App\Livewire\Referentiels\CategoriesDepense;
use App\Livewire\Referentiels\EcranReferentiel;
use App\Livewire\Referentiels\Intrants as IntrantsReferentiel;
use App\Livewire\Referentiels\Langues;
use App\Livewire\Referentiels\Magasins;
use App\Livewire\Referentiels\Parametres;
use App\Livewire\Referentiels\Pisteurs;
use App\Livewire\Referentiels\PointsCollecte;
use App\Livewire\Referentiels\Produits;
use App\Livewire\Referentiels\Villages;
use App\Livewire\Referentiels\Zones;
use App\Livewire\Rendements\ClassementRendements;
use App\Livewire\Rendements\EvolutionProducteur;
use App\Livewire\Resultat\ResultatDeCampagne;
use App\Livewire\Stock\FicheLot;
use App\Livewire\Stock\ListeLots;
use App\Livewire\TableauDeBord;
use App\Livewire\Tresorerie\Comptes;
use App\Livewire\Tresorerie\ReleveCompte;
use App\Livewire\Utilisateurs\GestionUtilisateurs;
use App\Livewire\Ventes\FicheVente;
use App\Livewire\Ventes\FormulaireVente;
use App\Livewire\Ventes\ListeVentes;
use App\Livewire\Visites\ListeVisites;
use Illuminate\Support\Facades\Route;

// Vitrine publique : on n'atterrit plus directement sur la connexion. Sans aucune donnée
// de l'application, et sans mention de l'opération d'investissement (contrat art. 2.3 :
// aucune publicité).
Route::get('/', [VitrineController::class, 'accueil'])->name('accueil');
Route::get('/prix', [PrixController::class, 'evolution'])->name('prix.evolution');
Route::get('/actualites', [VitrineController::class, 'actualites'])->name('actualites');
Route::get('/actualites/{actualite}', [VitrineController::class, 'actualite'])->name('actualites.voir');

// Référencement : plan du site, robots.txt et llms.txt générés depuis les prix publiés.
Route::get('/sitemap.xml', [ReferencementController::class, 'plan'])->name('referencement.plan');
Route::get('/robots.txt', [ReferencementController::class, 'robots'])->name('referencement.robots');
Route::get('/llms.txt', [ReferencementController::class, 'llms'])->name('referencement.llms');

// Tâches planifiées appelées par Vercel Cron (protégées par CRON_SECRET).
Route::get('/cron/{tache}', TacheCronController::class)->name('cron');

// Nommée `login` : c'est la route où Laravel renvoie un visiteur non connecté.
Route::get('/connexion', Connexion::class)->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/tableau-de-bord', TableauDeBord::class)->name('tableau-de-bord');
    Route::get('/mon-mot-de-passe', ChangerMotDePasse::class)->name('mot-de-passe');

    Route::get('/utilisateurs', GestionUtilisateurs::class)
        ->middleware('can:ouvrir-comptes')
        ->name('utilisateurs');

    // Rapports de la direction et exports (semaine 10).
    Route::get('/rapports', [RapportController::class, 'index'])->middleware('can:voir-rapports')->name('rapports');
    Route::get('/rapports/{rapport}', [RapportController::class, 'afficher'])->middleware('can:voir-rapports')
        ->whereIn('rapport', array_keys(RapportController::RAPPORTS))->name('rapports.voir');

    Route::get('/journal', ConsultationJournal::class)
        ->middleware('can:voir-journal')
        ->name('journal');

    Route::prefix('producteurs')->name('producteurs')->group(function () {
        Route::get('/', ListeProducteurs::class)->middleware('can:voir-producteurs')->name('');
        Route::get('/nouveau', FormulaireProducteur::class)->middleware('can:gerer-producteurs')->name('.nouveau');
        Route::get('/groupes', Groupes::class)->middleware('can:gerer-producteurs')->name('.groupes');
        Route::get('/{producteur}', [ProducteurController::class, 'fiche'])->middleware('can:voir-producteurs')->name('.fiche');
        Route::delete('/{producteur}', [ProducteurController::class, 'supprimer'])->middleware('can:annuler-operations')->name('.supprimer');
        Route::get('/{producteur}/modifier', FormulaireProducteur::class)->middleware('can:gerer-producteurs')->name('.modifier');
        Route::get('/{producteur}/photo', [ProducteurController::class, 'photo'])->middleware('can:voir-producteurs')->name('.photo');
        Route::get('/{producteur}/carte', [ProducteurController::class, 'carte'])->middleware('can:gerer-producteurs')->name('.carte');
        Route::get('/{producteur}/parcelles/nouvelle', FormulaireParcelle::class)->middleware('can:gerer-producteurs')->name('.parcelles.nouvelle');
        Route::get('/{producteur}/parcelles/{parcelle}/modifier', FormulaireParcelle::class)
            ->middleware('can:gerer-producteurs')->scopeBindings()->name('.parcelles.modifier');
    });

    // Référentiels : chaque écran vérifie son propre droit (EcranReferentiel::onglets()).
    Route::get('/referentiels', function () {
        $route = EcranReferentiel::premierOngletAutorise();
        abort_if($route === null, 403);

        return redirect()->route($route);
    })->name('referentiels');

    Route::prefix('referentiels')->name('referentiels.')->group(function () {
        Route::get('/zones', Zones::class)->middleware('can:gerer-referentiels')->name('zones');
        Route::get('/villages', Villages::class)->middleware('can:gerer-referentiels')->name('villages');
        Route::get('/produits', Produits::class)->middleware('can:gerer-referentiels')->name('produits');
        Route::get('/campagnes', Campagnes::class)->middleware('can:gerer-campagnes')->name('campagnes');
        Route::get('/magasins', Magasins::class)->middleware('can:gerer-referentiels')->name('magasins');
        Route::get('/points-collecte', PointsCollecte::class)->middleware('can:gerer-referentiels')->name('points-collecte');
        Route::get('/parametres', Parametres::class)->middleware('can:gerer-parametres')->name('parametres');
        Route::get('/categories-depense', CategoriesDepense::class)->middleware('can:gerer-tresorerie')->name('categories-depense');
        Route::get('/intrants', IntrantsReferentiel::class)->middleware('can:gerer-intrants')->name('intrants');
        Route::get('/pisteurs', Pisteurs::class)->middleware('can:gerer-referentiels')->name('pisteurs');
        Route::get('/langues', Langues::class)->middleware('can:gerer-referentiels')->name('langues');
    });

    // Achats : la liste vérifie elle-même le droit (saisir OU valider).
    Route::prefix('achats')->name('achats')->group(function () {
        Route::get('/', ListeAchats::class)->name('');
        Route::get('/nouveau', FormulaireAchat::class)->middleware('can:saisir-achats')->name('.nouveau');
        Route::get('/{achat}/bon', [AchatController::class, 'bon'])->name('.bon');
    });

    // Photos du terrain (pesée, justificatifs) : le contrôleur vérifie le droit.
    Route::get('/photos-terrain/{photo}', [PhotoTerrainController::class, 'afficher'])->name('photos-terrain');

    // Ventes : négociées au bureau (direction, comptabilité) ; l'encaissement est séparé.
    Route::prefix('ventes')->name('ventes')->group(function () {
        Route::get('/', ListeVentes::class)->middleware('can:voir-ventes')->name('');
        Route::get('/nouvelle', FormulaireVente::class)->middleware('can:saisir-ventes')->name('.nouvelle');
        Route::get('/{vente}', FicheVente::class)->middleware('can:voir-ventes')->name('.fiche');
    });

    Route::prefix('lots')->name('lots')->middleware('can:gerer-stock')->group(function () {
        Route::get('/', ListeLots::class)->name('');
        Route::get('/{lot}', FicheLot::class)->name('.fiche');
    });

    Route::prefix('tresorerie')->name('tresorerie')->middleware('can:gerer-tresorerie')->group(function () {
        Route::get('/', Comptes::class)->name('');
        Route::get('/comptes/{compte}', ReleveCompte::class)->name('.releve');
    });

    // Apports de campagne (direction, comptabilité) et portail en lecture seule de
    // l'investisseur (deux écrans distincts : pas les mêmes droits ni la même vue).
    Route::get('/apports', GestionApports::class)->middleware('can:gerer-apports')->name('apports');
    Route::get('/rendements', ClassementRendements::class)->middleware('can:voir-rendements')->name('rendements');
    Route::get('/rendements/producteurs/{producteur}', EvolutionProducteur::class)->middleware('can:voir-rendements')->name('rendements.producteur');
    // Les groupes AVANT `/fiabilite/{producteur}` : sinon « groupes » serait pris pour un producteur.
    Route::get('/publications', GestionPublications::class)->middleware('can:gerer-publications')->name('publications');
    Route::get('/appareils', ListeAppareils::class)->middleware('can:gerer-appareils')->name('appareils');
    Route::get('/fiabilite/groupes', ListeGroupes::class)->middleware('can:voir-fiabilite')->name('fiabilite.groupes');
    Route::get('/fiabilite/groupes/{groupe}', SituationGroupe::class)->middleware('can:voir-fiabilite')->name('fiabilite.groupe');
    Route::get('/fiabilite', ListeFiabilite::class)->middleware('can:voir-fiabilite')->name('fiabilite');
    Route::get('/fiabilite/{producteur}', FicheFiabilite::class)->middleware('can:voir-fiabilite')->name('fiabilite.fiche');
    Route::get('/rapport-campagne', PointEtape::class)->middleware('can:voir-rapport-campagne')->name('rapport-campagne');
    Route::post('/rapport-campagne/point-etape', [RapportCampagneController::class, 'pointEtape'])->middleware('can:voir-rapport-campagne')->name('rapport-campagne.point-etape');
    Route::get('/resultat', ResultatDeCampagne::class)->middleware('can:voir-resultat-campagne')->name('resultat');
    Route::get('/mon-investissement', PortailInvestisseur::class)->middleware('can:voir-portail-investisseur')->name('mon-investissement');
    Route::get('/visites', ListeVisites::class)->middleware('can:voir-visites')->name('visites');
    // IA (phase 3) : diagnostics des photos et référentiel des traitements (agronome).
    Route::get('/ia/diagnostics', ListeDiagnostics::class)->middleware('can:voir-ia')->name('ia.diagnostics');
    Route::get('/ia/referentiel', ReferentielTraitements::class)->middleware('can:voir-ia')->name('ia.referentiel');
    // Avis de chacun (tout utilisateur connecté, les siens seulement).
    Route::get('/notifications', ListeNotifications::class)->name('notifications');
    // Tickets 58 mm (imprimante thermique) ; droits vérifiés dans le contrôleur.
    Route::get('/tickets/achats/{achat}', [TicketController::class, 'achat'])->name('tickets.achat');
    Route::get('/tickets/prets/{pret}/{type}/{id}', [TicketController::class, 'remise'])
        ->whereIn('type', ['argent', 'intrants'])->whereNumber('id')->name('tickets.remise');
    Route::get('/budget', SuiviBudget::class)->middleware('can:voir-budget')->name('budget');

    Route::prefix('prets')->name('prets')->group(function () {
        Route::get('/', ListePrets::class)->middleware('can:voir-prets')->name('');
        Route::get('/nouveau', FormulairePret::class)->middleware('can:saisir-prets')->name('.nouveau');
        Route::get('/{pret}', FichePret::class)->middleware('can:voir-prets')->name('.fiche');
        Route::get('/{pret}/accord', [PretController::class, 'accord'])->middleware('can:voir-prets')->name('.accord');
        Route::get('/recus/{decaissement}', [PretController::class, 'recu'])->middleware('can:voir-prets')->name('.recu');
        Route::get('/{pret}/recu/{type}/{id}', [PretController::class, 'recuPdf'])
            ->middleware('can:voir-prets')->whereIn('type', ['argent', 'intrants'])->whereNumber('id')->name('.recu-pdf');
    });

    Route::get('/intrants', StockIntrant::class)->middleware('can:gerer-intrants')->name('intrants');

    Route::prefix('depenses')->name('depenses')->group(function () {
        Route::get('/', ListeDepenses::class)->name('');
        Route::get('/nouvelle', FormulaireDepense::class)->middleware('can:saisir-depenses')->name('.nouvelle');
        Route::get('/{depense}/justificatif', [DepenseController::class, 'justificatif'])->name('.justificatif');
    });

    Route::post('/deconnexion', DeconnexionController::class)->name('logout');
});
