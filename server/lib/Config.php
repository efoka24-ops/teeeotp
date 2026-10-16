<?php

declare(strict_types=1);

/**
 * Configuration, lue depuis un fichier .env.
 *
 * Le service est servi depuis un sous-dossier de trugroup.cm
 * (`/home/<compte>/public_html/teeeotp`). Deux emplacements sont tentes :
 *
 *  1. `/home/<compte>/teeeotp.env` — DEUX niveaux au-dessus du service, hors
 *     de `public_html` : aucune URL ne peut l'atteindre. Un seul niveau
 *     au-dessus serait `public_html` lui-meme, donc servi par le site.
 *  2. `<service>/.env` — repli protege uniquement par le .htaccess.
 */
final class Config
{
    /** @var array<string, string>|null */
    private static ?array $env = null;

    public static function boot(string $serviceDir): void
    {
        self::load(dirname($serviceDir, 2) . '/teeeotp.env', $serviceDir . '/.env');
    }

    public static function load(string ...$candidates): void
    {
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                self::$env = self::parse($path);
                return;
            }
        }

        error_log('[config] aucun fichier d environnement lisible : ' . implode(', ', $candidates));
        self::$env = [];
    }

    /** @return array<string, string> */
    private static function parse(string $path): array
    {
        $env = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $env[trim($key)] = $value;
        }
        return $env;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$env[$key] ?? null;
        if ($value === null || $value === '') {
            $fromEnv = getenv($key);
            $value = ($fromEnv === false || $fromEnv === '') ? null : $fromEnv;
        }
        return $value ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    /**
     * Valeur sans laquelle le service ne peut pas fonctionner correctement.
     * Un poivre OTP vide rendrait les codes devinables a partir de la base :
     * mieux vaut refuser de servir.
     */
    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            error_log("[config] variable obligatoire absente : $key");
            Http::fail('Service mal configure.', 500, 'MISCONFIGURED');
        }
        return $value;
    }

    public static function db(): array
    {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'port' => self::int('DB_PORT', 3306),
            'name' => self::get('DB_NAME', ''),
            'user' => self::get('DB_USER', ''),
            'pass' => self::get('DB_PASS', ''),
        ];
    }

    public static function publicUrl(): string
    {
        return rtrim((string) self::get('PUBLIC_URL', 'https://trugroup.cm/teeeotp'), '/');
    }
}
