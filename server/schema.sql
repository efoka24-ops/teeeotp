-- teeeotp — schema MySQL.
--
-- Horodatages en DATETIME(3) UTC (jamais TIMESTAMP, qui subit le fuseau de la
-- session). Memes conventions de nommage qu'apisungku.

SET FOREIGN_KEY_CHECKS = 0;

-- ─── Projets clients (KoliGo, ERP scolaire, Pressing…) ──────────────────────
CREATE TABLE IF NOT EXISTS `tenants` (
  `id`          CHAR(36)     NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `slug`        VARCHAR(50)  NOT NULL,
  -- Nom affiche dans le SMS : « 482913 est votre code <senderLabel> ».
  `senderLabel` VARCHAR(30)  NOT NULL,
  -- Plafond de SMS par jour pour ce projet : protege le forfait d'un projet
  -- qui boucle ou d'une cle qui fuit.
  `dailyQuota`  INT UNSIGNED NOT NULL DEFAULT 500,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `createdAt`   DATETIME(3)  NOT NULL,
  `updatedAt`   DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_key` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Cles API des projets ───────────────────────────────────────────────────
-- Seul le hachage est stocke ; la cle en clair n'est affichee qu'une fois.
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`         CHAR(36)    NOT NULL,
  `tenantId`   CHAR(36)    NOT NULL,
  `prefix`     VARCHAR(16) NOT NULL,
  `hash`       CHAR(64)    NOT NULL,
  `label`      VARCHAR(60) NULL,
  `lastUsedAt` DATETIME(3) NULL,
  `revokedAt`  DATETIME(3) NULL,
  `createdAt`  DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_keys_prefix_key` (`prefix`),
  KEY `api_keys_tenant_idx` (`tenantId`),
  CONSTRAINT `api_keys_tenant_fk` FOREIGN KEY (`tenantId`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Telephones passerelles ─────────────────────────────────────────────────
-- Chaque telephone (une SIM avec forfait SMS) s'authentifie avec son propre
-- jeton, revocable sans toucher aux autres.
CREATE TABLE IF NOT EXISTS `devices` (
  `id`          CHAR(36)     NOT NULL,
  `name`        VARCHAR(60)  NOT NULL,
  `tokenPrefix` VARCHAR(16)  NOT NULL,
  `tokenHash`   CHAR(64)     NOT NULL,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  -- Plafond journalier propre a la SIM, pour ne pas la faire bloquer par
  -- l'operateur.
  `dailyLimit`  INT UNSIGNED NOT NULL DEFAULT 300,
  `lastSeenAt`  DATETIME(3)  NULL,
  `lastIp`      VARCHAR(45)  NULL,
  `appVersion`  VARCHAR(20)  NULL,
  `battery`     TINYINT UNSIGNED NULL,
  `charging`    TINYINT(1)   NULL,
  `createdAt`   DATETIME(3)  NOT NULL,
  `updatedAt`   DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `devices_prefix_key` (`tokenPrefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Demandes d'OTP ─────────────────────────────────────────────────────────
-- Le code n'est jamais stocke en clair : seulement un HMAC poivre.
CREATE TABLE IF NOT EXISTS `otp_requests` (
  `id`           CHAR(36)    NOT NULL,
  `tenantId`     CHAR(36)    NOT NULL,
  `phone`        VARCHAR(16) NOT NULL,
  `purpose`      VARCHAR(30) NOT NULL DEFAULT 'verification',
  `codeHash`     CHAR(64)    NOT NULL,
  `attemptsLeft` TINYINT UNSIGNED NOT NULL,
  `status`       ENUM('pending','verified','expired','blocked') NOT NULL DEFAULT 'pending',
  `smsId`        CHAR(36)    NULL,
  `expiresAt`    DATETIME(3) NOT NULL,
  `verifiedAt`   DATETIME(3) NULL,
  `createdAt`    DATETIME(3) NOT NULL,
  `updatedAt`    DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `otp_phone_idx` (`phone`, `createdAt`),
  KEY `otp_tenant_idx` (`tenantId`, `createdAt`),
  CONSTRAINT `otp_tenant_fk` FOREIGN KEY (`tenantId`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── File des SMS a envoyer par les telephones ──────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_messages` (
  `id`          CHAR(36)     NOT NULL,
  `tenantId`    CHAR(36)     NOT NULL,
  `kind`        ENUM('otp','notification') NOT NULL,
  `phone`       VARCHAR(16)  NOT NULL,
  `body`        VARCHAR(480) NOT NULL,
  -- queued : en attente d'un telephone ; sending : reserve par un telephone ;
  -- sent : accepte par le reseau ; delivered : accuse de reception recu.
  `status`      ENUM('queued','sending','sent','delivered','failed','expired') NOT NULL DEFAULT 'queued',
  `priority`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `deviceId`    CHAR(36)     NULL,
  `claimToken`  CHAR(32)     NULL,
  `claimedAt`   DATETIME(3)  NULL,
  `sentAt`      DATETIME(3)  NULL,
  `deliveredAt` DATETIME(3)  NULL,
  `error`       VARCHAR(255) NULL,
  `expiresAt`   DATETIME(3)  NOT NULL,
  `createdAt`   DATETIME(3)  NOT NULL,
  `updatedAt`   DATETIME(3)  NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sms_queue_idx` (`status`, `priority`, `createdAt`),
  KEY `sms_claim_idx` (`claimToken`),
  KEY `sms_tenant_idx` (`tenantId`, `createdAt`),
  KEY `sms_device_idx` (`deviceId`, `sentAt`),
  CONSTRAINT `sms_tenant_fk` FOREIGN KEY (`tenantId`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
