-- Normalize region: treat "United Kingdom" as "UKI" so both appear as one region.
-- Run once: mysql -u user -p cmmbudget < salesout/install/normalize_region_uki.sql

UPDATE vendors
SET region = 'UKI'
WHERE TRIM(region) = 'United Kingdom';
