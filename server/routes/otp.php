<?php

declare(strict_types=1);

/**
 * Routes appelees par les projets (KoliGo, ERP…), avec leur cle API.
 */

return static function (Router $r): void {

    // ─── Envoyer un code ─────────────────────────────────────────────────────
    $r->post('/v1/otp/send', static function (): void {
        $tenant = Auth::requireTenant();

        $phone = Phone::normalize(Http::input('phone'));
        if ($phone === null) {
            Http::fail('phone doit etre un mobile camerounais (6XXXXXXXX ou +2376XXXXXXXX).', 400, 'INVALID_PHONE');
        }

        $purpose = (string) Http::input('purpose', 'verification');
        if (preg_match('/^[a-z0-9_-]{1,30}$/', $purpose) !== 1) {
            Http::fail('purpose : 1 a 30 caracteres parmi a-z, 0-9, _ et - (ex. signup, login, reset).', 400);
        }

        $length = (int) Http::input('length', 6);
        if ($length < 4 || $length > 8) {
            Http::fail('length doit etre compris entre 4 et 8.', 400);
        }

        try {
            $result = Otp::send($tenant, $phone, $purpose, $length);
        } catch (QuotaExceeded $e) {
            Http::fail($e->getMessage(), 429, 'QUOTA_EXCEEDED');
        }

        Http::ok($result, 202);
    });

    // ─── Verifier un code ────────────────────────────────────────────────────
    $r->post('/v1/otp/verify', static function (): void {
        $tenant = Auth::requireTenant();

        $requestId = (string) Http::input('requestId', '');
        $code      = (string) Http::input('code', '');
        if ($requestId === '' || preg_match('/^\d{4,8}$/', $code) !== 1) {
            Http::fail('requestId et code (4 a 8 chiffres) sont requis.', 400);
        }

        Http::ok(Otp::verify($tenant, $requestId, $code));
    });

    // ─── Etat d'une demande ──────────────────────────────────────────────────
    $r->get('/v1/otp/{requestId}', static function (array $args): void {
        $tenant = Auth::requireTenant();

        $status = Otp::status($tenant, $args['requestId']);
        if ($status === null) {
            Http::fail('Demande introuvable.', 404);
        }
        Http::ok($status);
    });

    // ─── SMS libre (notifications : commande prete, rappel…) ─────────────────
    $r->post('/v1/sms', static function (): void {
        $tenant = Auth::requireTenant();

        $phone = Phone::normalize(Http::input('phone'));
        if ($phone === null) {
            Http::fail('phone doit etre un mobile camerounais (6XXXXXXXX ou +2376XXXXXXXX).', 400, 'INVALID_PHONE');
        }

        $message = trim((string) Http::input('message', ''));
        if ($message === '' || mb_strlen($message) > 480) {
            Http::fail('message requis, d au plus 480 caracteres.', 400);
        }

        // 24 h par defaut : une notification reste utile plus longtemps qu'un OTP.
        $ttl = (int) Http::input('ttlSeconds', 86400);
        $ttl = max(60, min($ttl, 7 * 86400));

        try {
            $sms = SmsQueue::dispatch($tenant, 'notification', $phone, $message, $ttl);
        } catch (QuotaExceeded $e) {
            Http::fail($e->getMessage(), 429, 'QUOTA_EXCEEDED');
        }

        $out = ['id' => $sms['id'], 'status' => $sms['simulated'] ? 'simulated' : 'queued'];
        if ($tenant['test']) {
            $out += ['test' => true, 'testSmsRemaining' => $sms['testRemaining']];
        }
        Http::ok($out, 202);
    });

    $r->get('/v1/sms/{id}', static function (array $args): void {
        $tenant = Auth::requireTenant();

        $sms = Db::one(
            'SELECT `id`, `kind`, `phone`, `status`, `attempts`, `error`, `createdAt`, `sentAt`, `deliveredAt`, `expiresAt`
             FROM `otp_sms_messages` WHERE `id` = ? AND `tenantId` = ?',
            [$args['id'], $tenant['id']]
        );
        if ($sms === null) {
            Http::fail('SMS introuvable.', 404);
        }

        Http::ok([
            'id'          => $sms['id'],
            'kind'        => $sms['kind'],
            'phone'       => Phone::mask($sms['phone']),
            'status'      => $sms['status'],
            'attempts'    => (int) $sms['attempts'],
            'error'       => $sms['error'],
            'createdAt'   => Db::iso($sms['createdAt']),
            'sentAt'      => Db::iso($sms['sentAt']),
            'deliveredAt' => Db::iso($sms['deliveredAt']),
            'expiresAt'   => Db::iso($sms['expiresAt']),
        ]);
    });

    // ─── Identite du projet appelant ─────────────────────────────────────────
    $r->get('/v1/me', static function (): void {
        $tenant = Auth::requireTenant();

        Http::ok([
            'name'        => $tenant['name'],
            'slug'        => $tenant['slug'],
            'senderLabel' => $tenant['senderLabel'],
            'dailyQuota'  => $tenant['dailyQuota'],
            'mode'        => $tenant['test'] ? 'test' : 'live',
            'testSms'     => ['limit' => $tenant['testSmsLimit'], 'used' => $tenant['testSmsUsed']],
            'usedToday'   => (int) Db::value(
                'SELECT COUNT(*) FROM `otp_sms_messages` WHERE `tenantId` = ? AND `createdAt` >= ?',
                [$tenant['id'], gmdate('Y-m-d 00:00:00')]
            ),
        ]);
    });
};
