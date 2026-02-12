# Global Budget System

A regional budget management system for tracking spend, vendors, and reporting across multiple regions.

## Setup

1. **Copy configuration**
   ```bash
   cp config.example.php config.php
   ```
   Edit `config.php` and add your database credentials.

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure your web server** to point to this directory. Requires PHP 7.4+ with PDO MySQL.

## Features

- Dashboard with regional budget overview
- Budget item management (add, edit, import, export)
- Vendor management and matching
- Regional views and reports
- Conversion rate management
- User management (admin)

## Security Note

**Before pushing to a public GitHub repository:** Ensure `config.php` is listed in `.gitignore` (it is by default). Never commit database passwords or API keys. For production, consider using environment variables for sensitive configuration.

## Structure

- `config.php` - Database and app config (create from config.example.php)
- `header.php` / `footer.php` - Main layout
- `budget_manager.php` - Budget item CRUD
- `vendor_manager.php` - Vendor database
- `reports.php` - Reporting
- `salesout/` - Sales out module
