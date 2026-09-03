-- Add salesforce_id to sales_out_raw for Salesforce lookups (reseller owner, etc.)
-- Vendors table has salesforce_id; we copy it when we have a matched vendor.
-- Run: mysql -u user -p cmmbudget < install/add_salesforce_id.sql

ALTER TABLE sales_out_raw
ADD COLUMN salesforce_id VARCHAR(255) NULL COMMENT 'From vendors when matched' AFTER matched_vendor_id;

-- Backfill from vendors
UPDATE sales_out_raw s
INNER JOIN vendors v ON s.matched_vendor_id = v.id
SET s.salesforce_id = v.salesforce_id
WHERE s.matched_vendor_id IS NOT NULL;
