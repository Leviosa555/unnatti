-- =====================================================
-- Unnatti Enterprises Mini ERP - Database Schema
-- Version: 1.1 | Updated: 2024
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `mini_erp`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `mini_erp`;

-- =====================================================
-- TABLE: users
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `employee_code`        VARCHAR(20)   UNIQUE,
  `full_name`            VARCHAR(100)  NOT NULL,
  `username`             VARCHAR(50)   UNIQUE NOT NULL,
  `password`             VARCHAR(255)  NOT NULL,
  `email`                VARCHAR(100)  DEFAULT NULL,
  `mobile`               VARCHAR(15)   DEFAULT NULL,
  `address`              TEXT,
  `role`                 ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `joining_date`         DATE          DEFAULT NULL,
  `can_enter_stock`      TINYINT(1)    NOT NULL DEFAULT 1,
  `can_apply_discount`   TINYINT(1)    NOT NULL DEFAULT 0,
  `max_discount_pct`     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `last_login`           DATETIME      DEFAULT NULL,
  `created_by`           INT           DEFAULT NULL,
  `created_at`           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: categories
-- =====================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100)  NOT NULL,
  `parent_id`   INT           DEFAULT NULL,
  `description` TEXT,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: products
-- =====================================================
CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `category_id`    INT    DEFAULT NULL,
  `subcategory_id` INT    DEFAULT NULL,
  `name`           VARCHAR(200) NOT NULL,
  `brand`          VARCHAR(100) DEFAULT NULL,
  `description`    TEXT,
  `image`          VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT    DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`)    REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`subcategory_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: product_variants
-- =====================================================
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`                       INT AUTO_INCREMENT PRIMARY KEY,
  `product_id`               INT          NOT NULL,
  `sku`                      VARCHAR(50)  UNIQUE NOT NULL,
  `size`                     VARCHAR(50)  NOT NULL DEFAULT 'Standard',
  `purchase_price`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_gst_pct`         DECIMAL(5,2)  NOT NULL DEFAULT 5.00,
  `purchase_gst_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_price_with_gst`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `profit_markup_pct`        DECIMAL(5,2)  NOT NULL DEFAULT 65.00,
  `profit_amount`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price_before_gst` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sales_gst_pct`            DECIMAL(5,2)  NOT NULL DEFAULT 5.00,
  `sales_gst_amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tag_price`                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `current_stock`            INT           NOT NULL DEFAULT 0,
  `min_stock_alert`          INT           NOT NULL DEFAULT 5,
  `status`                   ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  INDEX `idx_sku`        (`sku`),
  INDEX `idx_product_id` (`product_id`),
  INDEX `idx_stock`      (`current_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: suppliers
-- =====================================================
CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(100) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `mobile`         VARCHAR(15)  DEFAULT NULL,
  `email`          VARCHAR(100) DEFAULT NULL,
  `address`        TEXT,
  `gstin`          VARCHAR(20)  DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: purchases
-- =====================================================
CREATE TABLE IF NOT EXISTS `purchases` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_no`         VARCHAR(50)  UNIQUE NOT NULL,
  `purchase_date`       DATE         NOT NULL,
  `supplier_id`         INT          DEFAULT NULL,
  `supplier_name`       VARCHAR(100) DEFAULT NULL,
  `supplier_invoice_no` VARCHAR(100) DEFAULT NULL,
  `total_items`         INT          NOT NULL DEFAULT 0,
  `total_qty`           INT          NOT NULL DEFAULT 0,
  `total_amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes`               TEXT,
  `status`              ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by`          INT          NOT NULL,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: purchase_items
-- =====================================================
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id`                       INT AUTO_INCREMENT PRIMARY KEY,
  `purchase_id`              INT          NOT NULL,
  `variant_id`               INT          NOT NULL,
  `qty`                      INT          NOT NULL,
  `purchase_price`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_gst_pct`         DECIMAL(5,2)  NOT NULL DEFAULT 5.00,
  `purchase_gst_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `purchase_price_with_gst`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `remaining_qty`            INT          NOT NULL DEFAULT 0,
  `created_at`               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`)        ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`)  REFERENCES `product_variants`(`id`),
  INDEX `idx_pi_variant`  (`variant_id`),
  INDEX `idx_pi_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: stock_movements
-- =====================================================
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `variant_id`     INT          NOT NULL,
  `movement_type`  ENUM('purchase','sale','return','adjustment','cancellation') NOT NULL,
  `qty_change`     INT          NOT NULL COMMENT 'Positive=IN, Negative=OUT',
  `qty_before`     INT          NOT NULL,
  `qty_after`      INT          NOT NULL,
  `reference_id`   INT          DEFAULT NULL,
  `reference_type` VARCHAR(50)  DEFAULT NULL,
  `notes`          TEXT,
  `created_by`     INT          DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_sm_variant` (`variant_id`, `created_at`),
  INDEX `idx_sm_type`    (`movement_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: customers
-- =====================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `name`                VARCHAR(100)  NOT NULL,
  `mobile`              VARCHAR(15)   DEFAULT NULL,
  `email`               VARCHAR(100)  DEFAULT NULL,
  `address`             TEXT,
  `gstin`               VARCHAR(20)   DEFAULT NULL,
  `customer_type`       ENUM('retail','wholesale','walk-in') NOT NULL DEFAULT 'retail',
  `outstanding_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_purchases`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes`               TEXT,
  `created_by`          INT           DEFAULT NULL,
  `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_cust_mobile` (`mobile`),
  INDEX `idx_cust_name`   (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: sales (bill header)
-- =====================================================
CREATE TABLE IF NOT EXISTS `sales` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_no`         VARCHAR(50)  UNIQUE NOT NULL,
  `sale_date`          DATE         NOT NULL,
  `customer_id`        INT          DEFAULT NULL,
  `customer_name`      VARCHAR(100) NOT NULL DEFAULT 'Walk-In Customer',
  `customer_mobile`    VARCHAR(15)  DEFAULT NULL,
  `customer_address`   TEXT,
  `subtotal`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `additional_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `grand_total`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_received`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_due`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status`     ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid',
  `payment_method`     ENUM('cash','upi','card','bank_transfer','credit','mixed') NOT NULL DEFAULT 'cash',
  `status`             ENUM('active','returned','cancelled') NOT NULL DEFAULT 'active',
  `notes`              TEXT,
  `created_by`         INT          NOT NULL,
  `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`),
  INDEX `idx_sale_date`    (`sale_date`),
  INDEX `idx_invoice_no`   (`invoice_no`),
  INDEX `idx_sale_customer` (`customer_id`),
  INDEX `idx_sale_emp`     (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: sale_items
-- =====================================================
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`                       INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id`                  INT          NOT NULL,
  `variant_id`               INT          DEFAULT NULL,
  `sku_snapshot`             VARCHAR(50)  NOT NULL,
  `product_name_snapshot`    VARCHAR(200) NOT NULL,
  `size_snapshot`            VARCHAR(50)  DEFAULT NULL,
  `qty`                      INT          NOT NULL,
  `purchase_cost_snapshot`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price_snapshot`   DECIMAL(10,2) NOT NULL,
  `discount_amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `gst_pct_snapshot`         DECIMAL(5,2)  NOT NULL DEFAULT 5.00,
  `gst_amount`               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total`               DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`sale_id`)    REFERENCES `sales`(`id`)            ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE SET NULL,
  INDEX `idx_si_sale`    (`sale_id`),
  INDEX `idx_si_variant` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: payments
-- =====================================================
CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id`        INT          NOT NULL,
  `payment_method` ENUM('cash','upi','card','bank_transfer','credit') NOT NULL,
  `amount`         DECIMAL(12,2) NOT NULL,
  `payment_date`   DATE         NOT NULL,
  `reference_no`   VARCHAR(100) DEFAULT NULL,
  `notes`          TEXT,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: sales_returns
-- =====================================================
CREATE TABLE IF NOT EXISTS `sales_returns` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `return_no`     VARCHAR(50)  UNIQUE NOT NULL,
  `sale_id`       INT          NOT NULL,
  `return_reason` TEXT,
  `return_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_by`    INT          NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`)    REFERENCES `sales`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: sales_return_items
