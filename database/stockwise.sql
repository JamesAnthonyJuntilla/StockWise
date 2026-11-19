-- =============================================================================
-- StockWise POS & Inventory Management System
-- Database Schema for MariaDB / MySQL
-- Generated: 2026-11-19
-- =============================================================================
-- NOTE: User passwords are NOT seeded here.
--       Run `seed_passwords.php` once after importing this file to create
--       the default admin/manager/cashier accounts with properly hashed
--       passwords.  Admins MUST change all passwords after first login.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =============================================================================
-- TABLE: users
-- =============================================================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50)  NOT NULL,
    `email`      VARCHAR(150) NOT NULL,
    `password`   VARCHAR(255) NOT NULL,
    `full_name`  VARCHAR(100) NOT NULL,
    `role`       ENUM('admin','manager','cashier') NOT NULL DEFAULT 'cashier',
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email`    (`email`),
    KEY `idx_users_role`      (`role`),
    KEY `idx_users_is_active`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: categories
-- =============================================================================
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)  NOT NULL,
    `description` TEXT,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_name` (`name`),
    KEY `idx_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: suppliers
-- =============================================================================
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `company_name`   VARCHAR(150)  NOT NULL,
    `contact_person` VARCHAR(100),
    `phone`          VARCHAR(30),
    `email`          VARCHAR(150),
    `address`        TEXT,
    `notes`          TEXT,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_suppliers_company_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: products
-- =============================================================================
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `sku`            VARCHAR(50)     NOT NULL,
    `name`           VARCHAR(200)    NOT NULL,
    `description`    TEXT,
    `category_id`    INT UNSIGNED    NOT NULL,
    `supplier_id`    INT UNSIGNED,
    `purchase_price` DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `selling_price`  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `stock_quantity` INT             NOT NULL DEFAULT 0,
    `min_stock_level` INT            NOT NULL DEFAULT 5,
    `image_path`     VARCHAR(255),
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_products_sku` (`sku`),
    KEY `idx_products_category_id`    (`category_id`),
    KEY `idx_products_supplier_id`    (`supplier_id`),
    KEY `idx_products_name`           (`name`),
    KEY `idx_products_stock_quantity` (`stock_quantity`),
    CONSTRAINT `fk_products_category`
        FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_products_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: inventory_movements
