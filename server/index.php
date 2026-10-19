<?php

declare(strict_types=1);

/**
 * teeeotp — controleur frontal.
 *
 * Service OTP et SMS mutualise : les projets (KoliGo, ERP…) deposent des
 * codes et des messages, des telephones Android equipes d'un forfait SMS les
 * envoient. Voir README.md a la racine du depot.
 *
 * Deploiement : ce repertoire est servi en sous-dossier de trugroup.cm
 * (`/home/<compte>/public_html/teeeotp`), donc sous
 * `https://trugroup.cm/teeeotp/v1/...`.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/lib/Http.php';
require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Id.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/Phone.php';
require __DIR__ . '/lib/SmsQueue.php';
require __DIR__ . '/lib/Otp.php';
require __DIR__ . '/lib/ApiLog.php';
require __DIR__ . '/lib/Migrations.php';
require __DIR__ . '/lib/Router.php';

Config::boot(__DIR__);
date_default_timezone_set('UTC');
ApiLog::start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header_remove('X-Powered-By');

// Les appels legitimes viennent de serveurs et des telephones passerelles,
// jamais d'un navigateur : aucune cle n'a sa place dans du code client web.
// Pas d'en-tete CORS, donc.
if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = '/' . trim((string) ($_GET['__path'] ?? ''), '/');

$router = new Router();
foreach (['otp', 'gateway', 'admin', 'backoffice'] as $groupe) {
    (require __DIR__ . "/routes/$groupe.php")($router);
}

$router->get('/v1/health', static function (): void {
    $database = 'up';
    try {
        Db::one('SELECT 1 AS ok');
    } catch (Throwable) {
        $database = 'down';
    }

    Http::ok([
        'status'    => $database === 'up' ? 'ok' : 'degraded',
        'database'  => $database,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
});

$router->get('/', static function (): void {
    Http::ok([
        'service' => 'teeeotp',
        'health'  => '/v1/health',
    ]);
});

try {
    $router->dispatch(Http::method(), $path);
} catch (Throwable $e) {
    error_log('[teeeotp] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::fail('Une erreur inattendue est survenue.', 500, 'INTERNAL_ERROR');
}
