<?php

declare(strict_types=1);

/**
 * Evolutions du schema apres l'installation initiale.
 *
 * install.php ne reste pas sur le serveur : les tables ajoutees ensuite sont
 * creees par POST /v1/admin/migrate (cle d'administration). Chaque
 * instruction est idempotente : rejouer la migration ne change rien.
 */
final class Migrations
{
    /** @return list<string> */
    public static function statements(): array
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
              `createdAt`  DATETIME(3)  NOT NULL,
              PRIMARY KEY (`id`),
              KEY `otp_api_logs_created_idx` (`createdAt`),
              KEY `otp_api_logs_tenant_idx` (`tenantId`, `createdAt`),
              KEY `otp_api_logs_status_idx` (`status`, `createdAt`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** @return array{applied: int, errors: list<string>} */
    public static function run(): array
    {
        $errors  = [];
        $applied = 0;
        foreach (self::statements() as $sql) {
            try {
                Db::pdo()->exec($sql);
                $applied++;
            } catch (PDOException $e) {
                $errors[] = $e->getMessage();
            }
        }
        return ['applied' => $applied, 'errors' => $errors];
    }
}