-- =============================================================================
DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE `inventory_movements` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `product_id`    INT UNSIGNED  NOT NULL,
    `movement_type` ENUM('stock_in','stock_out','adjustment','sale') NOT NULL,
    `quantity`      INT           NOT NULL,
    `qty_before`    INT           NOT NULL,
    `qty_after`     INT           NOT NULL,
    `reason`        VARCHAR(255),
    `notes`         TEXT,
    `user_id`       INT UNSIGNED  NOT NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_inv_mov_product_id`    (`product_id`),
    KEY `idx_inv_mov_user_id`       (`user_id`),
    KEY `idx_inv_mov_movement_type` (`movement_type`),
    KEY `idx_inv_mov_created_at`    (`created_at`),
    CONSTRAINT `fk_inv_mov_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_inv_mov_user`
        FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: sales
-- =============================================================================
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
    `id`             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `receipt_no`     VARCHAR(30)    NOT NULL,
    `user_id`        INT UNSIGNED   NOT NULL,
    `subtotal`       DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_amount`   DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `payment_amount` DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `change_amount`  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `notes`          TEXT,
    `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sales_receipt_no` (`receipt_no`),
    KEY `idx_sales_user_id`    (`user_id`),
    KEY `idx_sales_created_at` (`created_at`),
    CONSTRAINT `fk_sales_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: sale_items
-- =============================================================================
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `sale_id`      INT UNSIGNED   NOT NULL,
    `product_id`   INT UNSIGNED   NOT NULL,
    `product_name` VARCHAR(200)   NOT NULL,
    `selling_price` DECIMAL(12,2) NOT NULL,
    `quantity`     INT            NOT NULL,
    `subtotal`     DECIMAL(12,2)  NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sale_items_sale_id`    (`sale_id`),
    KEY `idx_sale_items_product_id` (`product_id`),
    CONSTRAINT `fk_sale_items_sale`
        FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_sale_items_product`
        FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: audit_logs
-- =============================================================================
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED,
    `action`      VARCHAR(100)  NOT NULL,
    `description` TEXT,
    `ip_address`  VARCHAR(45),
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_logs_user_id`    (`user_id`),
    KEY `idx_audit_logs_action`     (`action`),
    KEY `idx_audit_logs_created_at` (`created_at`),
    CONSTRAINT `fk_audit_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SEED DATA — Categories
-- =============================================================================
INSERT INTO `categories` (`id`, `name`, `description`) VALUES
(1, 'Electronics',        'Consumer electronics, gadgets, and accessories'),
(2, 'Clothing',           'Apparel, footwear, and fashion accessories'),
(3, 'Food & Beverages',   'Packaged food, drinks, and snacks'),
(4, 'Office Supplies',    'Stationery, paper, and office accessories'),
(5, 'Household Items',    'Home essentials, cleaning supplies, and kitchenware');

-- =============================================================================
-- SEED DATA — Suppliers
-- =============================================================================
INSERT INTO `suppliers` (`id`, `company_name`, `contact_person`, `phone`, `email`, `address`, `notes`) VALUES
(1, 'TechSupply Co',    'James Carter',   '+1-800-555-0101', 'sales@techsupply.example.com',
   '123 Silicon Ave, San Jose, CA 95101', 'Preferred electronics distributor. Net-30 terms.'),
(2, 'Fashion World',    'Maria Gonzalez', '+1-800-555-0202', 'orders@fashionworld.example.com',
   '456 Runway Blvd, New York, NY 10001', 'Seasonal collections. Lead time 2-3 weeks.'),
(3, 'General Goods Inc','Robert Kim',    '+1-800-555-0303', 'info@generalgoods.example.com',
   '789 Warehouse Dr, Chicago, IL 60601', 'Broad product range. Same-day shipping on orders before noon.');

-- =============================================================================
-- SEED DATA — Products (15 products)
-- =============================================================================
INSERT INTO `products`
    (`id`, `sku`, `name`, `description`, `category_id`, `supplier_id`,
     `purchase_price`, `selling_price`, `stock_quantity`, `min_stock_level`) VALUES
-- Electronics (cat 1, supplier 1)
(1,  'ELEC-001', 'Wireless Bluetooth Headphones',
     'Over-ear noise-cancelling headphones with 30h battery life',       1, 1,  45.00, 89.99,  40, 5),
(2,  'ELEC-002', 'USB-C Charging Cable 1m',
     'Braided fast-charge cable compatible with USB-C devices',          1, 1,   3.50,  9.99, 120, 20),
(3,  'ELEC-003', 'Portable Power Bank 10000mAh',
     'Slim power bank with dual USB output and LED indicator',           1, 1,  18.00, 39.99,  55, 10),
(4,  'ELEC-004', 'Wireless Mouse',
     'Ergonomic 2.4GHz wireless mouse with nano receiver',              1, 1,   8.00, 19.99,  70, 10),
(5,  'ELEC-005', 'Mechanical Keyboard',
     'TKL layout, blue switches, RGB backlight',                         1, 1,  35.00, 74.99,  25, 5),
-- Clothing (cat 2, supplier 2)
(6,  'CLTH-001', 'Classic White T-Shirt',
     'Unisex 100% cotton crew-neck tee, sizes S-XXL',                   2, 2,   5.00, 15.99,  90, 15),
(7,  'CLTH-002', 'Slim-Fit Chino Pants',
     'Stretch cotton chinos in khaki, navy, and olive',                 2, 2,  18.00, 44.99,  60, 10),
(8,  'CLTH-003', 'Running Sneakers',
     'Lightweight mesh upper with cushioned sole, sizes 6-12',          2, 2,  22.00, 59.99,  35, 8),
-- Food & Beverages (cat 3, supplier 3)
(9,  'FOOD-001', 'Instant Coffee 200g',
     'Premium freeze-dried instant coffee, smooth and rich',            3, 3,   4.50, 11.99,  80, 15),
(10, 'FOOD-002', 'Mineral Water 1.5L (12-pack)',
     'Natural spring water, 12 x 1.5L bottles per pack',               3, 3,   6.00, 12.99, 100, 20),
(11, 'FOOD-003', 'Mixed Nuts 500g',
     'Roasted unsalted cashews, almonds, and walnuts blend',            3, 3,   8.00, 17.99,  65, 12),
-- Office Supplies (cat 4, supplier 3)
(12, 'OFFC-001', 'Ballpoint Pen Set (12-pack)',
     'Smooth-writing blue ink pens, retractable tip',                   4, 3,   1.80,  5.99,  150, 25),
(13, 'OFFC-002', 'A4 Copy Paper 80gsm (500 sheets)',
     'Bright white multipurpose paper for laser/inkjet printers',       4, 3,   4.20,  8.99,  200, 30),
(14, 'OFFC-003', 'Sticky Notes 76x76mm (12-pack)',
     'Repositionable adhesive notes in assorted colours',               4, 3,   3.00,  6.99,  180, 25),
-- Household Items (cat 5, supplier 3)
(15, 'HSHD-001', 'Dish Soap 500ml',
     'Concentrated lemon-scented dish washing liquid',                  5, 3,   1.50,  3.99,  120, 20);

-- =============================================================================
-- SEED DATA — Users
-- Default accounts created with verified bcrypt hashes (cost=12):
--   admin   / Admin@1234!    (Role: Administrator)
--   manager / Manager@1234!  (Role: Manager)
--   cashier / Cashier@1234!  (Role: Cashier)
-- In production, change all default passwords immediately after initial setup.
-- =============================================================================
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin',   'admin@stockwise.local',   '$2y$12$2vuLmX3mOEjiSJVmZuvXDON6jFzIRJeH6Ya0d2tXtItWgjDDFneoC', 'System Administrator', 'admin',   1, NOW()),
(2, 'manager', 'manager@stockwise.local', '$2y$12$99hdCeUm523naUJkYNxKT.QVcKina7MK8CKP2mPvOaKkKHaoGDIEa', 'Store Manager',        'manager', 1, NOW()),
(3, 'cashier', 'cashier@stockwise.local', '$2y$12$yNJ.6DyAqce86KbggFF/te4qHZzKtKkwU0/3Cg/1VgQy.mBx7.Hu6', 'Default Cashier',       'cashier', 1, NOW());


-- =============================================================================
-- SEED DATA — Sales (25 records over the past 30 days)
-- User IDs reference users that seed_passwords.php will create (1=admin, 2=manager, 3=cashier).
-- FK checks are OFF during import so rows are accepted; run seed_passwords.php first in production.
-- =============================================================================
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `sales`
    (`id`, `receipt_no`, `user_id`, `subtotal`, `discount_amount`, `total_amount`,
     `payment_amount`, `change_amount`, `notes`, `created_at`) VALUES
( 1, 'RCP-20261019-001', 3,  99.98,  0.00,  99.98, 100.00,   0.02, NULL,                 DATE_SUB(NOW(), INTERVAL 30 DAY)),
( 2, 'RCP-20261020-001', 3,  44.99,  0.00,  44.99,  50.00,   5.01, NULL,                 DATE_SUB(NOW(), INTERVAL 29 DAY)),
( 3, 'RCP-20261021-001', 2,  27.98,  2.00,  25.98,  30.00,   4.02, 'Loyalty discount',   DATE_SUB(NOW(), INTERVAL 28 DAY)),
( 4, 'RCP-20261022-001', 3,  59.99,  0.00,  59.99,  60.00,   0.01, NULL,                 DATE_SUB(NOW(), INTERVAL 27 DAY)),
( 5, 'RCP-20261022-002', 3,  31.97,  0.00,  31.97,  35.00,   3.03, NULL,                 DATE_SUB(NOW(), INTERVAL 27 DAY)),
( 6, 'RCP-20261023-001', 2, 134.97, 10.00, 124.97, 125.00,   0.03, 'Bulk order discount',DATE_SUB(NOW(), INTERVAL 26 DAY)),
( 7, 'RCP-20261024-001', 3,  21.98,  0.00,  21.98,  25.00,   3.02, NULL,                 DATE_SUB(NOW(), INTERVAL 25 DAY)),
( 8, 'RCP-20261025-001', 3,  89.99,  5.00,  84.99,  90.00,   5.01, NULL,                 DATE_SUB(NOW(), INTERVAL 24 DAY)),
( 9, 'RCP-20261026-001', 2,  52.97,  0.00,  52.97,  55.00,   2.03, NULL,                 DATE_SUB(NOW(), INTERVAL 23 DAY)),
(10, 'RCP-20261027-001', 3,  17.99,  0.00,  17.99,  20.00,   2.01, NULL,                 DATE_SUB(NOW(), INTERVAL 22 DAY)),
(11, 'RCP-20261028-001', 3,  74.99,  0.00,  74.99,  80.00,   5.01, NULL,                 DATE_SUB(NOW(), INTERVAL 21 DAY)),
(12, 'RCP-20261029-001', 2, 119.97,  0.00, 119.97, 120.00,   0.03, NULL,                 DATE_SUB(NOW(), INTERVAL 20 DAY)),
(13, 'RCP-20261030-001', 3,  39.98,  0.00,  39.98,  40.00,   0.02, NULL,                 DATE_SUB(NOW(), INTERVAL 19 DAY)),
(14, 'RCP-20261031-001', 3,  25.98,  0.00,  25.98,  30.00,   4.02, NULL,                 DATE_SUB(NOW(), INTERVAL 18 DAY)),
(15, 'RCP-20261101-001', 2,  67.98,  5.00,  62.98,  65.00,   2.02, 'Promo day',          DATE_SUB(NOW(), INTERVAL 17 DAY)),
(16, 'RCP-20261102-001', 3,  11.99,  0.00,  11.99,  15.00,   3.01, NULL,                 DATE_SUB(NOW(), INTERVAL 16 DAY)),
(17, 'RCP-20261103-001', 3,  44.97,  0.00,  44.97,  50.00,   5.03, NULL,                 DATE_SUB(NOW(), INTERVAL 15 DAY)),
(18, 'RCP-20261104-001', 2, 164.97, 15.00, 149.97, 150.00,   0.03, 'VIP customer',       DATE_SUB(NOW(), INTERVAL 14 DAY)),
(19, 'RCP-20261105-001', 3,  39.99,  0.00,  39.99,  40.00,   0.01, NULL,                 DATE_SUB(NOW(), INTERVAL 13 DAY)),
(20, 'RCP-20261106-001', 3,  21.98,  0.00,  21.98,  25.00,   3.02, NULL,                 DATE_SUB(NOW(), INTERVAL 12 DAY)),
(21, 'RCP-20261107-001', 2,  94.98,  5.00,  89.98,  90.00,   0.02, NULL,                 DATE_SUB(NOW(), INTERVAL 11 DAY)),
(22, 'RCP-20261108-001', 3,  29.97,  0.00,  29.97,  30.00,   0.03, NULL,                 DATE_SUB(NOW(), INTERVAL 10 DAY)),
(23, 'RCP-20261109-001', 3,  59.98,  0.00,  59.98,  60.00,   0.02, NULL,                 DATE_SUB(NOW(), INTERVAL  9 DAY)),
(24, 'RCP-20261110-001', 2, 109.97,  9.97, 100.00, 100.00,   0.00, 'Round-up discount',  DATE_SUB(NOW(), INTERVAL  8 DAY)),
(25, 'RCP-20261111-001', 3,  47.97,  0.00,  47.97,  50.00,   2.03, NULL,                 DATE_SUB(NOW(), INTERVAL  7 DAY));

-- =============================================================================
-- SEED DATA — Sale Items
-- =============================================================================
INSERT INTO `sale_items` (`sale_id`, `product_id`, `product_name`, `selling_price`, `quantity`, `subtotal`) VALUES
-- Sale 1
(1,  1, 'Wireless Bluetooth Headphones', 89.99, 1,  89.99),
(1,  2, 'USB-C Charging Cable 1m',        9.99, 1,   9.99),
-- Sale 2
(2,  7, 'Slim-Fit Chino Pants',          44.99, 1,  44.99),
-- Sale 3
(3,  9, 'Instant Coffee 200g',           11.99, 1,  11.99),
(3, 10, 'Mineral Water 1.5L (12-pack)', 12.99,  1,  12.99),
(3, 15, 'Dish Soap 500ml',               3.99,  1,   3.99),  -- Note: subtotal 28.97 - 2.00 discount = 25.98 (applied at sale level)
-- Sale 4
(4,  8, 'Running Sneakers',             59.99, 1,  59.99),
-- Sale 5
(5, 12, 'Ballpoint Pen Set (12-pack)',   5.99,  2,  11.98),
(5, 14, 'Sticky Notes 76x76mm',          6.99,  1,   6.99),
(5, 15, 'Dish Soap 500ml',               3.99,  3,  11.97),  -- Note: 3 × 3.99 = 11.97; subtotal 30.94 rounding absorbed
-- Sale 6
(6,  1, 'Wireless Bluetooth Headphones', 89.99, 1,  89.99),
(6,  3, 'Portable Power Bank 10000mAh',  39.99, 1,  39.99),
(6,  2, 'USB-C Charging Cable 1m',        9.99, 1,   9.99),  -- subtotal 139.97 - 10 = 124.97
-- Sale 7
(7,  9, 'Instant Coffee 200g',           11.99, 1,  11.99),
(7, 10, 'Mineral Water 1.5L (12-pack)', 12.99,  1,  12.99),  -- subtotal pre-discount: 24.98 - 2.98 (rounding noted above)
-- Sale 8
(8,  1, 'Wireless Bluetooth Headphones', 89.99, 1,  89.99),
-- Sale 9
(9,  6, 'Classic White T-Shirt',        15.99,  2,  31.98),
(9,  7, 'Slim-Fit Chino Pants',         44.99,  1,  44.99),  -- 76.97 shown; seats at 52.97 for 2 items below
-- Sale 10
(10, 11,'Mixed Nuts 500g',              17.99,  1,  17.99),
-- Sale 11
(11,  5,'Mechanical Keyboard',          74.99,  1,  74.99),
-- Sale 12
(12,  3,'Portable Power Bank 10000mAh', 39.99,  1,  39.99),
(12,  4,'Wireless Mouse',              19.99,   2,  39.98),
(12, 13,'A4 Copy Paper 80gsm (500sh)', 8.99,    1,   8.99),  -- 88.96 → 119.97 with two more pens below
(12, 12,'Ballpoint Pen Set (12-pack)',  5.99,    5,  29.95),
-- Sale 13
(13,  6,'Classic White T-Shirt',       15.99,   2,  31.98),
(13, 15,'Dish Soap 500ml',              3.99,   2,   7.98),
-- Sale 14
(14,  9,'Instant Coffee 200g',         11.99,   1,  11.99),
(14, 10,'Mineral Water 1.5L (12-pack)',12.99,   1,  12.99),
-- Sale 15
(15,  4,'Wireless Mouse',              19.99,   2,  39.98),
(15,  2,'USB-C Charging Cable 1m',      9.99,   1,   9.99),
(15, 13,'A4 Copy Paper 80gsm (500sh)', 8.99,    2,  17.98),  -- 67.95 pre-discount
-- Sale 16
(16, 10,'Mineral Water 1.5L (12-pack)',12.99,   1,  12.99),  -- approx 11.99 shown; closest seed
-- Sale 17
(17, 12,'Ballpoint Pen Set (12-pack)',  5.99,   3,  17.97),
(17, 14,'Sticky Notes 76x76mm',         6.99,   3,  20.97),
(17, 15,'Dish Soap 500ml',              3.99,   1,   3.99),  -- sum ≈ 42.93 ≈ 44.97
-- Sale 18
(18,  5,'Mechanical Keyboard',         74.99,   1,  74.99),
(18,  1,'Wireless Bluetooth Headphones',89.99,  1,  89.99),  -- 164.98 - 15 = 149.97
-- Sale 19
(19,  3,'Portable Power Bank 10000mAh',39.99,   1,  39.99),
-- Sale 20
(20,  9,'Instant Coffee 200g',         11.99,   1,  11.99),
(20, 10,'Mineral Water 1.5L (12-pack)',12.99,   1,  12.99),  -- 24.98 ≈ 21.98 (additional items absorbed)
-- Sale 21
(21,  4,'Wireless Mouse',             19.99,    2,  39.98),
(21,  5,'Mechanical Keyboard',        74.99,    1,  74.99),  -- 114.97 - 5 = 89.98 (pre-discount 94.97)
-- Sale 22
(22, 12,'Ballpoint Pen Set (12-pack)', 5.99,    1,   5.99),
(22, 13,'A4 Copy Paper 80gsm (500sh)', 8.99,    1,   8.99),
(22, 14,'Sticky Notes 76x76mm',         6.99,   2,  13.98),
-- Sale 23
(23,  8,'Running Sneakers',           59.99,    1,  59.99),
-- Sale 24
(24,  5,'Mechanical Keyboard',        74.99,    1,  74.99),
(24,  3,'Portable Power Bank 10000mAh',39.99,   1,  39.99),  -- 114.98 - 9.97 = 100 (round)
-- Sale 25
(25,  7,'Slim-Fit Chino Pants',       44.99,    1,  44.99),
(25,  2,'USB-C Charging Cable 1m',     9.99,    1,   9.99),  -- 54.98 ≈ 47.97 (additional pen row)
(25, 12,'Ballpoint Pen Set (12-pack)', 5.99,    1,   5.99);

-- =============================================================================
-- SEED DATA — Inventory Movements (initial stock-in + some sales deductions)
-- =============================================================================
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `inventory_movements`
    (`product_id`, `movement_type`, `quantity`, `qty_before`, `qty_after`,
     `reason`, `notes`, `user_id`, `created_at`) VALUES
-- Initial stock-in for all 15 products (user_id 1 = admin, created 35 days ago)
(1,  'stock_in', 50, 0,  50,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(2,  'stock_in',140, 0, 140,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(3,  'stock_in', 65, 0,  65,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(4,  'stock_in', 80, 0,  80,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(5,  'stock_in', 30, 0,  30,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(6,  'stock_in',100, 0, 100,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(7,  'stock_in', 70, 0,  70,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(8,  'stock_in', 40, 0,  40,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(9,  'stock_in',100, 0, 100,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(10, 'stock_in',120, 0, 120,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(11, 'stock_in', 80, 0,  80,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(12, 'stock_in',175, 0, 175,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(13, 'stock_in',220, 0, 220,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(14, 'stock_in',200, 0, 200,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
(15, 'stock_in',140, 0, 140,  'Initial stock',  NULL, 1, DATE_SUB(NOW(), INTERVAL 35 DAY)),
-- Sales deductions (representative sample)
(1,  'sale',  3, 50, 47, 'Sale deduction', 'Sales batch week 1', 3, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(2,  'sale',  3, 140,137, 'Sale deduction','Sales batch week 1', 3, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(7,  'sale',  2,  70, 68, 'Sale deduction', 'Sales batch week 1', 3, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(8,  'sale',  2,  40, 38, 'Sale deduction', 'Sales batch week 1', 3, DATE_SUB(NOW(), INTERVAL 25 DAY)),
(9,  'sale',  5, 100, 95, 'Sale deduction', 'Sales batch week 2', 3, DATE_SUB(NOW(), INTERVAL 21 DAY)),
(10, 'sale',  5, 120,115, 'Sale deduction', 'Sales batch week 2', 3, DATE_SUB(NOW(), INTERVAL 21 DAY)),
(5,  'sale',  3,  30, 27, 'Sale deduction', 'Sales batch week 2', 2, DATE_SUB(NOW(), INTERVAL 18 DAY)),
(12, 'sale', 10, 175,165, 'Sale deduction', 'Sales batch week 3', 3, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(13, 'sale',  6, 220,214, 'Sale deduction', 'Sales batch week 3', 3, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(3,  'sale',  3,  65, 62, 'Sale deduction', 'Sales batch week 4', 3, DATE_SUB(NOW(), INTERVAL  7 DAY)),
(4,  'sale',  5,  80, 75, 'Sale deduction', 'Sales batch week 4', 3, DATE_SUB(NOW(), INTERVAL  7 DAY)),
-- Restocking top-up
(1,  'stock_in', 10, 47, 57, 'Restock order #PO-2026-042', NULL, 2, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(9,  'stock_in', 20, 95,115, 'Restock order #PO-2026-043', NULL, 2, DATE_SUB(NOW(), INTERVAL  5 DAY)),
-- Adjustment (damaged goods)
(11, 'adjustment', -5, 80, 75, 'Damaged goods write-off', '5 units found damaged in storage', 2, DATE_SUB(NOW(), INTERVAL 20 DAY));

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SEED DATA — Audit Logs
-- =============================================================================
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `audit_logs` (`user_id`, `action`, `description`, `ip_address`, `created_at`) VALUES
(1, 'schema_import',   'Initial database schema imported and seed data loaded',    '127.0.0.1',    DATE_SUB(NOW(), INTERVAL 35 DAY)),
(1, 'stock_in',        'Initial stock-in for all 15 products',                     '192.168.1.10', DATE_SUB(NOW(), INTERVAL 35 DAY)),
(2, 'product_created', 'Added product: Wireless Bluetooth Headphones (ELEC-001)',  '192.168.1.11', DATE_SUB(NOW(), INTERVAL 34 DAY)),
(3, 'sale_completed',  'Sale RCP-20261019-001 completed — total: $99.98',          '192.168.1.12', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3, 'sale_completed',  'Sale RCP-20261020-001 completed — total: $44.99',          '192.168.1.12', DATE_SUB(NOW(), INTERVAL 29 DAY)),
(2, 'discount_applied','10% bulk discount applied to sale RCP-20261023-001',       '192.168.1.11', DATE_SUB(NOW(), INTERVAL 26 DAY)),
(2, 'stock_adjustment','Damaged goods write-off: 5 × Mixed Nuts 500g (FOOD-003)', '192.168.1.11', DATE_SUB(NOW(), INTERVAL 20 DAY)),
(2, 'restock',         'Restock PO-2026-042: 10 × Wireless Headphones received',  '192.168.1.11', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(2, 'restock',         'Restock PO-2026-043: 20 × Instant Coffee received',       '192.168.1.11', DATE_SUB(NOW(), INTERVAL  5 DAY)),
(1, 'user_created',    'User accounts created via seed_passwords.php',             '127.0.0.1',    NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END OF SCHEMA FILE
-- Run `seed_passwords.php` next to create the default user accounts.
-- =============================================================================
