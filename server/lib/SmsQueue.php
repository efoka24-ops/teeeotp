<?php

declare(strict_types=1);

/**
 * File des SMS a envoyer.
 *
 * Les projets y deposent des messages ; les telephones passerelles viennent
 * les reserver (`claim`), les envoient avec leur SIM, puis rendent compte
 * (`report`). Il n'y a pas de cron : l'expiration et la remise en file des
 * reservations abandonnees sont faites a chaque reservation.
 */
final class SmsQueue
{
    /** Au-dela, une reservation sans compte rendu est consideree abandonnee. */
    private const CLAIM_TIMEOUT_SECONDS = 120;
    private const MAX_ATTEMPTS = 3;

    /**
     * Verifie les plafonds puis met un SMS en file.
     *
     * @throws QuotaExceeded
     */
    public static function enqueue(array $tenant, string $kind, string $phone, string $body, int $ttlSeconds): string
    {
        $dayStart = gmdate('Y-m-d 00:00:00');

        $tenantToday = (int) Db::value(
            'SELECT COUNT(*) FROM `sms_messages` WHERE `tenantId` = ? AND `createdAt` >= ?',
            [$tenant['id'], $dayStart]
        );
        if ($tenantToday >= $tenant['dailyQuota']) {
            throw new QuotaExceeded("Quota journalier du projet atteint ({$tenant['dailyQuota']} SMS).");
        }

        $globalCap = Config::int('GLOBAL_DAILY_CAP', 1000);
        $allToday  = (int) Db::value('SELECT COUNT(*) FROM `sms_messages` WHERE `createdAt` >= ?', [$dayStart]);
        if ($allToday >= $globalCap) {
            error_log("[sms] plafond global atteint ($globalCap)");
            throw new QuotaExceeded('Capacite journaliere du service atteinte.');
        }

        return Db::insert('sms_messages', [
            'tenantId'  => $tenant['id'],
            'kind'      => $kind,
            'phone'     => $phone,
            'body'      => $body,
            // Un OTP attend un utilisateur devant son ecran : il passe avant
            // les notifications.
            'priority'  => $kind === 'otp' ? 1 : 5,
            'expiresAt' => Db::now($ttlSeconds),
        ]);
    }

    /**
     * Reserve jusqu'a $limit SMS pour un telephone.
     *
     * La reservation est un UPDATE atomique marque d'un jeton unique : deux
     * telephones qui interrogent en meme temps ne recoivent jamais le meme SMS.
     *
     * @return list<array{id: string, phone: string, body: string}>
     */
    public static function claim(array $device, int $limit): array
    {
        $now = Db::now();

        Db::run(
            "UPDATE `sms_messages` SET `status` = 'expired', `updatedAt` = ?
             WHERE `status` IN ('queued','sending') AND `expiresAt` < ?",
            [$now, $now]
        );

        // Telephone redemarre, reseau coupe… : le SMS reserve n'a jamais ete
        // confirme. On le remet en file tant qu'il reste des tentatives.
        $abandon = Db::now(-self::CLAIM_TIMEOUT_SECONDS);
        Db::run(
            "UPDATE `sms_messages`
             SET `status` = IF(`attempts` >= ?, 'failed', 'queued'),
                 `error` = IF(`attempts` >= ?, 'aucun compte rendu du telephone', `error`),
                 `claimToken` = NULL, `updatedAt` = ?
             WHERE `status` = 'sending' AND `claimedAt` < ?",
            [self::MAX_ATTEMPTS, self::MAX_ATTEMPTS, $now, $abandon]
        );

        $sentToday = (int) Db::value(
            "SELECT COUNT(*) FROM `sms_messages`
             WHERE `deviceId` = ? AND `status` IN ('sending','sent','delivered') AND `claimedAt` >= ?",
            [$device['id'], gmdate('Y-m-d 00:00:00')]
        );
        $limit = min($limit, max(0, (int) $device['dailyLimit'] - $sentToday));
        if ($limit === 0) {
            return [];
        }

        $token = bin2hex(random_bytes(16));
        Db::run(
            "UPDATE `sms_messages`
             SET `status` = 'sending', `claimToken` = ?, `deviceId` = ?, `claimedAt` = ?,
                 `attempts` = `attempts` + 1, `updatedAt` = ?
             WHERE `status` = 'queued' AND `expiresAt` > ?
             ORDER BY `priority`, `createdAt`
             LIMIT $limit",
            [$token, $device['id'], $now, $now, $now]
        );

        return Db::all(
            'SELECT `id`, `phone`, `body` FROM `sms_messages` WHERE `claimToken` = ? ORDER BY `priority`, `createdAt`',
            [$token]
        );
    }

    /**
     * Compte rendu d'un telephone pour un SMS qu'il a reserve.
     *
     * Les statuts n'avancent que dans un sens : un `sent` qui arriverait apres
     * un `delivered` (rapports desordonnes) est ignore.
     */
    public static function report(array $device, string $smsId, string $status, ?string $error): bool
    {
        $sms = Db::one('SELECT `status` FROM `sms_messages` WHERE `id` = ? AND `deviceId` = ?', [$smsId, $device['id']]);
        if ($sms === null) {
            return false;
        }

        $rang = ['queued' => 0, 'sending' => 1, 'failed' => 2, 'expired' => 2, 'sent' => 3, 'delivered' => 4];
        if ($rang[$status] <= $rang[$sms['status']] && $sms['status'] !== 'sending') {
            return true;
        }

        $now  = Db::now();
        $data = ['status' => $status, 'claimToken' => null];
        if ($status === 'sent') {
            $data['sentAt'] = $now;
        } elseif ($status === 'delivered') {
            $data['deliveredAt'] = $now;
            $data['sentAt'] = Db::value('SELECT `sentAt` FROM `sms_messages` WHERE `id` = ?', [$smsId]) ?? $now;
        } else {
            $data['error'] = mb_substr((string) ($error ?? 'echec d envoi'), 0, 255);
        }
        Db::update('sms_messages', $smsId, $data);

        return true;
    }
}

final class QuotaExceeded extends RuntimeException
{
}
