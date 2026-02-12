-- Migration: Add EPOS product list columns to sales_out_products
-- Run against cmmbudget database (e.g. via phpMyAdmin)
-- Supports EPOS_GBP_Products.csv format import
-- Run each statement; ignore "Duplicate column name" if columns already exist

ALTER TABLE sales_out_products ADD COLUMN msrp DECIMAL(12,2) NULL AFTER product_category;
ALTER TABLE sales_out_products ADD COLUMN currency VARCHAR(3) NULL AFTER msrp;
ALTER TABLE sales_out_products ADD COLUMN trade_price DECIMAL(12,2) NULL AFTER currency;
ALTER TABLE sales_out_products ADD COLUMN product_status VARCHAR(50) NULL AFTER trade_price;
ALTER TABLE sales_out_products ADD COLUMN product_series VARCHAR(100) NULL AFTER product_status;
ALTER TABLE sales_out_products ADD COLUMN product_line VARCHAR(100) NULL AFTER product_series;
ALTER TABLE sales_out_products ADD COLUMN product_type VARCHAR(100) NULL AFTER product_line;
ALTER TABLE sales_out_products ADD COLUMN product_sub_type VARCHAR(100) NULL AFTER product_type;
ALTER TABLE sales_out_products ADD COLUMN ean_code VARCHAR(20) NULL AFTER product_sub_type;
ALTER TABLE sales_out_products ADD COLUMN upc_code VARCHAR(20) NULL AFTER ean_code;
ALTER TABLE sales_out_products ADD COLUMN country_of_origin VARCHAR(100) NULL AFTER upc_code;
