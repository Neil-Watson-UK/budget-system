-- Composite index for date + distributor filtering (insights, export)
-- Run: mysql -u user -p cmmbudget < install/add_index_report_distributor.sql

ALTER TABLE sales_out_raw
ADD INDEX idx_report_distributor (report_date, distributor_name);
