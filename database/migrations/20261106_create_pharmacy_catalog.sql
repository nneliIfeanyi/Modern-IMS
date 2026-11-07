CREATE TABLE IF NOT EXISTS pharmacy_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL,
    label VARCHAR(100) NULL,
    brand VARCHAR(100) NULL,
    variant VARCHAR(100) NULL,
    description VARCHAR(500) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    display_price DECIMAL(12,2) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pharmacy_catalog_published_order (is_published, sort_order, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pharmacy_catalog (
    name, category, label, brand, variant, description, image_path, display_price, is_published, sort_order
)
SELECT item.name, item.category, item.label, NULL, NULL, item.description, item.image_path, NULL, 1, item.sort_order
FROM (
    SELECT 'Paracetamol tablets' AS name, 'Pharmacy' AS category, 'Everyday care' AS label,
        'Ask for current price and availability.' AS description,
        'https://images.unsplash.com/photo-1585435557343-3b092031a831?auto=format&fit=crop&w=900&q=85' AS image_path, 5 AS sort_order
    UNION ALL SELECT 'Daily care toothpaste', 'Personal Care', 'Family essentials',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1607613009820-a29f7bb81c04?auto=format&fit=crop&w=900&q=85', 2
    UNION ALL SELECT 'Multivitamin supplement', 'Wellness', 'Daily routine',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1550572017-edd951b55104?auto=format&fit=crop&w=900&q=85', 4
    UNION ALL SELECT 'Blood pressure monitor', 'Healthcare Devices', 'Home monitoring',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1559757175-0eb30cd8c063?auto=format&fit=crop&w=900&q=85', 5
    UNION ALL SELECT 'Hand sanitizer', 'Personal Care', 'Everyday hygiene',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1584483766114-2cea6facdf57?auto=format&fit=crop&w=900&q=85', 6
    UNION ALL SELECT 'Baby care essentials', 'Baby & Maternity', 'Gentle family care',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?auto=format&fit=crop&w=900&q=85', 7
    UNION ALL SELECT 'Herbal wellness tea', 'Wellness', 'Daily routine',
        'Ask for current price and availability.',
        'https://images.unsplash.com/photo-1544787219-7f47ccb76574?auto=format&fit=crop&w=900&q=85', 8
) AS item
WHERE NOT EXISTS (
    SELECT 1 FROM pharmacy_catalog existing
    WHERE existing.name = item.name AND existing.category = item.category
);
