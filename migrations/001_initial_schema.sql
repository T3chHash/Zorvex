-- =====================================================================
-- Zorvex | Network — Initial database schema
-- Charset: utf8mb4 · Engine: InnoDB
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ---------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    telegram_id   BIGINT           NOT NULL,
    username      VARCHAR(64)      NOT NULL DEFAULT '',
    first_name    VARCHAR(96)      NOT NULL DEFAULT '',
    last_name     VARCHAR(96)      NOT NULL DEFAULT '',
    role          ENUM('user','admin') NOT NULL DEFAULT 'user',
    status        ENUM('active','banned','disabled') NOT NULL DEFAULT 'active',
    phone         VARCHAR(24)      NOT NULL DEFAULT '',
    balance       DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    vpn_server_id INT UNSIGNED     NULL,
    vpn_username  VARCHAR(64)      NULL,
    created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_telegram_id (telegram_id),
    KEY ix_users_username (username),
    KEY ix_users_status (status),
    KEY ix_users_created_at (created_at),
    CONSTRAINT fk_users_server FOREIGN KEY (vpn_server_id)
        REFERENCES servers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Servers (VPN panels)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS servers (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name         VARCHAR(96)   NOT NULL,
    url          VARCHAR(255)  NOT NULL,
    type         ENUM('marzban','3x-ui','other') NOT NULL DEFAULT 'marzban',
    api_key      TEXT          NOT NULL,
    status       ENUM('active','disabled','maintenance') NOT NULL DEFAULT 'active',
    subnet       VARCHAR(64)   NULL DEFAULT NULL,
    max_clients  INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_servers_type (type),
    KEY ix_servers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Products (purchasable packages)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    server_id     INT UNSIGNED  NOT NULL,
    name          VARCHAR(128)  NOT NULL,
    description   TEXT          NULL,
    price         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    duration_days INT UNSIGNED  NOT NULL DEFAULT 30,
    traffic       BIGINT UNSIGNED NOT NULL DEFAULT 0,           -- bytes
    status        ENUM('active','disabled','draft') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_products_server (server_id),
    KEY ix_products_status (status),
    CONSTRAINT fk_products_server FOREIGN KEY (server_id)
        REFERENCES servers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Subscriptions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    server_id     INT UNSIGNED    NOT NULL,
    product_id    INT UNSIGNED    NULL,
    username      VARCHAR(96)     NOT NULL,
    status        ENUM('pending','active','expired','suspended','revoked') NOT NULL DEFAULT 'pending',
    traffic_used  BIGINT UNSIGNED NOT NULL DEFAULT 0,           -- bytes
    traffic_limit BIGINT UNSIGNED NOT NULL DEFAULT 0,           -- bytes
    expires_at    DATETIME        NULL,
    activated_at  DATETIME        NULL,
    config_link   TEXT            NULL,
    panel_payload TEXT            NULL,                        -- JSON
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_subscriptions_user (user_id),
    KEY ix_subscriptions_server (server_id),
    KEY ix_subscriptions_status (status),
    KEY ix_subscriptions_expires (expires_at),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_server FOREIGN KEY (server_id)
        REFERENCES servers (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Invoices
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)    NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    product_id      INT UNSIGNED NULL,
    status          ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    description     TEXT        NULL,
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_uuid (uuid),
    KEY ix_invoices_user (user_id),
    KEY ix_invoices_status (status),
    KEY ix_invoices_subscription (subscription_id),
    CONSTRAINT fk_invoices_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_subscription FOREIGN KEY (subscription_id)
        REFERENCES subscriptions (id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    invoice_id   BIGINT UNSIGNED NULL,
    method       ENUM('crypto','card_to_card','zarinpal','wallet') NOT NULL DEFAULT 'zarinpal',
    status       ENUM('pending','processing','completed','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency     VARCHAR(12)   NULL,
    order_id     VARCHAR(64)   NOT NULL,
    external_ref VARCHAR(255)  NULL,
    proof_file   VARCHAR(255)  NULL,
    meta         TEXT          NULL,                            -- JSON
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at      DATETIME      NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_order (order_id),
    KEY ix_payments_user (user_id),
    KEY ix_payments_status (status),
    KEY ix_payments_invoice (invoice_id),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings (key-value)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`      VARCHAR(96)  NOT NULL,
    `value`    TEXT         NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tickets (support)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    subject    VARCHAR(160)    NOT NULL,
    status     ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_tickets_user (user_id),
    KEY ix_tickets_status (status),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Ticket messages
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_messages (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id  BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    message    TEXT            NOT NULL,
    is_admin   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_tm_ticket (ticket_id),
    CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Migration bookkeeping (for future migrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(160) NOT NULL,
    batch      INT UNSIGNED NOT NULL DEFAULT 1,
    executed_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_migrations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (name, batch) VALUES ('001_initial_schema', 1)
    ON DUPLICATE KEY UPDATE name = name;

SET foreign_key_checks = 1;