<?php

declare(strict_types=1);

/**
 * Routes appelees par les telephones passerelles, avec leur jeton `dv_…`.
 *
 * Le telephone interroge (`claim`) toutes les quelques secondes : c'est lui
 * qui initie toutes les connexions, il n'a donc besoin ni d'IP fixe ni de port
 * ouvert, et fonctionne derriere n'importe quel reseau mobile.
 */

return static function (Router $r): void {

    // ─── Reserver des SMS a envoyer (sert aussi de signal de vie) ────────────
    $r->post('/v1/gateway/claim', static function (): void {
        $device = Auth::requireDevice();

        $battery = Http::input('battery');
        Db::update('otp_devices', $device['id'], [
            'lastSeenAt' => Db::now(),
            'lastIp'     => Http::clientIp(),
            'appVersion' => is_string(Http::input('appVersion')) ? mb_substr(Http::input('appVersion'), 0, 20) : $device['appVersion'],
            'battery'    => is_int($battery) && $battery >= 0 && $battery <= 100 ? $battery : null,
            'charging'   => Http::input('charging') === null ? null : (Http::input('charging') ? 1 : 0),
        ]);

        $limit = max(1, min((int) Http::input('limit', 5), 20));

        Http::ok([
            'messages'    => SmsQueue::claim($device, $limit),
            // Le serveur pilote le rythme d'interrogation : on peut ralentir
            // tous les telephones sans republier l'appli.
            'pollAfterMs' => Config::int('GATEWAY_POLL_MS', 3000),
            'sendDelayMs' => Config::int('GATEWAY_SEND_DELAY_MS', 1500),
        ]);
    });

    // ─── Compte rendu d'envoi (un ou plusieurs) ──────────────────────────────
    $r->post('/v1/gateway/report', static function (): void {
        $device  = Auth::requireDevice();
        $reports = Http::input('reports');
        if (!is_array($reports)) {
            $reports = [Http::body()];
        }
        if (count($reports) > 50) {
            Http::fail('Au plus 50 comptes rendus par appel.', 400);
        }

        $acceptes = 0;
        $inconnus = [];
        foreach ($reports as $report) {
            $id     = is_array($report) ? (string) ($report['id'] ?? '') : '';
            $status = is_array($report) ? (string) ($report['status'] ?? '') : '';
            if ($id === '' || !in_array($status, ['sent', 'delivered', 'failed'], true)) {
                Http::fail('Chaque compte rendu exige id et status (sent, delivered ou failed).', 400);
            }

            $error = isset($report['error']) && is_string($report['error']) ? $report['error'] : null;
            if (SmsQueue::report($device, $id, $status, $error)) {
                $acceptes++;
            } else {
                $inconnus[] = $id;
            }
        }

        Http::ok(['accepted' => $acceptes, 'unknown' => $inconnus]);
    });
};
