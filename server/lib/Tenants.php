<?php

declare(strict_types=1);

/**
 * Creation des projets et emission de leurs cles. Partage par
 * l'administration (POST /v1/projects) et la validation des demandes d'acces.
 */
final class Tenants
{
    public static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug) === 1;
    }

    /** « Ecole Les Lauriers » -> « ecole-les-lauriers », rendu unique par un suffixe. */
    public static function freeSlug(string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', Otp::ascii($name)) ?? '', '-'));
        $base = substr($base !== '' ? $base : 'projet', 0, 44);
        if (strlen($base) < 3) {
            $base = str_pad($base, 3, '0');
        }

        $slug = $base;
        for ($i = 2; Db::one('SELECT `id` FROM `otp_tenants` WHERE `slug` = ?', [$slug]) !== null; $i++) {
            $slug = "$base-$i";
        }
        return $slug;
    }

    /**
     * @param array{name: string, slug: string, senderLabel: string, dailyQuota: int, testSmsLimit: int,
     *              origin?: string, contactName?: ?string, contactEmail?: ?string, contactPhone?: ?string} $data
     */
    public static function create(array $data): string
    {
        return Db::insert('otp_tenants', [
            'name'         => $data['name'],
            'slug'         => $data['slug'],
            'senderLabel'  => $data['senderLabel'],
            'dailyQuota'   => $data['dailyQuota'],
            'testSmsLimit' => $data['testSmsLimit'],
            'origin'       => $data['origin'] ?? 'admin',
            'contactName'  => $data['contactName'] ?? null,
            'contactEmail' => $data['contactEmail'] ?? null,
            'contactPhone' => $data['contactPhone'] ?? null,
        ]);
    }

    /** Emet une cle et renvoie sa valeur en clair : seul moment ou elle existe. */
    public static function issueKey(string $tenantId, string $env, string $label): string
    {
        $cle = Auth::generateApiKey($env);
        Db::insert('otp_api_keys', [
            'tenantId' => $tenantId,
            'prefix'   => $cle['prefix'],
            'hash'     => $cle['hash'],
            'env'      => $env,
            'label'    => $label,
        ]);
        return $cle['plaintext'];
    }
}
