<?php

declare(strict_types=1);

/**
 * Entree et sortie HTTP.
 *
 * Meme format qu'apisungku : la reponse de succes est l'objet lui-meme, sans
 * enveloppe, et l'erreur est toujours `{ "error": { "code", "message" } }`.
 * Un projet deja integre a apisungku traite donc les erreurs des deux
 * services avec le meme code.
 */
final class Http
{
    private static ?string $raw = null;
    private static ?array $body = null;

    /** Corps brut, lu une seule fois : `php://input` n'est pas rejouable. */
    public static function raw(): string
    {
        if (self::$raw === null) {
            $raw = file_get_contents('php://input');
            self::$raw = $raw === false ? '' : $raw;
        }
        return self::$raw;
    }

    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $decoded = self::raw() === '' ? [] : json_decode(self::raw(), true);
        return self::$body = is_array($decoded) ? $decoded : [];
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $body = self::body();
        return array_key_exists($key, $body) ? $body[$key] : $default;
    }

    public static function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data, int $status = 200): never
    {
        self::json($data, $status);
    }

    /** Code de la derniere erreur renvoyee, repris par le journal des appels. */
    public static ?string $lastErrorCode = null;

    public static function fail(string $message, int $status = 400, ?string $code = null, array $extra = []): never
    {
        $error = ['code' => $code ?? self::codeFor($status), 'message' => $message] + $extra;
        self::$lastErrorCode = $error['code'];
        self::json(['error' => $error], $status);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'INVALID_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            410 => 'GONE',
            429 => 'TOO_MANY_REQUESTS',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'INTERNAL_ERROR',
        };
    }

    /** En-tete de requete, insensible a la casse. */
    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;

        // Certaines configurations Apache ne transmettent pas Authorization :
        // le .htaccess le recopie alors dans REDIRECT_HTTP_AUTHORIZATION.
        if ($value === null && $key === 'HTTP_AUTHORIZATION') {
            $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** Jeton presente en `Authorization: Bearer <jeton>`. */
    public static function bearer(): ?string
    {
        $auth = self::header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
