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
