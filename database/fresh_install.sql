-- IMS fresh deployment schema and seed data.
-- Create/select the target database first, then import this file.
-- The default application database is `ims` (see .env.example).

SET NAMES utf8mb4;

-- Complete schema.

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id)
        REFERENCES roles(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS warehouses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    location VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(60) NOT NULL UNIQUE,
    unit VARCHAR(40) NOT NULL DEFAULT 'piece',
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_stock_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    UNIQUE KEY uq_stock_product_warehouse (product_id, warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventory_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    batch_number VARCHAR(80) NOT NULL,
    expiry_date DATE NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    received_date DATE NULL,
    supplier_id INT UNSIGNED NULL,
    status ENUM('active', 'expired', 'quarantined', 'depleted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_batches_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_batches_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
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
    CONSTRAINT fk_expiry_alert_batch FOREIGN KEY (batch_id)
        REFERENCES inventory_batches(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    UNIQUE KEY uq_expiry_alert_batch_recipient (batch_id, recipient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(40) NOT NULL UNIQUE,
    supplier_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    status ENUM('draft', 'ordered', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    order_date DATE NULL,
    expected_date DATE NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_po_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty_ordered DECIMAL(12,2) NOT NULL,
    qty_received DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id)
        REFERENCES purchase_orders(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_code VARCHAR(40) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    so_number VARCHAR(40) NOT NULL UNIQUE,
    warehouse_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    customer_name VARCHAR(150) NULL,
    status ENUM('draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'draft',
    order_date DATE NULL,
    notes TEXT NULL,
    payment_mode ENUM('cash', 'credit', 'mixed') NOT NULL DEFAULT 'cash',
    initial_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_so_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_so_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_so_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    qty_ordered DECIMAL(12,2) NOT NULL,
    qty_fulfilled DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_soi_so FOREIGN KEY (sales_order_id)
        REFERENCES sales_orders(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_soi_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    warehouse_id INT UNSIGNED NOT NULL,
    movement_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    reference VARCHAR(100) NULL,
    source_warehouse_id INT UNSIGNED NULL,
    destination_warehouse_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sm_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sm_source_warehouse FOREIGN KEY (source_warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sm_destination_warehouse FOREIGN KEY (destination_warehouse_id)
        REFERENCES warehouses(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sm_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX idx_sm_created_at (created_at),
    INDEX idx_sm_type (movement_type),
    INDEX idx_sm_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('cash', 'bank_transfer', 'card', 'cheque', 'other') NOT NULL DEFAULT 'cash',
    reference VARCHAR(120) NULL,
    notes VARCHAR(255) NULL,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sop_sales_order FOREIGN KEY (sales_order_id)
        REFERENCES sales_orders(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sop_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX idx_sop_sales_order (sales_order_id),
    INDEX idx_sop_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    sales_order_id INT UNSIGNED NULL,
    sales_order_payment_id INT UNSIGNED NULL,
    entry_type ENUM('sale', 'payment', 'adjustment') NOT NULL,
    direction ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(120) NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cl_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_cl_sales_order FOREIGN KEY (sales_order_id)
        REFERENCES sales_orders(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_cl_payment FOREIGN KEY (sales_order_payment_id)
        REFERENCES sales_order_payments(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_cl_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX idx_cl_customer (customer_id),
    INDEX idx_cl_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(40) NOT NULL,
    recipient VARCHAR(40) NOT NULL,
    message VARCHAR(500) NOT NULL,
    provider_message_id VARCHAR(120) NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    provider_response TEXT NULL,
    customer_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NULL,
    payment_id INT UNSIGNED NULL,
    batch_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    CONSTRAINT fk_sms_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sms_product FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sms_payment FOREIGN KEY (payment_id)
        REFERENCES sales_order_payments(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sms_batch FOREIGN KEY (batch_id)
        REFERENCES inventory_batches(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_sms_created_by FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    INDEX idx_sms_status (status),
    INDEX idx_sms_event_type (event_type),
    INDEX idx_sms_created_at (created_at),
    INDEX idx_sms_product_created (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial data.

INSERT INTO roles (name)
VALUES ('admin'), ('staff')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO warehouses (name, location)
VALUES ('Main WH', 'Head Office')
ON DUPLICATE KEY UPDATE location = VALUES(location);

INSERT INTO categories (name, description)
VALUES
    ('Electronics', 'Electronic accessories and devices'),
    ('Furniture', 'Office and warehouse furniture'),
    ('Stationery', 'Office consumables')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO products (category_id, name, sku, unit, cost_price, selling_price, reorder_level)
VALUES
    ((SELECT id FROM categories WHERE name = 'Electronics'), 'USB-C Cable 1m', 'ELC-0142', 'piece', 850.00, 1500.00, 50),
    ((SELECT id FROM categories WHERE name = 'Furniture'), 'Office Chair - Black', 'FUR-0027', 'piece', 8000.00, 9000.00, 10),
    ((SELECT id FROM categories WHERE name = 'Stationery'), 'A4 Paper Ream', 'STA-0011', 'ream', 700.00, 1000.00, 20),
    ((SELECT id FROM categories WHERE name = 'Electronics'), 'HDMI Cable 2m', 'ELC-0158', 'piece', 1200.00, 2000.00, 15),
    ((SELECT id FROM categories WHERE name = 'Electronics'), 'Wireless Mouse', 'ELC-0201', 'piece', 3500.00, 5000.00, 12)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    unit = VALUES(unit),
    cost_price = VALUES(cost_price),
    selling_price = VALUES(selling_price),
    reorder_level = VALUES(reorder_level);

INSERT INTO users (role_id, full_name, email, password_hash, is_active)
SELECT r.id, 'System Admin', 'admin@ims.local', '$2y$10$LZ6aTOQkgbjuh52QOxfuXu/hw47vOZaqGHm05K6HQKjZEIT40kHjK', 1
FROM roles r
WHERE r.name = 'admin'
AND NOT EXISTS (
    SELECT 1 FROM users u WHERE u.email = 'admin@ims.local'
);

INSERT INTO users (role_id, full_name, email, password_hash, is_active)
SELECT r.id, 'Warehouse Staff', 'staff@ims.local', '$2y$10$LZ6aTOQkgbjuh52QOxfuXu/hw47vOZaqGHm05K6HQKjZEIT40kHjK', 1
FROM roles r
WHERE r.name = 'staff'
AND NOT EXISTS (
    SELECT 1 FROM users u WHERE u.email = 'staff@ims.local'
);

INSERT INTO suppliers (name, contact_person, email, phone, address, is_active)
SELECT 'Zenith Supplies Ltd', 'Uche Nnamdi', 'sales@zenith.ng', '08030000001', 'Lagos', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE name = 'Zenith Supplies Ltd');

INSERT INTO suppliers (name, contact_person, email, phone, address, is_active)
SELECT 'Bright Office Co.', 'Ada Umeh', 'orders@brightoffice.ng', '08060000002', 'Abuja', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE name = 'Bright Office Co.');

INSERT INTO suppliers (name, contact_person, email, phone, address, is_active)
SELECT 'Fasttrack Distributors', 'Kelvin Ude', 'info@fasttrack.ng', '08120000003', 'Port Harcourt', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE name = 'Fasttrack Distributors');

INSERT INTO customers (customer_code, full_name, phone, email, address, credit_limit, opening_balance, is_active)
SELECT 'CUST-0001', 'Walk-in Retail', NULL, NULL, 'Local counter customer', 0, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE customer_code = 'CUST-0001');

INSERT INTO customers (customer_code, full_name, phone, email, address, credit_limit, opening_balance, is_active)
SELECT 'CUST-0002', 'Premier Office Supplies', '08090000021', 'finance@premieroffice.ng', 'Lagos', 500000, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE customer_code = 'CUST-0002');
