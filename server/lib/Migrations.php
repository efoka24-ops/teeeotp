<?php

declare(strict_types=1);

/**
 * Evolutions du schema apres l'installation initiale.
 *
 * install.php ne reste pas sur le serveur : les tables et colonnes ajoutees
 * ensuite sont creees par POST /v1/admin/migrate (cle d'administration).
 * Tout est idempotent : rejouer la migration ne change rien. MySQL 8 ne
 * connaissant pas `ADD COLUMN IF NOT EXISTS`, l'existence de chaque colonne
 * est verifiee dans information_schema avant l'ajout.
 */
final class Migrations
{
    /** @return list<string> */
    public static function tables(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS `otp_api_logs` (
              `id`         CHAR(36)     NOT NULL,
              `actor`      ENUM('project','device','admin','anonymous') NOT NULL,
              `tenantId`   CHAR(36)     NULL,
              `deviceId`   CHAR(36)     NULL,
              `keyPrefix`  VARCHAR(16)  NULL,
              `method`     VARCHAR(8)   NOT NULL,
              `path`       VARCHAR(120) NOT NULL,
              `status`     SMALLINT UNSIGNED NOT NULL,
              `errorCode`  VARCHAR(40)  NULL,
              `durationMs` INT UNSIGNED NOT NULL,
              `ip`         VARCHAR(45)  NULL,
              `test`       TINYINT(1)   NOT NULL DEFAULT 0,
              `createdAt`  DATETIME(3)  NOT NULL,
              PRIMARY KEY (`id`),
              KEY `otp_api_logs_created_idx` (`createdAt`),
              KEY `otp_api_logs_tenant_idx` (`tenantId`, `createdAt`),
              KEY `otp_api_logs_status_idx` (`status`, `createdAt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Demandes d'acces deposees depuis la documentation publique. La
            // cle emise a la validation y attend, chiffree avec le jeton que
            // seul le demandeur detient, jusqu'a sa premiere lecture.
            "CREATE TABLE IF NOT EXISTS `otp_access_requests` (
              `id`             CHAR(36)     NOT NULL,
              `projectName`    VARCHAR(120) NOT NULL,
              `contactName`    VARCHAR(120) NOT NULL,
              `email`          VARCHAR(190) NOT NULL,
              `phone`          VARCHAR(30)  NOT NULL,
              `company`        VARCHAR(120) NULL,
              `useCase`        TEXT         NOT NULL,
              `status`         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              `tokenHash`      CHAR(64)     NOT NULL,
              `boxPublicKey`   CHAR(64)     NOT NULL,
              `tenantId`       CHAR(36)     NULL,
              `keyCipher`      TEXT         NULL,
              `keyRetrievedAt` DATETIME(3)  NULL,
              `decisionNote`   VARCHAR(500) NULL,
              `decidedAt`      DATETIME(3)  NULL,
              `ip`             VARCHAR(45)  NULL,
              `createdAt`      DATETIME(3)  NOT NULL,
              `updatedAt`      DATETIME(3)  NOT NULL,
              PRIMARY KEY (`id`),
              KEY `otp_access_status_idx` (`status`, `createdAt`),
              KEY `otp_access_ip_idx` (`ip`, `createdAt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> table, colonne, definition */
    public static function columns(): array
    {
        return [
            // Mode test : vrais SMS dans la limite de testSmsLimit, simulation au-dela.
            ['otp_tenants', 'testSmsLimit', 'INT UNSIGNED NOT NULL DEFAULT 5'],
            ['otp_tenants', 'testSmsUsed', 'INT UNSIGNED NOT NULL DEFAULT 0'],
            ['otp_tenants', 'origin', "ENUM('admin','self-service') NOT NULL DEFAULT 'admin'"],
            ['otp_tenants', 'contactName', 'VARCHAR(120) NULL'],
            ['otp_tenants', 'contactEmail', 'VARCHAR(190) NULL'],
            ['otp_tenants', 'contactPhone', 'VARCHAR(30) NULL'],
            // Les cles emises avant le mode test (sk_<prefixe>_<secret>) sont de production.
            ['otp_api_keys', 'env', "ENUM('live','test') NOT NULL DEFAULT 'live'"],
            ['otp_requests', 'test', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['otp_sms_messages', 'test', 'TINYINT(1) NOT NULL DEFAULT 0'],
            ['otp_api_logs', 'test', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ];
    }

    /** @return array{applied: list<string>, errors: list<string>} */
    public static function run(): array
    {
        $applied = [];
        $errors  = [];

        foreach (self::tables() as $sql) {
            try {
                Db::pdo()->exec($sql);
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach (self::columns() as [$table, $column, $definition]) {
            $exists = (int) Db::value(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            if ($exists > 0) {
                continue;
            }
            try {
                Db::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $applied[] = "$table.$column";
            } catch (PDOException $e) {
                $errors[] = "$table.$column : " . $e->getMessage();
            }
        }

        return ['applied' => $applied, 'errors' => $errors];
    }
}
