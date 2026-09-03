-- Sales Out Performance Indexes
-- Run in phpMyAdmin after deleting old data (36+ months)
-- These help report_date and filter combinations use indexes

-- Composite index for reseller report (report_date + matched_vendor_id)
ALTER TABLE sales_out_raw ADD INDEX idx_report_vendor (report_date, matched_vendor_id);

-- Composite index for unmapped reseller queries (report_date + reseller_name)
ALTER TABLE sales_out_raw ADD INDEX idx_report_reseller (report_date, reseller_name);

