<?php

declare(strict_types=1);

/**
 * API du back-office (admin/index.html). Toutes les routes exigent X-Admin-Key.
 *
 * Contrairement aux reponses faites aux projets, les numeros sont affiches en
 * entier : le back-office sert au support, qui doit retrouver un utilisateur.
 */

return static function (Router $r): void {

    $iso = static fn (?string $v): ?string => Db::iso($v);

    $deviceRows = static function (): array {
        $dayStart = gmdate('Y-m-d 00:00:00');
        $offline  = Config::int('DEVICE_OFFLINE_AFTER_SECONDS', 60);

        return array_map(static function (array $d) use ($dayStart, $offline): array {
            $seen = $d['lastSeenAt'] === null ? null : strtotime($d['lastSeenAt'] . ' UTC');
            return [
                'id'         => $d['id'],
                'name'       => $d['name'],
                'active'     => (int) $d['active'] === 1,
                'online'     => $seen !== null && time() - $seen <= $offline,
                'lastSeenAt' => Db::iso($d['lastSeenAt']),
                'lastIp'     => $d['lastIp'],
                'battery'    => $d['battery'] === null ? null : (int) $d['battery'],
                'charging'   => $d['charging'] === null ? null : (int) $d['charging'] === 1,
                'appVersion' => $d['appVersion'],
                'dailyLimit' => (int) $d['dailyLimit'],
                'sentToday'  => (int) Db::value(
                    "SELECT COUNT(*) FROM `otp_sms_messages` WHERE `deviceId` = ? AND `status` IN ('sent','delivered') AND `sentAt` >= ?",
                    [$d['id'], $dayStart]
                ),
                'failedToday' => (int) Db::value(
                    "SELECT COUNT(*) FROM `otp_sms_messages` WHERE `deviceId` = ? AND `status` = 'failed' AND `updatedAt` >= ?",
                    [$d['id'], $dayStart]
                ),
            ];
        }, Db::all('SELECT * FROM `otp_devices` ORDER BY `createdAt`'));
    };

    $logRow = static fn (array $l): array => [
        'id'         => $l['id'],
        'at'         => Db::iso($l['createdAt']),
        'actor'      => $l['actor'],
        'project'    => $l['slug'] ?? null,
        'device'     => $l['deviceName'] ?? null,
        'keyPrefix'  => $l['keyPrefix'],
        'method'     => $l['method'],
        'path'       => $l['path'],
        'status'     => (int) $l['status'],
        'errorCode'  => $l['errorCode'],
        'durationMs' => (int) $l['durationMs'],
        'ip'         => $l['ip'],
        'test'       => (int) ($l['test'] ?? 0) === 1,
    ];

    $smsRow = static fn (array $s): array => [
        'id'          => $s['id'],
        'project'     => $s['slug'],
        'kind'        => $s['kind'],
        'phone'       => $s['phone'],
        'status'      => $s['status'],
        'attempts'    => (int) $s['attempts'],
        'device'      => $s['deviceName'],
        'error'       => $s['error'],
        'createdAt'   => Db::iso($s['createdAt']),
        'sentAt'      => Db::iso($s['sentAt']),
        'deliveredAt' => Db::iso($s['deliveredAt']),
        'expiresAt'   => Db::iso($s['expiresAt']),
        // Le texte d'un OTP contient le code : jamais affiche.
        'body'        => $s['kind'] === 'otp' ? null : $s['body'],
        'test'        => (int) ($s['test'] ?? 0) === 1,
    ];

    $tenantBySlug = static function (string $slug): array {
        $t = Db::one('SELECT * FROM `otp_tenants` WHERE `slug` = ?', [$slug]);
        if ($t === null) {
            Http::fail("Projet '$slug' introuvable.", 404);
        }
        return $t;
    };

    // ─── Mise a jour du schema ───────────────────────────────────────────────
    $r->post('/v1/admin/migrate', static function (): void {
        Auth::requireAdmin();
        $result = Migrations::run();
        Http::ok($result, $result['errors'] === [] ? 200 : 500);
    });

    // ─── Vue d'ensemble ──────────────────────────────────────────────────────
    $r->get('/v1/admin/overview', static function () use ($deviceRows, $logRow, $iso): void {
        Auth::requireAdmin();

        $dayStart = gmdate('Y-m-d 00:00:00');
        $since24h = Db::now(-86400);

        $smsToday = array_fill_keys(['queued', 'sending', 'sent', 'delivered', 'failed', 'expired'], 0);
        foreach (Db::all('SELECT `status`, COUNT(*) AS n FROM `otp_sms_messages` WHERE `createdAt` >= ? GROUP BY `status`', [$dayStart]) as $row) {
            $smsToday[$row['status']] = (int) $row['n'];
        }

        $api = Db::one(
            'SELECT COUNT(*) AS calls, SUM(`status` >= 400) AS errors, ROUND(AVG(`durationMs`)) AS avgMs
             FROM `otp_api_logs` WHERE `createdAt` >= ?',
            [$since24h]
        ) ?? [];

        // Volume des 14 derniers jours, par jour et par statut.
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', time() - $i * 86400)] = ['ok' => 0, 'failed' => 0, 'pending' => 0];
        }
        $rows = Db::all(
            'SELECT DATE(`createdAt`) AS d, `status`, COUNT(*) AS n FROM `otp_sms_messages`
             WHERE `createdAt` >= ? GROUP BY d, `status`',
            [gmdate('Y-m-d 00:00:00', time() - 13 * 86400)]
        );
        foreach ($rows as $row) {
            $bucket = match ($row['status']) {
                'sent', 'delivered' => 'ok',
                'failed', 'expired' => 'failed',
                default             => 'pending',
            };
            if (isset($series[$row['d']])) {
                $series[$row['d']][$bucket] += (int) $row['n'];
            }
        }

        $projects = array_map(static function (array $t) use ($dayStart, $since24h, $iso): array {
            $lastError = Db::one(
                'SELECT `createdAt`, `path`, `status`, `errorCode` FROM `otp_api_logs`
                 WHERE `tenantId` = ? AND `status` >= 400 ORDER BY `createdAt` DESC LIMIT 1',
                [$t['id']]
            );
            return [
                'slug'       => $t['slug'],
                'name'       => $t['name'],
                'active'     => (int) $t['active'] === 1,
                'dailyQuota' => (int) $t['dailyQuota'],
                'origin'     => $t['origin'] ?? 'admin',
                'testSms'    => ['limit' => (int) ($t['testSmsLimit'] ?? 0), 'used' => (int) ($t['testSmsUsed'] ?? 0)],
                'liveKeys'   => (int) Db::value("SELECT COUNT(*) FROM `otp_api_keys` WHERE `tenantId` = ? AND `env` = 'live' AND `revokedAt` IS NULL", [$t['id']]),
                'usedToday'  => (int) Db::value('SELECT COUNT(*) FROM `otp_sms_messages` WHERE `tenantId` = ? AND `createdAt` >= ?', [$t['id'], $dayStart]),
                'calls24h'   => (int) Db::value('SELECT COUNT(*) FROM `otp_api_logs` WHERE `tenantId` = ? AND `createdAt` >= ?', [$t['id'], $since24h]),
                'errors24h'  => (int) Db::value('SELECT COUNT(*) FROM `otp_api_logs` WHERE `tenantId` = ? AND `status` >= 400 AND `createdAt` >= ?', [$t['id'], $since24h]),
                'lastCallAt' => $iso(Db::value('SELECT MAX(`createdAt`) FROM `otp_api_logs` WHERE `tenantId` = ?', [$t['id']])),
                'lastError'  => $lastError === null ? null : [
                    'at'     => $iso($lastError['createdAt']),
                    'path'   => $lastError['path'],
                    'status' => (int) $lastError['status'],
                    'code'   => $lastError['errorCode'],
                ],
            ];
        }, Db::all('SELECT * FROM `otp_tenants` ORDER BY `name`'));

        $recentErrors = array_map($logRow, Db::all(
            'SELECT l.*, t.`slug`, d.`name` AS deviceName FROM `otp_api_logs` l
             LEFT JOIN `otp_tenants` t ON t.`id` = l.`tenantId`
             LEFT JOIN `otp_devices` d ON d.`id` = l.`deviceId`
             WHERE l.`status` >= 400 ORDER BY l.`createdAt` DESC LIMIT 10'
        ));

        Http::ok([
            'smsToday'     => $smsToday,
            'globalCap'    => Config::int('GLOBAL_DAILY_CAP', 1000),
            'api24h'       => [
                'calls'  => (int) ($api['calls'] ?? 0),
                'errors' => (int) ($api['errors'] ?? 0),
                'avgMs'  => (int) ($api['avgMs'] ?? 0),
            ],
            'series'       => array_map(
                static fn (string $d, array $v): array => ['day' => $d] + $v,
                array_keys($series),
                array_values($series)
            ),
            'devices'      => $deviceRows(),
            'projects'     => $projects,
            'recentErrors' => $recentErrors,
            'pendingAccess'=> (int) Db::value("SELECT COUNT(*) FROM `otp_access_requests` WHERE `status` = 'pending'"),
            'serverTime'   => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    });

    // ─── Detail d'un projet (integration) ────────────────────────────────────
    $r->get('/v1/admin/projects/{slug}', static function (array $args) use ($tenantBySlug, $logRow, $iso): void {
        Auth::requireAdmin();
        $t = $tenantBySlug($args['slug']);

        $keys = array_map(static fn (array $k): array => [
            'id'         => $k['id'],
            // Prefixe seul : les premieres cles (sk_<prefixe>_…) n ont pas de mention live/test.
            'prefix'     => $k['prefix'],
            'env'        => $k['env'],
            'label'      => $k['label'],
            'createdAt'  => Db::iso($k['createdAt']),
            'lastUsedAt' => Db::iso($k['lastUsedAt']),
            'revokedAt'  => Db::iso($k['revokedAt']),
        ], Db::all('SELECT * FROM `otp_api_keys` WHERE `tenantId` = ? ORDER BY `createdAt`', [$t['id']]));

        $byEndpoint = array_map(static fn (array $e): array => [
            'method'  => $e['method'],
            'path'    => $e['path'],
            'calls'   => (int) $e['calls'],
            'errors'  => (int) $e['errors'],
            'avgMs'   => (int) $e['avgMs'],
            'lastAt'  => Db::iso($e['lastAt']),
        ], Db::all(
            'SELECT `method`, `path`, COUNT(*) AS calls, SUM(`status` >= 400) AS errors,
                    ROUND(AVG(`durationMs`)) AS avgMs, MAX(`createdAt`) AS lastAt
             FROM `otp_api_logs` WHERE `tenantId` = ? AND `createdAt` >= ?
             GROUP BY `method`, `path` ORDER BY calls DESC',
            [$t['id'], Db::now(-7 * 86400)]
        ));

        $errorCodes = Db::all(
            'SELECT `errorCode` AS code, COUNT(*) AS n FROM `otp_api_logs`
             WHERE `tenantId` = ? AND `status` >= 400 AND `createdAt` >= ?
             GROUP BY `errorCode` ORDER BY n DESC',
            [$t['id'], Db::now(-7 * 86400)]
        );

        $otp = Db::one(
            "SELECT COUNT(*) AS requested, SUM(`status` = 'verified') AS verified,
                    SUM(`status` = 'blocked') AS blocked
             FROM `otp_requests` WHERE `tenantId` = ? AND `createdAt` >= ?",
            [$t['id'], Db::now(-7 * 86400)]
        ) ?? [];

        Http::ok([
            'slug'        => $t['slug'],
            'name'        => $t['name'],
            'senderLabel' => $t['senderLabel'],
            'dailyQuota'  => (int) $t['dailyQuota'],
            'active'      => (int) $t['active'] === 1,
            'createdAt'   => $iso($t['createdAt']),
            'origin'      => $t['origin'],
            'contact'     => ['name' => $t['contactName'], 'email' => $t['contactEmail'], 'phone' => $t['contactPhone']],
            'testSms'     => ['limit' => (int) $t['testSmsLimit'], 'used' => (int) $t['testSmsUsed']],
            'keys'        => $keys,
            'endpoints7d' => $byEndpoint,
            'errorCodes7d'=> array_map(static fn (array $e): array => ['code' => $e['code'], 'count' => (int) $e['n']], $errorCodes),
            'otp7d'       => [
                'requested' => (int) ($otp['requested'] ?? 0),
                'verified'  => (int) ($otp['verified'] ?? 0),
                'blocked'   => (int) ($otp['blocked'] ?? 0),
            ],
            'recentCalls' => array_map($logRow, Db::all(
                'SELECT l.*, t.`slug`, NULL AS deviceName FROM `otp_api_logs` l
                 JOIN `otp_tenants` t ON t.`id` = l.`tenantId`
                 WHERE l.`tenantId` = ? ORDER BY l.`createdAt` DESC LIMIT 30',
                [$t['id']]
            )),
        ]);
    });

    // ─── Modifier un projet ──────────────────────────────────────────────────
    $r->post('/v1/admin/projects/{slug}', static function (array $args) use ($tenantBySlug): void {
        Auth::requireAdmin();
        $t    = $tenantBySlug($args['slug']);
        $data = [];

        if (Http::input('active') !== null) {
            $data['active'] = Http::input('active') ? 1 : 0;
        }
        if (Http::input('dailyQuota') !== null) {
            $quota = (int) Http::input('dailyQuota');
            if ($quota < 1 || $quota > 100000) {
                Http::fail('dailyQuota doit etre compris entre 1 et 100000.', 400);
            }
            $data['dailyQuota'] = $quota;
        }
        if (Http::input('senderLabel') !== null) {
            $label = Otp::ascii((string) Http::input('senderLabel'));
            if ($label === '' || strlen($label) > 30) {
                Http::fail('senderLabel : 1 a 30 caracteres sans accent.', 400);
            }
            $data['senderLabel'] = $label;
        }
        if ($data === []) {
            Http::fail('Rien a modifier (active, dailyQuota ou senderLabel).', 400);
        }

        Db::update('otp_tenants', $t['id'], $data);
        Http::ok(['slug' => $t['slug']] + $data);
    });

    // ─── Revoquer une cle ────────────────────────────────────────────────────
    $r->post('/v1/admin/keys/{id}/revoke', static function (array $args): void {
        Auth::requireAdmin();
        $key = Db::one('SELECT `id`, `revokedAt` FROM `otp_api_keys` WHERE `id` = ?', [$args['id']]);
        if ($key === null) {
            Http::fail('Cle introuvable.', 404);
        }
        if ($key['revokedAt'] === null) {
            Db::run('UPDATE `otp_api_keys` SET `revokedAt` = ? WHERE `id` = ?', [Db::now(), $key['id']]);
        }
        Http::ok(['id' => $key['id'], 'revoked' => true]);
    });

    // ─── Journal des appels API ──────────────────────────────────────────────
    $r->get('/v1/admin/logs', static function () use ($logRow): void {
        Auth::requireAdmin();

        $where  = [];
        $params = [];
        if (($slug = (string) ($_GET['project'] ?? '')) !== '') {
            $where[]  = 't.`slug` = ?';
            $params[] = $slug;
        }
        if (in_array($actor = (string) ($_GET['actor'] ?? ''), ['project', 'device', 'admin', 'anonymous'], true)) {
            $where[]  = 'l.`actor` = ?';
            $params[] = $actor;
        }
        $where[] = match ((string) ($_GET['status'] ?? '')) {
            'ok'    => 'l.`status` < 400',
            'error' => 'l.`status` >= 400',
            '4xx'   => 'l.`status` BETWEEN 400 AND 499',
            '5xx'   => 'l.`status` >= 500',
            default => '1 = 1',
        };
        if (($path = (string) ($_GET['path'] ?? '')) !== '') {
            $where[]  = 'l.`path` LIKE ?';
            $params[] = '%' . addcslashes($path, '%_\\') . '%';
        }
        if (($before = (string) ($_GET['before'] ?? '')) !== '') {
            $where[]  = 'l.`createdAt` < ?';
            $params[] = gmdate('Y-m-d H:i:s.v', (int) strtotime($before));
        }
        $limit = max(1, min((int) ($_GET['limit'] ?? 100), 200));

        Http::ok(['items' => array_map($logRow, Db::all(
            'SELECT l.*, t.`slug`, d.`name` AS deviceName FROM `otp_api_logs` l
             LEFT JOIN `otp_tenants` t ON t.`id` = l.`tenantId`
             LEFT JOIN `otp_devices` d ON d.`id` = l.`deviceId`
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY l.`createdAt` DESC LIMIT $limit",
            $params
        ))]);
    });

    // ─── SMS ─────────────────────────────────────────────────────────────────
    $r->get('/v1/admin/sms', static function () use ($smsRow): void {
        Auth::requireAdmin();

        $where  = ['1 = 1'];
        $params = [];
        if (($slug = (string) ($_GET['project'] ?? '')) !== '') {
            $where[]  = 't.`slug` = ?';
            $params[] = $slug;
        }
        if (in_array($status = (string) ($_GET['status'] ?? ''), ['queued', 'sending', 'sent', 'delivered', 'failed', 'expired'], true)) {
            $where[]  = 's.`status` = ?';
            $params[] = $status;
        }
        if (in_array($kind = (string) ($_GET['kind'] ?? ''), ['otp', 'notification'], true)) {
            $where[]  = 's.`kind` = ?';
            $params[] = $kind;
        }
        $digits = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? '')) ?? '';
        if ($digits !== '') {
            $where[]  = 's.`phone` LIKE ?';
            $params[] = '%' . substr($digits, -9) . '%';
        }
        if (($before = (string) ($_GET['before'] ?? '')) !== '') {
            $where[]  = 's.`createdAt` < ?';
            $params[] = gmdate('Y-m-d H:i:s.v', (int) strtotime($before));
        }
        $limit = max(1, min((int) ($_GET['limit'] ?? 100), 200));

        Http::ok(['items' => array_map($smsRow, Db::all(
            'SELECT s.*, t.`slug`, d.`name` AS deviceName FROM `otp_sms_messages` s
             JOIN `otp_tenants` t ON t.`id` = s.`tenantId`
             LEFT JOIN `otp_devices` d ON d.`id` = s.`deviceId`
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY s.`createdAt` DESC LIMIT $limit",
            $params
        ))]);
    });

    // ─── Telephones ──────────────────────────────────────────────────────────
    $r->get('/v1/admin/devices', static function () use ($deviceRows): void {
        Auth::requireAdmin();
        Http::ok(['items' => $deviceRows()]);
    });

    $r->post('/v1/admin/devices/{id}', static function (array $args): void {
        Auth::requireAdmin();
        if (Db::one('SELECT `id` FROM `otp_devices` WHERE `id` = ?', [$args['id']]) === null) {
            Http::fail('Telephone introuvable.', 404);
        }

        $data = [];
        if (Http::input('active') !== null) {
            $data['active'] = Http::input('active') ? 1 : 0;
        }
        if (Http::input('dailyLimit') !== null) {
            $limit = (int) Http::input('dailyLimit');
            if ($limit < 1 || $limit > 10000) {
                Http::fail('dailyLimit doit etre compris entre 1 et 10000.', 400);
            }
            $data['dailyLimit'] = $limit;
        }
        if (Http::input('name') !== null) {
            $name = trim((string) Http::input('name'));
            if ($name === '' || mb_strlen($name) > 60) {
                Http::fail("name : 1 a 60 caracteres.", 400);
            }
            $data['name'] = $name;
        }
        if ($data === []) {
            Http::fail('Rien a modifier (active, dailyLimit ou name).', 400);
        }

        Db::update('otp_devices', $args['id'], $data);
        Http::ok(['id' => $args['id']] + $data);
    });

    // ─── SMS de test, au nom d'un projet ─────────────────────────────────────
    $r->post('/v1/admin/test-sms', static function () use ($tenantBySlug): void {
        Auth::requireAdmin();

        $t = $tenantBySlug((string) Http::input('project', ''));
        $phone = Phone::normalize(Http::input('phone'));
        if ($phone === null) {
            Http::fail('phone doit etre un mobile camerounais.', 400, 'INVALID_PHONE');
        }
        $message = trim((string) Http::input('message', ''));
        if ($message === '') {
            $message = sprintf('%s : SMS de test de la passerelle teeeotp.', Otp::ascii((string) $t['senderLabel']));
        }
        if (mb_strlen($message) > 480) {
            Http::fail('message : 480 caracteres au plus.', 400);
        }

        $tenant = ['id' => $t['id'], 'dailyQuota' => (int) $t['dailyQuota']];
        try {
            $id = SmsQueue::enqueue($tenant, 'notification', $phone, $message, 600);
        } catch (QuotaExceeded $e) {
            Http::fail($e->getMessage(), 429, 'QUOTA_EXCEEDED');
        }
        Http::ok(['id' => $id, 'status' => 'queued'], 202);
    });
};
