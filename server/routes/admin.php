<?php

declare(strict_types=1);

/**
 * Administration : enrolement des projets et des telephones, supervision.
 * Toutes ces routes exigent l'en-tete X-Admin-Key.
 */

return static function (Router $r): void {

    // ─── Creer un projet ─────────────────────────────────────────────────────
    $r->post('/v1/projects', static function (): void {
        Auth::requireAdmin();

        $name = trim((string) Http::input('name', ''));
        if ($name === '' || mb_strlen($name) > 120) {
            Http::fail("name requis, d'au plus 120 caracteres.", 400);
        }

        $slug = (string) Http::input('slug', '');
        if (!Tenants::validSlug($slug)) {
            Http::fail('slug : minuscules, chiffres et tirets, entre 3 et 50 caracteres.', 400);
        }

        $senderLabel = Otp::ascii((string) Http::input('senderLabel', $name));
        if ($senderLabel === '' || strlen($senderLabel) > 30) {
            Http::fail('senderLabel : 1 a 30 caracteres sans accent (nom affiche dans le SMS).', 400);
        }

        $dailyQuota = (int) Http::input('dailyQuota', 500);
        if ($dailyQuota < 1 || $dailyQuota > 100000) {
            Http::fail('dailyQuota doit etre compris entre 1 et 100000.', 400);
        }

        $testSmsLimit = (int) Http::input('testSmsLimit', Config::int('TEST_SMS_LIMIT', 5));
        if ($testSmsLimit < 0 || $testSmsLimit > 1000) {
            Http::fail('testSmsLimit doit etre compris entre 0 et 1000.', 400);
        }

        if (Db::one('SELECT `id` FROM `otp_tenants` WHERE `slug` = ?', [$slug]) !== null) {
            Http::fail("Le projet '$slug' existe deja. Utilisez POST /v1/projects/$slug/keys.", 409, 'SLUG_ALREADY_USED');
        }

        [$tenantId, $live, $test] = Db::transaction(static function () use ($name, $slug, $senderLabel, $dailyQuota, $testSmsLimit): array {
            $id = Tenants::create(compact('name', 'slug', 'senderLabel', 'dailyQuota', 'testSmsLimit'));
            return [$id, Tenants::issueKey($id, 'live', 'cle de production'), Tenants::issueKey($id, 'test', 'cle de test')];
        });

        Http::ok([
            'id'            => $tenantId,
            'name'          => $name,
            'slug'          => $slug,
            'senderLabel'   => $senderLabel,
            'dailyQuota'    => $dailyQuota,
            'testSmsLimit'  => $testSmsLimit,
            'apiKey'        => $live,
            'testApiKey'    => $test,
            'avertissement' => 'Conservez les deux cles maintenant : elles ne seront plus jamais affichees. '
                . 'La cle de test envoie au plus testSmsLimit vrais SMS, puis simule.',
        ], 201);
    });

    // ─── Ajouter une cle a un projet (rotation) ──────────────────────────────
    $r->post('/v1/projects/{slug}/keys', static function (array $args): void {
        Auth::requireAdmin();

        $tenant = Db::one('SELECT `id`, `slug` FROM `otp_tenants` WHERE `slug` = ?', [$args['slug']]);
        if ($tenant === null) {
            Http::fail("Projet '{$args['slug']}' introuvable.", 404);
        }

        $env = (string) Http::input('env', 'live');
        if (!in_array($env, ['live', 'test'], true)) {
            Http::fail('env doit valoir live ou test.', 400);
        }
        $key = Tenants::issueKey($tenant['id'], $env, $env === 'test' ? 'cle de test' : 'cle de production');

        Http::ok([
            'slug'          => $tenant['slug'],
            'env'           => $env,
            'apiKey'        => $key,
            'avertissement' => 'Les cles precedentes restent actives jusqu a revocation.',
        ], 201);
    });

    // ─── Enroler un telephone passerelle ─────────────────────────────────────
    $r->post('/v1/devices', static function (): void {
        Auth::requireAdmin();

        $name = trim((string) Http::input('name', ''));
        if ($name === '' || mb_strlen($name) > 60) {
            Http::fail("name requis, d'au plus 60 caracteres (ex. « Tecno SIM MTN »).", 400);
        }
        $dailyLimit = (int) Http::input('dailyLimit', 300);
        if ($dailyLimit < 1 || $dailyLimit > 10000) {
            Http::fail('dailyLimit doit etre compris entre 1 et 10000.', 400);
        }

        $jeton = Auth::generate('dv');
        $id = Db::insert('otp_devices', [
            'name'        => $name,
            'tokenPrefix' => $jeton['prefix'],
            'tokenHash'   => $jeton['hash'],
            'dailyLimit'  => $dailyLimit,
        ]);

        Http::ok([
            'id'            => $id,
            'name'          => $name,
            'dailyLimit'    => $dailyLimit,
            'serverUrl'     => Config::publicUrl(),
            'deviceToken'   => $jeton['plaintext'],
            'avertissement' => "Saisissez serverUrl et deviceToken dans l'appli du telephone. "
                . 'Le jeton ne sera plus jamais affiche.',
        ], 201);
    });

    // ─── Activer / desactiver un telephone ───────────────────────────────────
    $r->post('/v1/devices/{id}/active', static function (array $args): void {
        Auth::requireAdmin();

        if (Db::one('SELECT `id` FROM `otp_devices` WHERE `id` = ?', [$args['id']]) === null) {
            Http::fail('Telephone introuvable.', 404);
        }
        $active = (bool) Http::input('active', true);
        Db::update('otp_devices', $args['id'], ['active' => $active ? 1 : 0]);
        Http::ok(['id' => $args['id'], 'active' => $active]);
    });

    // ─── Supervision ─────────────────────────────────────────────────────────
    $r->get('/v1/admin/status', static function (): void {
        Auth::requireAdmin();

        $dayStart = gmdate('Y-m-d 00:00:00');
        $offline  = Config::int('DEVICE_OFFLINE_AFTER_SECONDS', 60);

        $devices = array_map(static function (array $d) use ($offline, $dayStart): array {
            $seen = $d['lastSeenAt'] === null ? null : strtotime($d['lastSeenAt'] . ' UTC');
            return [
                'id'         => $d['id'],
                'name'       => $d['name'],
                'active'     => (int) $d['active'] === 1,
                'online'     => $seen !== null && time() - $seen <= $offline,
                'lastSeenAt' => Db::iso($d['lastSeenAt']),
                'battery'    => $d['battery'] === null ? null : (int) $d['battery'],
                'charging'   => $d['charging'] === null ? null : (int) $d['charging'] === 1,
                'appVersion' => $d['appVersion'],
                'dailyLimit' => (int) $d['dailyLimit'],
                'sentToday'  => (int) Db::value(
                    "SELECT COUNT(*) FROM `otp_sms_messages` WHERE `deviceId` = ? AND `status` IN ('sent','delivered') AND `sentAt` >= ?",
                    [$d['id'], $dayStart]
                ),
            ];
        }, Db::all('SELECT * FROM `otp_devices` ORDER BY `createdAt`'));

        $parStatut = [];
        foreach (Db::all('SELECT `status`, COUNT(*) AS n FROM `otp_sms_messages` WHERE `createdAt` >= ? GROUP BY `status`', [$dayStart]) as $row) {
            $parStatut[$row['status']] = (int) $row['n'];
        }

        $recents = array_map(static fn (array $s): array => [
            'id'        => $s['id'],
            'project'   => $s['slug'],
            'kind'      => $s['kind'],
            'phone'     => Phone::mask($s['phone']),
            'status'    => $s['status'],
            'attempts'  => (int) $s['attempts'],
            'error'     => $s['error'],
            'createdAt' => Db::iso($s['createdAt']),
            'sentAt'    => Db::iso($s['sentAt']),
        ], Db::all(
            'SELECT s.*, t.`slug` FROM `otp_sms_messages` s JOIN `otp_tenants` t ON t.`id` = s.`tenantId`
             ORDER BY s.`createdAt` DESC LIMIT 30'
        ));

        Http::ok([
            'devices'    => $devices,
            'today'      => $parStatut,
            'globalCap'  => Config::int('GLOBAL_DAILY_CAP', 1000),
            'projects'   => Db::all('SELECT `slug`, `name`, `senderLabel`, `dailyQuota`, `active` FROM `otp_tenants` ORDER BY `name`'),
            'recent'     => $recents,
        ]);
    });
};
