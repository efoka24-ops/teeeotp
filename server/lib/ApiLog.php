<?php

declare(strict_types=1);

/**
 * Journal des appels API.
 *
 * Une ligne par requete, ecrite APRES la reponse (fonction d'arret) : qui a
 * appele (projet, telephone, admin), quoi, avec quel resultat et en combien de
 * temps. C'est ce qui permet de suivre une integration depuis le back-office :
 * une cle mal recopiee ou un numero mal formate y apparaissent immediatement.
 *
 * Jamais de secret ni de contenu de message : seulement le prefixe public des
 * cles, et le code d'erreur renvoye.
 *
 * Bruit evite : les interrogations a vide des telephones (toutes les 3 s) et
 * les lectures du back-office ne sont pas journalisees.
 */
final class ApiLog
{
    private const RETENTION_DAYS = 30;

    private static float $start = 0.0;
    private static string $actor = 'anonymous';
    private static ?string $tenantId = null;
    private static ?string $deviceId = null;
    private static ?string $keyPrefix = null;
    private static bool $skip = false;
    private static bool $test = false;

    public static function start(): void
    {
        self::$start = microtime(true);
        register_shutdown_function([self::class, 'flush']);
    }

    public static function keyPrefix(string $prefix): void
    {
        self::$keyPrefix = mb_substr($prefix, 0, 16);
    }

    public static function tenant(string $id, bool $test = false): void
    {
        self::$test  = $test;
        self::$actor = 'project';
        self::$tenantId = $id;
    }

    public static function device(string $id): void
    {
        self::$actor = 'device';
        self::$deviceId = $id;
    }

    public static function admin(): void
    {
        self::$actor = 'admin';
    }

    /** La requete en cours ne merite pas de ligne (ex. interrogation a vide). */
    public static function skip(): void
    {
        self::$skip = true;
    }

    public static function flush(): void
    {
        $method = Http::method();
        $status = (int) (http_response_code() ?: 200);

        if (self::$skip || $method === 'OPTIONS' || !Db::available()) {
            return;
        }
        // Le back-office interroge en boucle : seules ses ecritures et ses
        // erreurs interessent l'audit.
        if (self::$actor === 'admin' && $method === 'GET' && $status < 400) {
            return;
        }

        try {
            Db::insert('otp_api_logs', [
                'actor'      => self::$actor,
                'tenantId'   => self::$tenantId,
                'deviceId'   => self::$deviceId,
                'keyPrefix'  => self::$keyPrefix,
                'method'     => $method,
                'path'       => self::path(),
                'status'     => $status,
                'errorCode'  => Http::$lastErrorCode,
                'durationMs' => (int) round((microtime(true) - self::$start) * 1000),
                'ip'         => mb_substr(Http::clientIp(), 0, 45),
                'test'       => self::$test ? 1 : 0,
            ]);

            if (random_int(1, 500) === 1) {
                Db::run('DELETE FROM `otp_api_logs` WHERE `createdAt` < ?', [Db::now(-self::RETENTION_DAYS * 86400)]);
            }
        } catch (Throwable $e) {
            // Le journal ne doit jamais casser une reponse deja partie.
            error_log('[apilog] ' . $e->getMessage());
        }
    }

    /** Chemin demande, identifiants remplaces par {id} pour pouvoir regrouper. */
    private static function path(): string
    {
        $path = '/' . trim((string) ($_GET['__path'] ?? ''), '/');
        $path = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{id}', $path) ?? $path;
        return mb_substr($path, 0, 120);
    }
}
