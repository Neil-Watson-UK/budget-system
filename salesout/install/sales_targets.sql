-- Sales targets and seasonality
-- Run against cmmbudget database

-- Sales targets: annual target per distributor or reseller (vendor)
CREATE TABLE IF NOT EXISTS sales_out_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_type ENUM('distributor', 'reseller') NOT NULL COMMENT 'distributor=distributor_name, reseller=matched vendor',
    entity_key VARCHAR(255) NOT NULL COMMENT 'distributor_name when target_type=distributor, or vendor_id when target_type=reseller',
    year SMALLINT UNSIGNED NOT NULL,
    annual_target DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'GBP',
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_target (target_type, entity_key, year),
    INDEX idx_target_year (year),
    INDEX idx_target_type (target_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default seasonality (percent of annual target per month) - matches team Excel
-- Jan 5%, Feb 5%, Mar 5%, Apr 9%, May 9%, Jun 10%, Jul 9%, Aug 9%, Sep 10%, Oct 9%, Nov 10%, Dec 10%
CREATE TABLE IF NOT EXISTS sales_out_seasonality (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL DEFAULT 'default' COMMENT 'Profile name for different patterns',
    month_num TINYINT UNSIGNED NOT NULL COMMENT '1=Jan .. 12=Dec',
    pct DECIMAL(5,2) NOT NULL COMMENT 'Percent of annual target for this month',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_profile_month (name, month_num),
    INDEX idx_seasonality_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default seasonality (5,5,5,9,9,10,9,9,10,9,10,10)
INSERT IGNORE INTO sales_out_seasonality (name, month_num, pct) VALUES
('default', 1, 5), ('default', 2, 5), ('default', 3, 5), ('default', 4, 9),
('default', 5, 9), ('default', 6, 10), ('default', 7, 9), ('default', 8, 9),
('default', 9, 10), ('default', 10, 9), ('default', 11, 10), ('default', 12, 10);
