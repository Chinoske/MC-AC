-- ═══════════════════════════════════════════════════════════════
--  Migrador de Personajes — AzerothCore WotLK 3.3.5a
--  install.sql — Tablas necesarias en acore_auth
--
--  INSTRUCCIONES:
--    mysql -u acore -p acore_auth < install.sql
--  O importar desde phpMyAdmin seleccionando la base acore_auth
-- ═══════════════════════════════════════════════════════════════

USE `acore_auth`;

-- ── Tabla principal de transferencias ────────────────────────
CREATE TABLE IF NOT EXISTS `account_transfer` (
    `id`          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `cAccount`    INT UNSIGNED     NOT NULL DEFAULT 0    COMMENT 'ID de cuenta del jugador',
    `cName`       VARCHAR(16)      NOT NULL DEFAULT ''   COMMENT 'Nombre del personaje',
    `cGUID`       INT UNSIGNED     NOT NULL DEFAULT 0    COMMENT 'GUID del personaje en el realm destino',
    `cRealmID`    TINYINT UNSIGNED NOT NULL DEFAULT 1    COMMENT 'ID del realm destino',
    `cRealmList`  VARCHAR(64)      NOT NULL DEFAULT ''   COMMENT 'Nombre de la DB del realm',
    `cDump`       MEDIUMTEXT                             COMMENT 'Datos del dump (opcional, puede quedar vacío)',
    `status`      TINYINT UNSIGNED NOT NULL DEFAULT 0    COMMENT '0=Pendiente 1=Aprobado 2=Denegado 3=Cancelado 4=Reenviado',
    `reason`      VARCHAR(255)     NOT NULL DEFAULT ''   COMMENT 'Motivo de denegación (GM)',
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cAccount` (`cAccount`),
    KEY `idx_status`   (`status`),
    KEY `idx_cGUID`    (`cGUID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solicitudes de transferencia de personaje';

-- ── Blacklist de cuentas (no pueden hacer transferencias) ─────
CREATE TABLE IF NOT EXISTS `account_transfer_blacklist` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` INT UNSIGNED NOT NULL,
    `reason`     VARCHAR(255) NOT NULL DEFAULT '',
    `added_by`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'account_id del GM que la añadió',
    `added_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cuentas que no pueden realizar transferencias';

-- ── Columna `reason` por si ya existe la tabla sin ella ───────
-- (Se ejecuta sin error aunque ya exista en versiones antiguas del schema)
ALTER TABLE `account_transfer`
    MODIFY COLUMN `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Motivo de denegación (GM)';

-- ── Índices extra (ignorar si ya existen) ─────────────────────
-- Crear índice solo si no existe (compatible MySQL 5.7 / 8.0 / MariaDB)
DROP PROCEDURE IF EXISTS `_migrador_add_idx`;
DELIMITER $$
CREATE PROCEDURE `_migrador_add_idx`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = 'account_transfer'
          AND index_name   = 'idx_created_at'
    ) THEN
        ALTER TABLE `account_transfer`
            ADD INDEX `idx_created_at` (`created_at`);
    END IF;
END$$
DELIMITER ;
CALL `_migrador_add_idx`();
DROP PROCEDURE IF EXISTS `_migrador_add_idx`;

-- ── Vista útil para reportes ──────────────────────────────────
CREATE OR REPLACE VIEW `v_transfer_summary` AS
    SELECT
        t.id,
        a.username    AS account_name,
        t.cName       AS char_name,
        t.cRealmID    AS realm_id,
        t.status,
        CASE t.status
            WHEN 0 THEN 'Pendiente'
            WHEN 1 THEN 'Aprobado'
            WHEN 2 THEN 'Denegado'
            WHEN 3 THEN 'Cancelado'
            WHEN 4 THEN 'Reenviado'
            ELSE 'Desconocido'
        END           AS status_label,
        t.reason,
        t.created_at,
        t.updated_at
    FROM `account_transfer` t
    LEFT JOIN `account` a ON a.id = t.cAccount
    ORDER BY t.id DESC;

SELECT 'Migrador instalado correctamente en acore_auth.' AS resultado;