-- =====================================================
CREATE TABLE IF NOT EXISTS `sales_return_items` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `return_id`     INT          NOT NULL,
  `sale_item_id`  INT          NOT NULL,
  `variant_id`    INT          DEFAULT NULL,
  `qty_returned`  INT          NOT NULL,
  `refund_amount` DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (`return_id`)    REFERENCES `sales_returns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items`(`id`),
  FOREIGN KEY (`variant_id`)   REFERENCES `product_variants`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: bill_cancellations
-- =====================================================
CREATE TABLE IF NOT EXISTS `bill_cancellations` (
  `id`                   INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id`              INT  NOT NULL,
  `cancellation_reason`  TEXT NOT NULL,
  `cancelled_by`         INT  NOT NULL,
  `stock_reversed`       TINYINT(1) NOT NULL DEFAULT 1,
  `cancelled_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`)      REFERENCES `sales`(`id`),
  FOREIGN KEY (`cancelled_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: stock_adjustments
-- =====================================================
CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `adjustment_no`   VARCHAR(50)  UNIQUE NOT NULL,
  `variant_id`      INT          NOT NULL,
  `adjustment_type` ENUM('add','subtract') NOT NULL,
  `qty_change`      INT          NOT NULL,
  `reason`          TEXT         NOT NULL,
  `created_by`      INT          NOT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`variant_id`)  REFERENCES `product_variants`(`id`),
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: settings
-- =====================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) UNIQUE NOT NULL,
  `setting_value` TEXT,
  `setting_group` VARCHAR(50)  DEFAULT 'general',
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: activity_logs
-- =====================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT          DEFAULT NULL,
  `action`         VARCHAR(500) NOT NULL,
  `module`         VARCHAR(50)  DEFAULT NULL,
  `reference_id`   INT          DEFAULT NULL,
  `reference_type` VARCHAR(50)  DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_log_user`    (`user_id`),
  INDEX `idx_log_module`  (`module`),
  INDEX `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- SEED DATA: Default Admin
