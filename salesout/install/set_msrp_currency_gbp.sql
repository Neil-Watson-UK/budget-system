-- Set all product MSRP currency to GBP (£)
-- Table: sales_out_products (columns: msrp, currency)

-- Preview: rows that will be updated (all with non-GBP or NULL currency)
SELECT sku, product_name, msrp, currency AS current_currency, 'GBP' AS new_currency
  FROM sales_out_products
  WHERE currency IS NULL OR TRIM(currency) != 'GBP';

-- Apply: set currency to GBP for all products
UPDATE sales_out_products
   SET currency = 'GBP'
 WHERE currency IS NULL OR TRIM(currency) != 'GBP';

-- Optional: set every row to GBP regardless of current value
-- UPDATE sales_out_products SET currency = 'GBP';
