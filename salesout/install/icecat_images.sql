-- Icecat product imagery: add image columns to sales_out_products
-- Run after schema.sql. Import icecat_data.xlsx via icecat_import.php to populate.

-- Add image columns (run once; omit if columns already exist)
ALTER TABLE sales_out_products ADD COLUMN image_thumb VARCHAR(500) NULL COMMENT 'Thumbnail URL (e.g. Icecat ThumbPic)';
ALTER TABLE sales_out_products ADD COLUMN image_url VARCHAR(500) NULL COMMENT 'High-res image URL (e.g. Icecat HighPic)';

-- Index for EAN lookups during Icecat import (if not exists)
-- CREATE INDEX idx_ean ON sales_out_products(ean_code);
