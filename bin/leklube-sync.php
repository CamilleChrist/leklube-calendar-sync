<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Toutes les dates du programme sont en heure de Paris : séances lues comme événements écrits.
date_default_timezone_set('Europe/Paris');

use LeKlube\Config;
use LeKlube\GoogleCalendarSync;
use LeKlube\GoogleAuth;
use LeKlube\LeKlubeClient;
use LeKlube\ReservationExtractor;
use LeKlube\SyncException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);
$listOnly = in_array('--list', $argv, true);
$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<TXT
    Usage : php bin/leklube-sync.php [options]

      (aucune option)  Synchronise les séances réservées vers Google Agenda
      --dry-run        Affiche les actions sans rien écrire dans l'agenda
      --list           Liste les séances détectées, sans toucher à Google
      --verbose, -v    Journalisation détaillée
      --help, -h       Affiche cette aide

    TXT;
    exit(0);
}

$config = Config::fromEnv($root);
// Erreurs sur stderr (sans les répéter sur stdout), le reste sur stdout.
$format = new LineFormatter("%datetime% [%level_name%] %channel%: %message%\n", 'Y-m-d H:i:s');
$logger = new Logger('leklube_sync', [
    new StreamHandler(STDERR, Level::Error, bubble: false)->setFormatter($format),
    new StreamHandler(STDOUT, $verbose ? Level::Debug : Level::Info)->setFormatter($format),
]);

try {
    // Le tableau de bord est la seule source de réservations.
    $props = new LeKlubeClient($config, $logger)->loginAndFetchDashboard();

    $reservations = new ReservationExtractor($logger)->extractUpcoming($props);

    if ($reservations === []) {
        $logger->warning(
            "Aucune séance réservée détectée. Si tu en as pourtant dans l'application, relance "
            . "`php bin/leklube-sync.php --list` pour afficher la liste détectée, ou vérifie le README."
        );
        exit(0);
    }

    $logger->info(sprintf('%d séance(s) réservée(s) à venir', count($reservations)));

    if ($listOnly) {
        foreach ($reservations as $reservation) {
            echo '  • ' . $reservation->describe() . PHP_EOL;
        }
        exit(0);
    }

    $config->validateGoogle();

    $service = new GoogleAuth($config, $logger)->getCalendarService();
    $stats = new GoogleCalendarSync($config, $logger)->sync($service, $reservations, $dryRun);

    $logger->info(sprintf(
        'Terminé%s : %d créé(s), %d mis à jour, %d inchangé(s), %d supprimé(s)',
        $dryRun ? ' (dry-run)' : '',
        $stats['created'],
        $stats['updated'],
        $stats['unchanged'],
        $stats['deleted'],
    ));
} catch (SyncException $exc) {
    $logger->error($exc->getMessage());
    exit(1);
}