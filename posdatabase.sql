-- =====================================================================
--  MARINA'S HARDWARE - POS & INVENTORY MANAGEMENT SYSTEM
--  Database Schema  :  posinventory
--  Charset          :  utf8mb4
-- =====================================================================

CREATE DATABASE IF NOT EXISTS posinventory CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE posinventory;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sale_items, sales, inventory_movements, stock_requests,
                     notifications, cashier_sessions, audit_logs, products,
                     categories, users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- USERS  (super_admin | admin | cashier)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  role          ENUM('super_admin','admin','cashier') NOT NULL DEFAULT 'cashier',
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  phone         VARCHAR(30)  DEFAULT NULL,
  last_login    DATETIME     DEFAULT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATEGORIES
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PRODUCTS
-- ---------------------------------------------------------------------
CREATE TABLE products (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  barcode       VARCHAR(60)  DEFAULT NULL UNIQUE,
  name          VARCHAR(150) NOT NULL,
  category_id   INT          NOT NULL,
  unit          VARCHAR(20)  NOT NULL DEFAULT 'pc',
  image         VARCHAR(255) NULL,
  cost_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock_qty     INT          NOT NULL DEFAULT 0,
  display_qty   INT          NOT NULL DEFAULT 0,
  reorder_level INT          NOT NULL DEFAULT 10,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CASHIER SESSIONS  (clock in / clock out)
-- ---------------------------------------------------------------------
CREATE TABLE cashier_sessions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  time_in       DATETIME NOT NULL,
  time_out      DATETIME DEFAULT NULL,
  starting_cash DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ending_cash   DECIMAL(12,2) DEFAULT NULL,
  status        ENUM('open','closed') NOT NULL DEFAULT 'open',
  CONSTRAINT fk_cs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SALES
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  receipt_no    VARCHAR(30) NOT NULL UNIQUE,
  cashier_id    INT NOT NULL,
  session_id    INT DEFAULT NULL,
  total_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  change_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('cash') NOT NULL DEFAULT 'cash',
  total_cost    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status        ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  idempotency_key VARCHAR(64) DEFAULT NULL,
  voided_by     INT DEFAULT NULL,
  voided_at     DATETIME DEFAULT NULL,
  void_reason   VARCHAR(255) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_cashier FOREIGN KEY (cashier_id) REFERENCES users(id),
  CONSTRAINT fk_sale_voider  FOREIGN KEY (voided_by)  REFERENCES users(id),
  UNIQUE KEY uq_sales_idem (idempotency_key)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  sale_id     INT NOT NULL,
  product_id  INT NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  qty         INT NOT NULL,
  unit_price  DECIMAL(12,2) NOT NULL,
  unit_cost   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  subtotal    DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_prod FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE INDEX idx_sales_created ON sales(created_at);
CREATE INDEX idx_si_product    ON sale_items(product_id);

-- ---------------------------------------------------------------------
-- INVENTORY MOVEMENTS  (in | out | adjustment)
-- ---------------------------------------------------------------------
CREATE TABLE inventory_movements (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  user_id    INT DEFAULT NULL,
  type       ENUM('stock_in','sale','adjustment','void_return') NOT NULL,
  qty        INT NOT NULL,
  notes      VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_im_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_im_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- STOCK REQUESTS  (approval needed: stock-in, price change, new product, product edit)
-- ---------------------------------------------------------------------
CREATE TABLE stock_requests (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  product_id   INT NULL,
  requested_by INT NOT NULL,
  req_type     ENUM('stock_in','price_change','damage_report','new_product','edit_product','adjust_stock') NOT NULL DEFAULT 'stock_in',
  qty          INT NOT NULL DEFAULT 0,
  new_price    DECIMAL(12,2) DEFAULT NULL,
  payload      TEXT NULL,
  reason       VARCHAR(255) DEFAULT NULL,
  status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by   INT DEFAULT NULL,
  decided_at   DATETIME DEFAULT NULL,
  decision_note VARCHAR(255) DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sr_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_sr_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS
--   audience: 'role:<role>'  or  'user:<id>'
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  audience   VARCHAR(60) NOT NULL,
  type       VARCHAR(40) NOT NULL,
  title      VARCHAR(160) NOT NULL,
  message    VARCHAR(400) NOT NULL,
  link       VARCHAR(160) DEFAULT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_notif_aud ON notifications(audience, is_read);

-- ---------------------------------------------------------------------
-- AUDIT LOGS
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT DEFAULT NULL,
  user_name  VARCHAR(120) DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,
  entity     VARCHAR(60) DEFAULT NULL,
  entity_id  INT DEFAULT NULL,
  details    VARCHAR(400) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =====================================================================
--  SEED DATA (production-clean: no demo sales, 3 accounts)
-- =====================================================================

-- Default passwords — ★ CHANGE AFTER FIRST LOGIN (Change password)!
--   superadmin / admin123   (super admin)
--   admin1     / admin123   (admin)
--   cashier1   / cashier123 (cashier)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('superadmin', '$2y$10$svf.PY5IXgpmU5lvpRfFf.gblqo9rankFR.FqN/sDITDio67ljnO2', 'Super Administrator', 'super_admin'),
('admin1',     '$2y$10$svf.PY5IXgpmU5lvpRfFf.gblqo9rankFR.FqN/sDITDio67ljnO2', 'Store Admin',        'admin'),
('cashier1',   '$2y$10$KpVdFmEPEnIPpKBAJb3Peew2dx70VNqkkT.Ii0RbsPAxac86ZUQsa', 'Maria Santos',       'cashier');

INSERT INTO categories (name) VALUES
('Tools'),('Electrical'),('Plumbing'),('Paint & Finishing'),
('Building Materials'),('Hardware & Fasteners'),('Safety & Protection');

INSERT INTO products (barcode, name, category_id, unit, cost_price, selling_price, stock_qty, reorder_level) VALUES('4800011001', 'Portland Cement 40kg',            5, 'bag',   245.00,  275.00, 120, 30),
('4800011002', 'Washed Sand 1 cu.ft',             5, 'bag',    60.00,   85.00,  60, 20),
('4800011003', 'Gravel 3/4" 1 cu.ft',             5, 'bag',    70.00,   95.00,  45, 20),
('4800011004', 'Hollow Blocks 6"',                5, 'pc',     14.00,   18.00, 500, 100),
('4800011005', '16mm Deformed Bar 6m',            5, 'pc',    320.00,  385.00,  80, 20),
('4800022001', 'Common Wire Nails 1kg',           6, 'kg',     75.00,   98.00,  90, 25),
('4800022002', 'Concrete Nails 1kg',              6, 'kg',     85.00,  110.00,  70, 20),
('4800022003', 'Hex Bolt & Nut 1/2" (set)',       6, 'set',    35.00,   55.00, 150, 40),
('4800022004', 'Wood Screws Assorted 200pcs',     6, 'box',   120.00,  165.00,  55, 15),
('4800022005', 'Staple Wire T-50',                6, 'box',    45.00,   68.00,   8, 10),
('4800033001', 'Flat Latex Paint White 4L',       4, 'can',   480.00,  595.00,  40, 12),
('4800033002', 'Enamel Paint Red 1L',             4, 'can',   185.00,  245.00,  35, 10),
('4800033003', 'Paint Roller 9"',                 4, 'pc',     65.00,   95.00,  60, 15),
('4800033004', 'Paint Brush 3"',                  4, 'pc',     35.00,   58.00,  75, 20),
('4800033005', 'Paint Thinner 1L',                4, 'can',     0.00,     0.00,   0, 10),
('4800044001', 'PVC Pipe 1/2" 3m',                3, 'pc',     95.00,  135.00, 100, 25),
('4800044002', 'PVC Elbow 1/2"',                  3, 'pc',     12.00,   25.00, 200, 50),
('4800044003', 'PVC Solvent Cement 250g',         3, 'can',     0.00,     0.00,   0, 10),
('4800044004', 'Water Closet Complete Set',       3, 'set',  2100.00, 2650.00,  12,  5),
('4800044005', 'Kitchen Faucet Stainless',        3, 'pc',    450.00,  650.00,  18,  6),
('4800055001', 'LED Bulb 9W Warm White',          2, 'pc',     55.00,   89.00, 180, 40),
('4800055002', 'Extension Cord 5m',               2, 'pc',    145.00,  215.00,  65, 15),
('4800055003', 'Circuit Breaker 20A 1P',          2, 'pc',    160.00,  235.00,  42, 12),
('4800055004', 'Electrical Wire #14 THHN (100m)', 2, 'roll',  780.00,  980.00,  25,  8),
('4800055005', 'Conduit Pipe 1/2" 3m',            2, 'pc',     68.00,  105.00,   6, 15),
('4800066001', 'Claw Hammer 16oz',                1, 'pc',    210.00,  295.00,  30,  8),
('4800066002', 'Screwdriver Set 6pcs',            1, 'set',   175.00,  265.00,  40, 10),
('4800066003', 'Adjustable Wrench 10"',           1, 'pc',    195.00,  285.00,  22,  8),
('4800066004', 'Hacksaw Frame 12"',               1, 'pc',    165.00,  245.00,  15,  6),
('4800066005', 'Measuring Tape 5m',               1, 'pc',     85.00,  135.00,   9, 12),
('4800077001', 'Safety Helmet Hard Hat',          7, 'pc',    140.00,  199.00,  28, 10),
('4800077002', 'Work Gloves Leather Pair',        7, 'pair',   55.00,   89.00,  85, 25),
('4800077003', 'Safety Goggles Clear',            7, 'pc',     65.00,  110.00,  50, 15);

-- Migrasyon: ang kasalukuyang stock ay naka-display muna sa shelves
UPDATE products SET display_qty = stock_qty;

-- BACKUP HISTORY (backup & restore module)
CREATE TABLE backup_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  file VARCHAR(255) NOT NULL,
  action VARCHAR(20) NOT NULL,                 -- backup | auto_backup | restore | safety
  type VARCHAR(20) NOT NULL DEFAULT 'manual',  -- manual | auto
  status VARCHAR(20) NOT NULL DEFAULT 'successful',
  note VARCHAR(255) NULL,
  created_by VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Initial stock-in movements for audit trail completeness
INSERT INTO inventory_movements (product_id, user_id, type, qty, notes)
SELECT p.id, 2, 'stock_in', p.stock_qty, 'Beginning inventory'
FROM products p WHERE p.stock_qty > 0;

-- (No demo notifications — the bell starts empty on a clean install.
--  Low-stock and approval alerts are generated automatically by the system.)

-- Login rate limiting (max 5 failed attempts per 60s per IP or username)
DROP TABLE IF EXISTS login_attempts;
CREATE TABLE login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(64) NOT NULL DEFAULT '',
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ip_idx (ip),
  INDEX user_idx (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
