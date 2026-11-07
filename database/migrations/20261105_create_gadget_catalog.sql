CREATE TABLE IF NOT EXISTS gadget_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL,
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    description VARCHAR(500) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    display_price DECIMAL(12,2) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gadget_catalog_published_order (is_published, sort_order, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO gadget_catalog (
    name, category, brand, model, description, image_path, display_price, is_published, sort_order
)
SELECT item.name, item.category, NULL, NULL, item.description, item.image_path, NULL, 1, item.sort_order
FROM (
    SELECT 'Smart Television' AS name, 'TVs & Displays' AS category,
        'Ask about available screen sizes, features and current price.' AS description,
        'https://images.unsplash.com/photo-1593359677879-a4bb92f829d1?auto=format&fit=crop&w=900&q=85' AS image_path,
        1 AS sort_order
    UNION ALL SELECT 'Double-Door Refrigerator', 'Refrigerators',
        'Ask about available sizes, configurations and current price.',
        'https://images.unsplash.com/photo-1584568694244-14fbdf83bd30?auto=format&fit=crop&w=900&q=85', 2
    UNION ALL SELECT 'Automatic Washing Machine', 'Washing Machines',
        'Ask about available capacities, features and current price.',
        'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?auto=format&fit=crop&w=900&q=85', 3
    UNION ALL SELECT 'Home Theatre System', 'Home Theatre',
        'Ask about available speaker setups, features and current price.',
        'https://images.unsplash.com/photo-1593359677879-a4bb92f829d1?auto=format&fit=crop&w=900&q=85', 4
    UNION ALL SELECT 'Kitchen Blender', 'Kitchen Appliances',
        'Ask about available models, features and current price.',
        'https://images.unsplash.com/photo-1570222094114-d054a817e56b?auto=format&fit=crop&w=900&q=85', 5
) AS item
WHERE NOT EXISTS (
    SELECT 1 FROM gadget_catalog existing
    WHERE existing.name = item.name AND existing.category = item.category
);
