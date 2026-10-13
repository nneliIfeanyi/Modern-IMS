-- Additive pharmacy expiry tracking. Existing aggregate stock remains unchanged.

CREATE TABLE IF NOT EXISTS inventory_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    batch_number VARCHAR(80) NOT NULL,
    expiry_date DATE NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    received_date DATE NULL,
    supplier_id INT UNSIGNED NULL,
    status ENUM('active', 'expired', 'quarantined', 'depleted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_batches_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_batches_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_batch_location (product_id, warehouse_id, batch_number),
    INDEX idx_batches_expiry (expiry_date, status, quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expiry_alerts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    recipient VARCHAR(40) NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    provider_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    CONSTRAINT fk_expiry_alert_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    UNIQUE KEY uq_expiry_alert_batch_recipient (batch_id, recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sms_batch_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sms_logs'
      AND COLUMN_NAME = 'batch_id'
);

SET @add_sms_batch_column_sql := IF(
    @sms_batch_column_exists = 0,
    'ALTER TABLE sms_logs ADD COLUMN batch_id INT UNSIGNED NULL AFTER payment_id',
    'SELECT 1'
);

PREPARE add_sms_batch_column FROM @add_sms_batch_column_sql;
EXECUTE add_sms_batch_column;
DEALLOCATE PREPARE add_sms_batch_column;

SET @sms_batch_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sms_logs'
      AND CONSTRAINT_NAME = 'fk_sms_batch'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @add_sms_batch_fk_sql := IF(
    @sms_batch_fk_exists = 0,
    'ALTER TABLE sms_logs ADD CONSTRAINT fk_sms_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE add_sms_batch_fk FROM @add_sms_batch_fk_sql;
EXECUTE add_sms_batch_fk;
DEALLOCATE PREPARE add_sms_batch_fk;

ALTER TABLE inventory_batches
    MODIFY expiry_date DATE NULL;

SET @initial_payment_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sales_orders'
      AND COLUMN_NAME = 'initial_payment'
);

SET @add_initial_payment_column_sql := IF(
    @initial_payment_column_exists = 0,
    'ALTER TABLE sales_orders ADD COLUMN initial_payment DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_mode',
    'SELECT 1'
);

PREPARE add_initial_payment_column FROM @add_initial_payment_column_sql;
EXECUTE add_initial_payment_column;
DEALLOCATE PREPARE add_initial_payment_column;

INSERT INTO inventory_batches (
    product_id, warehouse_id, batch_number, expiry_date, quantity, received_date, supplier_id
)
SELECT
    s.product_id,
    s.warehouse_id,
    CONCAT('LEGACY-', s.id),
    NULL,
    s.quantity,
    NULL,
    NULL
FROM stock s
WHERE s.quantity > 0
  AND NOT EXISTS (
      SELECT 1
      FROM inventory_batches b
      WHERE b.product_id = s.product_id
        AND b.warehouse_id = s.warehouse_id
        AND b.batch_number = CONCAT('LEGACY-', s.id)
  );