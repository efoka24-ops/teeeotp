<?php

declare(strict_types=1);

/**
 * Trois identites distinctes, chacune avec son propre secret :
 *
 *  - un PROJET (KoliGo, ERP…)   : cle `sk_<prefixe>_<secret>` en X-Api-Key ;
 *  - un TELEPHONE passerelle    : jeton `dv_<prefixe>_<secret>` en Bearer ;
 *  - l'ADMINISTRATEUR            : ADMIN_API_KEY en X-Admin-Key.
 *
 * Le prefixe est stocke en clair pour retrouver la ligne ; le secret n'existe
 * en clair qu'une fois, a la creation. Seul un hachage sale est conserve.
 */
final class Auth
{
    private static ?array $tenant = null;

    /** @return array{plaintext: string, prefix: string, hash: string} */
    public static function generate(string $kind): array
    {
        $prefix    = bin2hex(random_bytes(6));
        $plaintext = "{$kind}_{$prefix}_" . bin2hex(random_bytes(24));

        return ['plaintext' => $plaintext, 'prefix' => $prefix, 'hash' => self::hash($plaintext)];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', Config::required('API_KEY_SALT') . ':' . $plaintext);
    }

    private static function prefixOf(string $plaintext, string $kind): ?string
    {
        $parts = explode('_', $plaintext);
        if (count($parts) !== 3 || $parts[0] !== $kind || $parts[1] === '') {
            return null;
        }
        return $parts[1];
    }

    /**
     * Exige une cle de projet valide.
     *
     * Le message est identique dans tous les cas d'echec : on ne revele ni si
     * le prefixe existe, ni si la cle est revoquee, ni si le projet est
     * desactive.
     */
    public static function requireTenant(): array
    {
        if (self::$tenant !== null) {
            return self::$tenant;
        }

        $presented = Http::header('X-Api-Key') ?? Http::bearer();
        if ($presented === null) {
            Http::fail('Cle API absente (en-tete X-Api-Key).', 401);
        }

        $prefix = self::prefixOf($presented, 'sk');
        $record = $prefix === null ? null : Db::one(
            'SELECT k.`id` AS keyId, k.`hash`, k.`revokedAt`,
                    t.`id`, t.`name`, t.`slug`, t.`senderLabel`, t.`dailyQuota`, t.`active`
             FROM `api_keys` k JOIN `tenants` t ON t.`id` = k.`tenantId`
             WHERE k.`prefix` = ?',
            [$prefix]
        );

        if (
            $record === null
            || $record['revokedAt'] !== null
            || (int) $record['active'] !== 1
            || !hash_equals((string) $record['hash'], self::hash($presented))
        ) {
            Http::fail('Cle API invalide.', 401);
        }

        try {
            Db::run('UPDATE `api_keys` SET `lastUsedAt` = ? WHERE `id` = ?', [Db::now(), $record['keyId']]);
        } catch (Throwable $e) {
            error_log('[auth] lastUsedAt non mis a jour : ' . $e->getMessage());
        }

        unset($record['hash'], $record['revokedAt'], $record['keyId'], $record['active']);
        $record['dailyQuota'] = (int) $record['dailyQuota'];
        return self::$tenant = $record;
    }

    /** Exige le jeton d'un telephone passerelle actif. */
    public static function requireDevice(): array
    {
        $presented = Http::bearer();
        $prefix    = $presented === null ? null : self::prefixOf($presented, 'dv');
        $device    = $prefix === null ? null : Db::one('SELECT * FROM `devices` WHERE `tokenPrefix` = ?', [$prefix]);

        if (
            $device === null
            || (int) $device['active'] !== 1
            || !hash_equals((string) $device['tokenHash'], self::hash((string) $presented))
        ) {
            Http::fail('Jeton de telephone invalide.', 401);
        }

        unset($device['tokenHash']);
        return $device;
    }

    /**
     * Exige la cle d'administration. Sans ADMIN_API_KEY configuree, les routes
     * d'administration sont fermees plutot qu'ouvertes.
     */
    public static function requireAdmin(): void
    {
        $expected = Config::get('ADMIN_API_KEY', '');
        if ($expected === null || $expected === '') {
            Http::fail("Administration desactivee : ADMIN_API_KEY n'est pas definie.", 503, 'ADMIN_KEY_NOT_CONFIGURED');
        }

        if (!hash_equals($expected, Http::header('X-Admin-Key') ?? '')) {
            Http::fail("Cle d'administration invalide.", 403);
        }
    }
}
