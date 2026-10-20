<?php

declare(strict_types=1);

/**
 * Acces en libre-service : un developpeur demande l'acces depuis la
 * documentation, l'administrateur valide au back-office, le developpeur
 * recupere sa cle de test sur sa page de suivi.
 *
 * Un projet ouvert ainsi n'a qu'une cle de TEST (quelques vrais SMS, puis
 * simulation). La cle de production reste une decision de l'administrateur.
 */

return static function (Router $r): void {

    $find = static function (string $id): array {
        $req = Db::one(
            'SELECT a.*, t.`slug` FROM `otp_access_requests` a
             LEFT JOIN `otp_tenants` t ON t.`id` = a.`tenantId` WHERE a.`id` = ?',
            [$id]
        );
        if ($req === null) {
            Http::fail('Demande introuvable.', 404);
        }
        return $req;
    };

    $text = static function (string $field, int $min, int $max, bool $required = true): ?string {
        $value = trim((string) Http::input($field, ''));
        if ($value === '' && !$required) {
            return null;
        }
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            Http::fail("$field : entre $min et $max caracteres.", 400);
        }
        return $value;
    };

    // ─── Deposer une demande (public) ────────────────────────────────────────
    $r->post('/v1/access-requests', static function () use ($text): void {
        // Champ piege, invisible pour un humain : un robot le remplit. On lui
        // repond comme a tout le monde, sans rien enregistrer.
        if (trim((string) Http::input('website', '')) !== '') {
            Http::ok(['id' => Id::uuid(), 'token' => bin2hex(random_bytes(24)), 'status' => 'pending'], 201);
        }

        $projectName = $text('projectName', 2, 120);
        $contactName = $text('contactName', 2, 120);
        $company     = $text('company', 2, 120, false);
        $useCase     = $text('useCase', 20, 2000);

        $email = trim((string) Http::input('email', ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            Http::fail('email invalide.', 400);
        }
        $phone = trim((string) Http::input('phone', ''));
        if (!preg_match('/^\+?[0-9 ().-]{8,25}$/', $phone)) {
            Http::fail('phone invalide.', 400);
        }

        $ip = Http::clientIp();
        if ((int) Db::value('SELECT COUNT(*) FROM `otp_access_requests` WHERE `ip` = ? AND `createdAt` >= ?', [$ip, Db::now(-86400)]) >= 3) {
            Http::fail('Trop de demandes depuis cette adresse. Reessayez demain.', 429);
        }
        if ((int) Db::value("SELECT COUNT(*) FROM `otp_access_requests` WHERE `status` = 'pending'") >= 100) {
            Http::fail('Les demandes sont momentanement fermees.', 503);
        }

        $jeton = AccessRequests::newToken();
        $id = Db::insert('otp_access_requests', [
            'projectName'  => $projectName,
            'contactName'  => $contactName,
            'email'        => $email,
            'phone'        => Phone::normalize($phone) ?? $phone,
            'company'      => $company,
            'useCase'      => $useCase,
            'tokenHash'    => $jeton['tokenHash'],
            'boxPublicKey' => $jeton['publicKey'],
            'ip'           => mb_substr($ip, 0, 45),
        ]);

        Http::ok([
            'id'            => $id,
            'token'         => $jeton['token'],
            'status'        => 'pending',
            'statusUrl'     => Config::publicUrl() . "/docs/#suivi=$id." . $jeton['token'],
            'avertissement' => 'Conservez le lien de suivi : il est le seul moyen de recuperer votre cle.',
        ], 201);
    });

    // ─── Suivre sa demande et recuperer la cle (public, avec le jeton) ───────
    $r->post('/v1/access-requests/{id}/status', static function (array $args): void {
        $token = (string) Http::input('token', '');
        $req   = Db::one('SELECT * FROM `otp_access_requests` WHERE `id` = ?', [$args['id']]);
        // Meme reponse pour « inconnue » et « mauvais jeton ».
        if ($req === null || !AccessRequests::tokenMatches($req, $token)) {
            Http::fail('Demande introuvable.', 404);
        }

        $out = AccessRequests::present($req, false) + ['baseUrl' => Config::publicUrl()];

        if ($req['status'] === 'approved') {
            $tenant = Db::one('SELECT `slug`, `testSmsLimit`, `testSmsUsed` FROM `otp_tenants` WHERE `id` = ?', [$req['tenantId']]);
            $out += [
                'project'  => $tenant['slug'] ?? null,
                'testSms'  => $tenant === null ? null : ['limit' => (int) $tenant['testSmsLimit'], 'used' => (int) $tenant['testSmsUsed']],
            ];

            if ($req['keyCipher'] !== null) {
                $key = AccessRequests::open((string) $req['keyCipher'], $token);
                if ($key !== null) {
                    // Lecture unique : la cle quitte la base des qu'elle est remise.
                    Db::update('otp_access_requests', $req['id'], ['keyCipher' => null, 'keyRetrievedAt' => Db::now()]);
                    $out['apiKey'] = $key;
                    $out['avertissement'] = 'Copiez cette cle maintenant : elle ne sera plus jamais affichee.';
                }
            } else {
                $out['keyRetrievedAt'] = Db::iso($req['keyRetrievedAt']);
            }
        }

        Http::ok($out);
    });

    // ─── Back-office : lister les demandes ───────────────────────────────────
    $r->get('/v1/admin/access-requests', static function (): void {
        Auth::requireAdmin();

        $status = (string) ($_GET['status'] ?? '');
        $where  = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'WHERE a.`status` = ?' : '';
        $rows   = Db::all(
            "SELECT a.*, t.`slug` FROM `otp_access_requests` a
             LEFT JOIN `otp_tenants` t ON t.`id` = a.`tenantId`
             $where ORDER BY a.`status` = 'pending' DESC, a.`createdAt` DESC LIMIT 100",
            $where === '' ? [] : [$status]
        );

        Http::ok(['items' => array_map(static fn (array $a): array => AccessRequests::present($a, true), $rows)]);
    });

    // ─── Back-office : valider ───────────────────────────────────────────────
    $r->post('/v1/admin/access-requests/{id}/approve', static function (array $args) use ($find): void {
        Auth::requireAdmin();
        $req = $find($args['id']);
        if ($req['status'] !== 'pending') {
            Http::fail('Cette demande a deja ete traitee.', 409);
        }

        $slug = (string) Http::input('slug', '');
        if ($slug === '') {
            $slug = Tenants::freeSlug((string) $req['projectName']);
        } elseif (!Tenants::validSlug($slug) || Db::one('SELECT `id` FROM `otp_tenants` WHERE `slug` = ?', [$slug]) !== null) {
            Http::fail('slug invalide ou deja pris.', 400);
        }

        $senderLabel = Otp::ascii((string) Http::input('senderLabel', mb_substr((string) $req['projectName'], 0, 30)));
        $senderLabel = substr($senderLabel !== '' ? $senderLabel : 'teeeotp', 0, 30);
        $testSmsLimit = max(0, min((int) Http::input('testSmsLimit', Config::int('TEST_SMS_LIMIT', 5)), 1000));
        $dailyQuota   = max(1, min((int) Http::input('dailyQuota', 50), 100000));
        $note         = mb_substr(trim((string) Http::input('note', '')), 0, 500) ?: null;

        $tenantId = Db::transaction(static function () use ($req, $slug, $senderLabel, $testSmsLimit, $dailyQuota, $note): string {
            $tenantId = Tenants::create([
                'name'         => $req['projectName'],
                'slug'         => $slug,
                'senderLabel'  => $senderLabel,
                'dailyQuota'   => $dailyQuota,
                'testSmsLimit' => $testSmsLimit,
                'origin'       => 'self-service',
                'contactName'  => $req['contactName'],
                'contactEmail' => $req['email'],
                'contactPhone' => $req['phone'],
            ]);
            $key = Tenants::issueKey($tenantId, 'test', 'cle de test (libre-service)');

            Db::update('otp_access_requests', $req['id'], [
                'status'       => 'approved',
                'tenantId'     => $tenantId,
                'keyCipher'    => AccessRequests::seal((string) $req['boxPublicKey'], $key),
                'decisionNote' => $note,
                'decidedAt'    => Db::now(),
            ]);
            return $tenantId;
        });

        // Prevenir le demandeur par SMS s'il a un mobile camerounais. Hors
        // allocation de test : c'est un message du service, pas du projet.
        $notified = false;
        if (Phone::normalize($req['phone']) !== null) {
            try {
                SmsQueue::enqueue(
                    ['id' => $tenantId, 'dailyQuota' => $dailyQuota],
                    'notification',
                    (string) $req['phone'],
                    sprintf('teeeotp : votre acces de test pour %s est valide. Recuperez votre cle sur votre page de suivi.', Otp::ascii((string) $req['projectName'])),
                    86400
                );
                $notified = true;
            } catch (Throwable $e) {
                error_log('[access] SMS de validation non envoye : ' . $e->getMessage());
            }
        }

        Http::ok(['id' => $req['id'], 'status' => 'approved', 'project' => $slug, 'notifiedBySms' => $notified]);
    });

    // ─── Back-office : refuser ───────────────────────────────────────────────
    $r->post('/v1/admin/access-requests/{id}/reject', static function (array $args) use ($find): void {
        Auth::requireAdmin();
        $req = $find($args['id']);
        if ($req['status'] !== 'pending') {
            Http::fail('Cette demande a deja ete traitee.', 409);
        }

        Db::update('otp_access_requests', $req['id'], [
            'status'       => 'rejected',
            'decisionNote' => mb_substr(trim((string) Http::input('note', '')), 0, 500) ?: null,
            'decidedAt'    => Db::now(),
        ]);
        Http::ok(['id' => $req['id'], 'status' => 'rejected']);
    });

    // ─── Back-office : allocation de test d'un projet ────────────────────────
    $r->post('/v1/admin/projects/{slug}/test', static function (array $args): void {
        Auth::requireAdmin();
        $t = Db::one('SELECT `id`, `testSmsLimit`, `testSmsUsed` FROM `otp_tenants` WHERE `slug` = ?', [$args['slug']]);
        if ($t === null) {
            Http::fail('Projet introuvable.', 404);
        }

        $data = [];
        if (Http::input('testSmsLimit') !== null) {
            $data['testSmsLimit'] = max(0, min((int) Http::input('testSmsLimit'), 1000));
        }
        if (Http::input('reset')) {
            $data['testSmsUsed'] = 0;
        }
        if ($data === []) {
            Http::fail('Rien a modifier (testSmsLimit ou reset).', 400);
        }
        Db::update('otp_tenants', $t['id'], $data);

        $t = Db::one('SELECT `testSmsLimit`, `testSmsUsed` FROM `otp_tenants` WHERE `id` = ?', [$t['id']]);
        Http::ok(['testSmsLimit' => (int) $t['testSmsLimit'], 'testSmsUsed' => (int) $t['testSmsUsed']]);
    });
};
