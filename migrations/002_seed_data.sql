-- =====================================================================
-- Zorvex | Network — Default settings and seed data
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Default settings
-- ---------------------------------------------------------------------
INSERT INTO settings (`key`, `value`) VALUES
    ('site_name',          'Zorvex | Network'),
    ('support_username',   '@zorvex_support'),
    ('default_currency',   'IRR'),
    ('crypto_network',     'TRC20'),
    ('crypto_wallet',      ''),
    ('card_to_card',       '6037-****-****-****'),
    ('card_to_card_holder',''),
    ('zarinpal_merchant',  ''),
    ('zarinpal_sandbox',   'true'),
    ('min_purchase_amount','10000'),
    ('max_clients_per_user','3'),
    ('welcome_bonus',      '0'),
    ('vpn_username_prefix','z'),
    ('reminder_days_before','3'),
    ('maintenance_mode',   'false')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ---------------------------------------------------------------------
-- Seed a demo server + sample products (edit before going live)
-- ---------------------------------------------------------------------
INSERT INTO servers (name, url, type, api_key, status, max_clients)
SELECT * FROM (
    SELECT 'Main Marzban', 'https://panel.example.com', 'marzban',
           'CHANGE_ME_API_KEY', 'active', 500
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM servers LIMIT 1);

-- Sample products for the demo server (id = 1 if just seeded).
INSERT INTO products (server_id, name, description, price, duration_days, traffic, status)
SELECT
    s.id,
    d.name,
    d.description,
    d.price,
    d.duration_days,
    d.traffic,
    d.status
FROM (SELECT 1 AS server_idx) x
JOIN servers s ON s.id = 1
CROSS JOIN (
    SELECT '1 ماهه'        AS name, 'حجم 30 گیگابایت' AS description, 99000  AS price, 30 AS duration_days, 30 * 1024 * 1024 * 1024 AS traffic, 'active' AS status
    UNION ALL SELECT '2 ماهه', 'حجم 60 گیگابایت', 185000, 60, 60 * 1024 * 1024 * 1024, 'active'
    UNION ALL SELECT '3 ماهه', 'حجم 100 گیگابایت', 265000, 90, 100 * 1024 * 1024 * 1024, 'active'
) d
WHERE NOT EXISTS (SELECT 1 FROM products LIMIT 1);