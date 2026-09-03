-- Inventory snapshot table for distributor stock levels
-- Run after schema.sql. Import via inventory_import.php

CREATE TABLE IF NOT EXISTS sales_out_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL COMMENT 'Report/snapshot date from file',
    distributor_name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    sku_description VARCHAR(255) NULL,
    on_hand_qty INT DEFAULT 0,
    unit_cost DECIMAL(12,2) DEFAULT 0,
    inventory_value DECIMAL(14,2) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'GBP',
    source_file VARCHAR(255) NULL,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_date (snapshot_date),
    INDEX idx_inv_distributor (distributor_name),
    INDEX idx_inv_sku (sku),
    INDEX idx_inv_dist_sku_date (distributor_name(100), sku(50), snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
