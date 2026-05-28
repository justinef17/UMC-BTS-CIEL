<?php
 
// Point d'entrée unique de l'application symfony 
// Ce fichier est le seul accessible depuis internet.
// Le serveur web  redirige toutes les requêtes HTTP vers ce fichier, quelle que soit l'URL.

 
// Importe la classe Kernel depuis le namespace App
// Le Kernel est le cerveau de Symfony : il charge la configuration,
// enregistre les bundles, démarre le routeur et gère les requêtes HTTP
use App\Kernel;
 
// Charge l'autoloader de Composer (autoload_runtime.php)
// L'autoloader permet d'utiliser toutes les bibliothèques installées
// via composer (Doctrine DBAL, PhpSpreadsheet, Dompdf, Twig...)
// sans avoir à écrire require() manuellement pour chaque fichier.
// dirname(__DIR__) remonte d'un niveau par rapport à public/
// pour pointer vers le dossier vendor/ à la racine du projet.
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';
 
// Retourne une fonction anonyme (closure) qui sera exécutée
// automatiquement par le composant Runtime de Symfony.
// Le tableau $context contient les variables d'environnement
// lues depuis le fichier .env (APP_ENV, APP_DEBUG, DATABASE_URL...)
return function (array $context) {
 
    // Crée et retourne une nouvelle instance du Kernel Symfony
    // avec deux paramètres issus du fichier .env :
    //
    // $context['APP_ENV'] → environnement d'exécution :
    //   'dev'  = mode développement (messages d'erreur détaillés,
    //            barre de debug Symfony en bas de page, cache désactivé)
    //   'prod' = mode production (erreurs masquées, cache activé,
    //            performances optimisées pour le déploiement final)
    //
    // (bool) $context['APP_DEBUG'] → mode débogage :
    //   true  = affiche les exceptions complètes avec stack trace
    //           (comme les pages d'erreur rouges Symfony qu'on a vues)
    //   false = affiche une page d'erreur générique sans détails
    //           (à activer en production pour ne pas exposer le code)
    //
    // Le cast (bool) convertit la valeur string '1'/'0' du .env
    // en vrai booléen PHP true/false
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
 

// Le Kernel charge la configuration (config/)
// Le Kernel enregistre tous les bundles Symfony
// Le Routeur analyse l'URL reçue (/graphiques?periode=24h)
// Le bon contrôleur est appelé (GraphiquesController::index())
// Le contrôleur interroge la BDD via DBAL
// Twig génère la page HTML
// La réponse HTTP est renvoyée au navigateur
