<?php

declare(strict_types=1);

/**
 * Demandes d'acces en libre-service.
 *
 * Le demandeur recoit, a la soumission, un jeton qu'il est seul a detenir :
 * le serveur n'en garde que le hachage et une cle publique derivee. A la
 * validation, la cle API de test est scellee (libsodium, `crypto_box_seal`)
 * avec cette cle publique ; seul le jeton permet de la rouvrir. Une fuite de
 * la base ne livre donc aucune cle en attente, et la cle disparait de la base
 * des sa premiere lecture.
 */
final class AccessRequests
{
    /** @return array{token: string, tokenHash: string, publicKey: string} */
    public static function newToken(): array
    {
        $token = bin2hex(random_bytes(24));
        return [
            'token'     => $token,
            'tokenHash' => hash('sha256', $token),
            'publicKey' => bin2hex(sodium_crypto_box_publickey(self::keypair($token))),
        ];
    }

    public static function tokenMatches(array $request, string $token): bool
    {
        return $token !== '' && hash_equals((string) $request['tokenHash'], hash('sha256', $token));
    }

    public static function seal(string $publicKeyHex, string $secret): string
    {
        return base64_encode(sodium_crypto_box_seal($secret, (string) hex2bin($publicKeyHex)));
    }

    public static function open(string $cipher, string $token): ?string
    {
        $plain = sodium_crypto_box_seal_open((string) base64_decode($cipher, true), self::keypair($token));
        return $plain === false ? null : $plain;
    }

    private static function keypair(string $token): string
    {
        return sodium_crypto_box_seed_keypair(hash('sha256', "teeeotp-access:$token", true));
    }

    /** Ligne publique : ce que le demandeur et le back-office voient. */
    public static function present(array $r, bool $admin): array
    {
        $out = [
            'id'           => $r['id'],
            'projectName'  => $r['projectName'],
            'status'       => $r['status'],
            'decisionNote' => $r['decisionNote'],
            'createdAt'    => Db::iso($r['createdAt']),
            'decidedAt'    => Db::iso($r['decidedAt']),
        ];
        if ($admin) {
            $out += [
                'contactName'    => $r['contactName'],
                'email'          => $r['email'],
                'phone'          => $r['phone'],
                'company'        => $r['company'],
                'useCase'        => $r['useCase'],
                'ip'             => $r['ip'],
                'project'        => $r['slug'] ?? null,
                'keyRetrievedAt' => Db::iso($r['keyRetrievedAt']),
                'keyPending'     => $r['keyCipher'] !== null,
            ];
        }
        return $out;
    }
}
