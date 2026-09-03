-- Backfill trade_price from MSRP (standard 45% discount → trade = 55% of MSRP)
-- Run in phpMyAdmin against your budget DB. Then run backfill_total_value.sql to fill sales_out_raw.total_value.

-- Preview: products that will get trade_price = msrp * 0.55
SELECT sku, product_name, msrp, trade_price AS current_trade,
       ROUND(msrp * 0.55, 2) AS new_trade
FROM sales_out_products
WHERE (trade_price IS NULL OR trade_price = 0)
  AND msrp IS NOT NULL AND msrp > 0
LIMIT 50;

-- Optional: count how many rows will be updated
-- SELECT COUNT(*) AS products_to_update
-- FROM sales_out_products
-- WHERE (trade_price IS NULL OR trade_price = 0)
--   AND msrp IS NOT NULL AND msrp > 0;

-- Update: set trade_price = 55% of MSRP where trade_price is missing
UPDATE sales_out_products
SET trade_price = ROUND(msrp * 0.55, 2)
WHERE (trade_price IS NULL OR trade_price = 0)
  AND msrp IS NOT NULL AND msrp > 0;
