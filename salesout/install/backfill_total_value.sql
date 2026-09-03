-- Backfill total_value from trade_price × quantity
-- Run in phpMyAdmin against your budget DB (e.g. cmmbudget).
-- Rows where total_value is 0 or NULL get: total_value = quantity * trade_price (from sales_out_products).

-- Optional: preview how many rows will be updated (run this first)
SELECT COUNT(*) AS rows_to_update
FROM sales_out_raw s
INNER JOIN sales_out_products p
  ON TRIM(REPLACE(s.sku, ' ', '')) = TRIM(REPLACE(p.sku, ' ', ''))
WHERE (s.total_value IS NULL OR s.total_value = 0)
  AND s.sku IS NOT NULL AND s.sku != ''
  AND s.quantity > 0
  AND p.trade_price IS NOT NULL AND p.trade_price > 0;

-- Optional: preview a few rows (what will change)
-- SELECT s.id, s.sku, s.quantity, s.total_value AS old_value,
--        p.trade_price, (s.quantity * p.trade_price) AS new_value
-- FROM sales_out_raw s
-- INNER JOIN sales_out_products p
--   ON TRIM(REPLACE(s.sku, ' ', '')) = TRIM(REPLACE(p.sku, ' ', ''))
-- WHERE (s.total_value IS NULL OR s.total_value = 0)
--   AND s.sku IS NOT NULL AND s.sku != ''
--   AND s.quantity > 0
--   AND p.trade_price IS NOT NULL AND p.trade_price > 0
-- LIMIT 20;

-- Update en masse: set total_value = quantity × trade_price
UPDATE sales_out_raw s
INNER JOIN sales_out_products p
  ON TRIM(REPLACE(s.sku, ' ', '')) = TRIM(REPLACE(p.sku, ' ', ''))
SET s.total_value = s.quantity * p.trade_price
WHERE (s.total_value IS NULL OR s.total_value = 0)
  AND s.sku IS NOT NULL AND s.sku != ''
  AND s.quantity > 0
  AND p.trade_price IS NOT NULL AND p.trade_price > 0;
