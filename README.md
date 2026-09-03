# Global Budget System

Regional budget management for tracking spend, vendors, and reporting, with a Sales Out module under `salesout/`.

## Setup

1. Copy configuration:
   ```bash
   cp config.example.php config.php
   cp salesout/config.example.php salesout/config.php
   ```
   Edit both files with database credentials and optional API keys. Never commit them.

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Point the web server at this directory. PHP 8.1+ with PDO MySQL.

## Security

`config.php` and `salesout/config.php` are gitignored. If `EXCEL_API_KEY` is set, `excel_api.php` requires `?key=` to match. Leave it empty to keep that endpoint disabled.

Rotate any database or API credentials that were previously stored in source files.

## Layout

- `index.php` / `login.php` — dashboard and auth
- `budget_manager.php` — budget item CRUD
- `vendor_manager.php` — vendor database
- `reports.php` / `regional_view.php` — reporting
- `salesout/` — distributor sales-out, inventory, products
