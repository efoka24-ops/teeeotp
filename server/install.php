<?php

declare(strict_types=1);

/**
 * Installateur de schema, a usage unique.
 *
 *   POST /teeeotp/install.php?token=<INSTALL_TOKEN>
 *
 * A supprimer du serveur juste apres usage, avec schema.sql. Refuse de
 * s'executer si les tables existent deja : ce script ne detruit jamais rien.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Id.php';
require __DIR__ . '/lib/Db.php';

Config::boot(__DIR__);
date_default_timezone_set('UTC');

if (Http::method() !== 'POST') {
    Http::fail('Utilisez POST.', 405, 'METHOD_NOT_ALLOWED');
}

$attendu = Config::get('INSTALL_TOKEN', '');
if ($attendu === null || $attendu === '' || !hash_equals($attendu, (string) ($_GET['token'] ?? ''))) {
    Http::fail('Non autorise.', 403);
}

$pdo        = Db::pdo();
$attendues  = ['tenants', 'api_keys', 'devices', 'otp_requests', 'sms_messages'];
$existantes = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

if (array_intersect($attendues, $existantes) !== []) {
    Http::ok([
        'status'  => 'already-installed',
        'tables'  => $existantes,
        'message' => 'Les tables existent deja. Aucune action effectuee.',
    ]);
}

$sqlPath = __DIR__ . '/schema.sql';
if (!is_readable($sqlPath)) {
    Http::fail('schema.sql introuvable a cote de l installateur.', 500);
}

// Decoupage sur « ; » en fin de ligne ; les commentaires sont retires ligne a
// ligne a l'interieur de chaque instruction.
$instructions = [];
foreach (preg_split('/;\s*\R/', (string) file_get_contents($sqlPath)) ?: [] as $bloc) {
    $lignes = array_filter(
        array_map('trim', preg_split('/\R/', $bloc) ?: []),
        static fn (string $l): bool => $l !== '' && !str_starts_with($l, '--')
    );
    $instruction = trim(implode("\n", $lignes));
    if ($instruction !== '') {
        $instructions[] = $instruction;
    }
}

$erreurs = [];
foreach ($instructions as $instruction) {
    try {
        $pdo->exec($instruction);
    } catch (PDOException $e) {
        $erreurs[] = ['instruction' => substr($instruction, 0, 120), 'message' => $e->getMessage()];
    }
}

$manquantes = array_values(array_diff($attendues, $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));

Http::ok([
    'status'     => $manquantes === [] ? 'installed' : 'incomplete',
    'manquantes' => $manquantes,
    'erreurs'    => $erreurs,
    'suite'      => $manquantes === []
        ? 'Supprimez install.php et schema.sql du serveur, puis creez un projet (POST /v1/projects) et un telephone (POST /v1/devices).'
        : 'Corrigez les erreurs ci-dessus avant de poursuivre.',
], $manquantes === [] ? 200 : 500);
