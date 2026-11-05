-- Migration: post-sale void + idempotent checkout support
-- Date: 2026-11-06
-- Applied to: posinventory

-- 1. Sales: lifecycle status (completed/voided), void metadata, and
--    idempotency key so a double-submitted checkout cannot create two sales.
ALTER TABLE sales
  ADD COLUMN status ENUM('completed','voided') NOT NULL DEFAULT 'completed' AFTER total_cost,
  ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL AFTER status,
  ADD COLUMN voided_by INT DEFAULT NULL AFTER idempotency_key,
  ADD COLUMN voided_at DATETIME DEFAULT NULL AFTER voided_by,
  ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL AFTER voided_at,
  ADD UNIQUE KEY uq_sales_idem (idempotency_key),
  ADD CONSTRAINT fk_sale_voider FOREIGN KEY (voided_by) REFERENCES users(id);

-- 2. Inventory movements: new type for stock returned by a voided sale.
ALTER TABLE inventory_movements
  MODIFY type ENUM('stock_in','sale','adjustment','void_return') NOT NULL;
