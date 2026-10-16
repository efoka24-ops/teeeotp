<?php

declare(strict_types=1);

/**
 * Codes a usage unique.
 *
 * Le code est genere ICI et verifie ICI : le projet appelant ne le voit
 * jamais. Il ne connait que l'identifiant de la demande et la reponse
 * « valide / invalide ». Une fuite de la base d'un projet ne donne donc aucun
 * code, et une fuite de la base teeeotp n'en donne pas davantage : seul un
 * HMAC poivre (OTP_PEPPER, hors base) est stocke.
 */
final class Otp
{
    /** @return array{requestId: string, expiresIn: int, length: int} */
    public static function send(array $tenant, string $phone, string $purpose, int $length): array
    {
        $ttl = Config::int('OTP_TTL_SECONDS', 300);

        // Anti-abus par numero : sans ce frein, n'importe qui pourrait vider le
        // forfait en demandant des codes pour un meme numero en boucle.
        $window = Config::int('OTP_RATE_WINDOW_SECONDS', 900);
        $max    = Config::int('OTP_RATE_MAX_PER_PHONE', 3);
        $recent = (int) Db::value(
            'SELECT COUNT(*) FROM `otp_requests` WHERE `phone` = ? AND `createdAt` >= ?',
            [$phone, Db::now(-$window)]
        );
        if ($recent >= $max) {
            Http::fail(
                "Trop de codes demandes pour ce numero. Reessayez dans quelques minutes.",
                429,
                null,
                ['retryAfter' => $window]
            );
        }

        $code      = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $requestId = Id::uuid();
        $minutes   = (int) ceil($ttl / 60);

        // Le code en tete du message : Android et iOS le proposent alors en
        // remplissage automatique. Pas d'accent : en GSM 7 bits un SMS tient
        // en 160 caracteres, contre 70 en UCS-2.
        $body = sprintf(
            '%s est votre code %s. Valide %d min. Ne le partagez avec personne.',
            $code,
            self::ascii($tenant['senderLabel']),
            $minutes
        );

        Db::transaction(static function () use ($tenant, $phone, $purpose, $requestId, $code, $ttl, $body): void {
            $smsId = SmsQueue::enqueue($tenant, 'otp', $phone, $body, $ttl);
            Db::insert('otp_requests', [
                'id'           => $requestId,
                'tenantId'     => $tenant['id'],
                'phone'        => $phone,
                'purpose'      => $purpose,
                'codeHash'     => self::hash($requestId, $code),
                'attemptsLeft' => Config::int('OTP_MAX_ATTEMPTS', 5),
                'smsId'        => $smsId,
                'expiresAt'    => Db::now($ttl),
            ]);
        });

        return ['requestId' => $requestId, 'expiresIn' => $ttl, 'length' => $length];
    }

    /**
     * Verifie un code. Chaque mauvaise saisie consomme une tentative ; a zero,
     * la demande est bloquee et un nouveau code doit etre demande.
     *
     * @return array<string, mixed>
     */
    public static function verify(array $tenant, string $requestId, string $code): array
    {
        return Db::transaction(static function () use ($tenant, $requestId, $code): array {
            // FOR UPDATE : deux verifications simultanees ne peuvent pas
            // consommer la meme tentative ni valider deux fois.
            $otp = Db::one(
                'SELECT * FROM `otp_requests` WHERE `id` = ? AND `tenantId` = ? FOR UPDATE',
                [$requestId, $tenant['id']]
            );

            if ($otp === null) {
                return ['valid' => false, 'reason' => 'not_found'];
            }
            if ($otp['status'] !== 'pending') {
                return ['valid' => false, 'reason' => $otp['status'] === 'verified' ? 'already_used' : $otp['status']];
            }
            if ($otp['expiresAt'] < Db::now()) {
                Db::update('otp_requests', $otp['id'], ['status' => 'expired']);
                return ['valid' => false, 'reason' => 'expired'];
            }

            if (!hash_equals((string) $otp['codeHash'], self::hash($otp['id'], $code))) {
                $left = (int) $otp['attemptsLeft'] - 1;
                Db::update('otp_requests', $otp['id'], [
                    'attemptsLeft' => $left,
                    'status'       => $left <= 0 ? 'blocked' : 'pending',
                ]);
                return ['valid' => false, 'reason' => $left <= 0 ? 'blocked' : 'invalid_code', 'attemptsLeft' => $left];
            }

            Db::update('otp_requests', $otp['id'], ['status' => 'verified', 'verifiedAt' => Db::now()]);
            return ['valid' => true, 'phone' => $otp['phone'], 'purpose' => $otp['purpose']];
        });
    }

    /** Etat d'une demande, avec celui du SMS associe. */
    public static function status(array $tenant, string $requestId): ?array
    {
        $row = Db::one(
            'SELECT o.`id`, o.`phone`, o.`purpose`, o.`status`, o.`attemptsLeft`, o.`expiresAt`, o.`verifiedAt`, o.`createdAt`,
                    s.`status` AS smsStatus, s.`sentAt`, s.`deliveredAt`, s.`error` AS smsError
             FROM `otp_requests` o LEFT JOIN `sms_messages` s ON s.`id` = o.`smsId`
             WHERE o.`id` = ? AND o.`tenantId` = ?',
            [$requestId, $tenant['id']]
        );
        if ($row === null) {
            return null;
        }

        $status = $row['status'] === 'pending' && $row['expiresAt'] < Db::now() ? 'expired' : $row['status'];

        return [
            'requestId'    => $row['id'],
            'phone'        => Phone::mask($row['phone']),
            'purpose'      => $row['purpose'],
            'status'       => $status,
            'attemptsLeft' => (int) $row['attemptsLeft'],
            'expiresAt'    => Db::iso($row['expiresAt']),
            'verifiedAt'   => Db::iso($row['verifiedAt']),
            'sms'          => [
                'status'      => $row['smsStatus'],
                'sentAt'      => Db::iso($row['sentAt']),
                'deliveredAt' => Db::iso($row['deliveredAt']),
                'error'       => $row['smsError'],
            ],
        ];
    }

    private static function hash(string $requestId, string $code): string
    {
        return hash_hmac('sha256', "$requestId:$code", Config::required('OTP_PEPPER'));
    }

    public static function ascii(string $text): string
    {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return trim(preg_replace('/[^\x20-\x7E]/', '', $converted === false ? $text : $converted) ?? '');
    }
}