-- Password: admin123 (hashed with password_hash)
-- =====================================================
INSERT IGNORE INTO `users`
  (`full_name`, `username`, `password`, `email`, `mobile`, `role`, `status`, `can_enter_stock`, `can_apply_discount`, `max_discount_pct`)
VALUES
  ('Super Admin', 'admin', '$2y$12$3FxbAivDDiAk/3rr79dSeeAoeywUFalc20yGCHzMZRLAUCIQGAj/6', 'admin@unnatti.com', '9999999999', 'admin', 'active', 1, 1, 100.00);

-- =====================================================
-- SEED DATA: Categories
-- =====================================================
INSERT IGNORE INTO `categories` (`name`, `description`, `status`) VALUES
('Clothing',    'Apparel and garments',          'active'),
('Footwear',    'Shoes, sandals, and footwear',   'active'),
('Accessories', 'Bags, belts, and accessories',   'active');

INSERT IGNORE INTO `categories` (`name`, `parent_id`, `status`) VALUES
("Men's Clothing",   1, 'active'),
("Women's Clothing", 1, 'active'),
("Kids' Clothing",   1, 'active'),
("Men's Footwear",   2, 'active'),
("Women's Footwear", 2, 'active'),
('Bags',             3, 'active'),
('Belts',            3, 'active');

-- =====================================================
-- SEED DATA: Default Settings
-- =====================================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_group`) VALUES
-- Business Info
('business_name',              'Unnatti Enterprises',                                        'business'),
('business_tagline',           'Quality Products, Trusted Service',                          'business'),
('business_address',           '123 Main Street, City, State - 000000',                      'business'),
('business_phone',             '+91 99999 99999',                                            'business'),
('business_email',             'info@unnatti.com',                                           'business'),
('business_gstin',             '27XXXXX0000X1Z5',                                           'business'),
('business_logo',              '',                                                           'business'),
('invoice_prefix',             'INV',                                                        'business'),
('invoice_footer',             'Thank you for your business! Visit us again.',               'business'),
('terms_conditions',           'Goods once sold will not be taken back. Subject to local jurisdiction.', 'business'),
-- Pricing
('purchase_gst_pct',           '5',                                                          'pricing'),
('sales_gst_pct',              '5',                                                          'pricing'),
('default_profit_markup_pct',  '65',                                                         'pricing'),
('default_min_stock_alert',    '5',                                                          'pricing'),
('max_discount_pct',           '20',                                                         'pricing'),
('rounding_rule',              'round',                                                      'pricing'),
-- System
('currency',                   'INR',                                                        'system'),
('currency_symbol',            '₹',                                                          'system'),
('date_format',                'd/m/Y',                                                      'system'),
('timezone',                   'Asia/Kolkata',                                               'system'),
('session_timeout',            '120',                                                        'system'),
-- Invoice
('invoice_size',               'A4',                                                         'invoice'),
('show_gstin_on_invoice',      '1',                                                          'invoice'),
('show_signature_on_invoice',  '1',                                                          'invoice');

-- =====================================================
-- TABLE: material_consumptions (Production / Cutting)
-- =====================================================
CREATE TABLE IF NOT EXISTS `material_consumptions` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `consumption_no`      VARCHAR(50)  UNIQUE NOT NULL,
  `production_date`     DATE         NOT NULL,
  `garment_type`        VARCHAR(100) NOT NULL,
  `units_produced`      INT          NOT NULL DEFAULT 0,
  `finished_product_id` INT          DEFAULT NULL,
  `finished_variant_id` INT          DEFAULT NULL,
  `tailor_name`         VARCHAR(100) DEFAULT NULL,
  `total_fabric_meters` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes`               TEXT,
  `created_by`          INT          DEFAULT NULL,
  `created_at`          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX (`production_date`),
  INDEX (`garment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: material_consumption_items (Used materials breakdown)
-- =====================================================
CREATE TABLE IF NOT EXISTS `material_consumption_items` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `consumption_id` INT          NOT NULL,
  `variant_id`     INT          NOT NULL,
  `material_role`  VARCHAR(50)  DEFAULT 'fabric',
  `qty_used`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit`           VARCHAR(20)  DEFAULT 'meters',
  `notes`          VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`consumption_id`) REFERENCES `material_consumptions`(`id`) ON DELETE CASCADE,
  INDEX (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA: Default Walk-In Customer
-- =====================================================
INSERT IGNORE INTO `customers` (`name`, `mobile`, `customer_type`, `status`) VALUES
('Walk-In Customer', '0000000000', 'walk-in', 'active');
