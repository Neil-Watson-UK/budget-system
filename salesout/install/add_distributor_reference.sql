-- Add distributor_reference column for distributor's own ref/code (lookups + alternative de-dup)
-- Run against cmmbudget database (run once)

ALTER TABLE sales_out_raw
ADD COLUMN distributor_reference VARCHAR(100) NULL COMMENT 'Distributor''s own ref/code for the line - for lookups when they ask';

ALTER TABLE sales_out_raw
ADD UNIQUE KEY unique_distributor_ref (distributor_name(100), distributor_reference(100));
