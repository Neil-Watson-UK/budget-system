-- Sales Out Report System - Database Schema
-- Run this against the cmmbudget database (same as budget system)

-- Raw imported sales data (standardised structure)
CREATE TABLE IF NOT EXISTS sales_out_raw (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE,
    distributor_name VARCHAR(255),
    distributor_code VARCHAR(100),
    reseller_name VARCHAR(255) COMMENT 'From report - before vendor matching',
    reseller_id VARCHAR(100),
    matched_vendor_id INT NULL COMMENT 'FK to vendors.id when matched',
    salesforce_id VARCHAR(255) NULL COMMENT 'From vendors when matched - for SF lookups',
    distributor_reference VARCHAR(100) NULL COMMENT 'Distributor''s own ref/code for the line - for de-dup and lookups when they ask',
    sku VARCHAR(100) COMMENT 'Manufacturer part number / product SKU',
    product_name VARCHAR(255),
    quantity INT DEFAULT 0,
    unit_price DECIMAL(12,2) DEFAULT 0,
    total_value DECIMAL(14,2) DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'EUR',
    region VARCHAR(50),
    country VARCHAR(50),
    source_file VARCHAR(255),
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_date (report_date),
    INDEX idx_report_distributor (report_date, distributor_name),
    INDEX idx_distributor (distributor_name),
    INDEX idx_reseller (reseller_name),
    INDEX idx_sku (sku),
    INDEX idx_matched_vendor (matched_vendor_id),
    UNIQUE KEY unique_distributor_ref (distributor_name(100), distributor_reference(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product master data (for SKU lookup / enrichment)
-- Supports EPOS_GBP_Products.csv format
CREATE TABLE IF NOT EXISTS sales_out_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE COMMENT 'Manufacturer part number',
    product_name VARCHAR(255),
    product_category VARCHAR(100),
    brand VARCHAR(100),
    msrp DECIMAL(12,2) NULL,
    currency VARCHAR(3) NULL,
    trade_price DECIMAL(12,2) NULL,
    product_status VARCHAR(50) NULL,
    product_series VARCHAR(100) NULL,
    product_line VARCHAR(100) NULL,
    product_type VARCHAR(100) NULL,
    product_sub_type VARCHAR(100) NULL,
    ean_code VARCHAR(20) NULL,
    upc_code VARCHAR(20) NULL,
    country_of_origin VARCHAR(100) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku (sku),
    INDEX idx_product_line (product_line),
    INDEX idx_product_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reseller name mapping (to standardise to known vendors)
-- Note: Requires vendors table with id column (from budget system)
CREATE TABLE IF NOT EXISTS sales_out_reseller_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reseller_name_raw VARCHAR(255) NOT NULL,
    vendor_id INT NOT NULL COMMENT 'FK to vendors.id',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reseller (reseller_name_raw),
    INDEX idx_reseller_raw (reseller_name_raw),
    INDEX idx_vendor (vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Import batches (track each upload)
CREATE TABLE IF NOT EXISTS sales_out_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255),
    distributor_name VARCHAR(255),
    row_count INT DEFAULT 0,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    imported_by INT NULL,
    INDEX idx_imported_at (imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
