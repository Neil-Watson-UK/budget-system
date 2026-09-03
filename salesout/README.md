# Sales Out Report System

Automate importing, standardising, and analysing distributor sales reports. Aggregate to known vendors (from budget system) and enrich with product data via SKU.

## Setup

1. **Install dependencies** (PhpSpreadsheet for Excel):
   ```bash
   cd salesout
   composer install
   ```
   Or from the budget folder:
   ```bash
   cd budget
   composer require phpoffice/phpspreadsheet
   ```
   The system will use whichever `vendor/autoload.php` exists.

2. **Run database schema** (while logged into budget):
   - Go to `https://yoursite.com/budgets/salesout/install.php` (after logging in)
   - This creates: `sales_out_raw`, `sales_out_products`, `sales_out_reseller_mapping`, `sales_out_imports`

## Features

- **Import** – Upload CSV or Excel distributor reports. Columns are auto-mapped (date, distributor, reseller, SKU, product, quantity, value).
- **Vendor matching** – Reseller names are matched to `vendors` (budget) for aggregation.
- **Reseller mapping** – Map unknown reseller names to known vendors.
- **Product master** – Import product data (SKU, name, category) for enrichment.
- **Export Excel** – Download standardised data with vendor and product enrichment.
- **Dashboard** – Summary stats, top distributors, top matched resellers.

## Folder structure

```
budget/
  vendor/phpoffice/phpspreadsheet/  (or salesout/vendor/ after composer install)
  salesout/
    index.php      – Dashboard
    import.php     – Import CSV/Excel
    export.php     – Export to Excel
    products.php   – Product master (SKU lookup)
    mapping.php    – Reseller → Vendor mapping
    install.php    – Run schema
    config.php     – Uses budget config
```

## URL

Access at: `https://yoursite.com/budgets/salesout/`
