-- Add product_family column for Portfolio Mix grouping
-- Run against cmmbudget database
ALTER TABLE sales_out_products ADD COLUMN product_family VARCHAR(100) NULL AFTER product_category;
