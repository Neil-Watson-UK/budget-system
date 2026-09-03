## POS & Inventory Data Exchange – Technical Specification

This document describes the standard way for distributors to send **POS / sales‑out** and **inventory** data into the EPOS reporting system.

---

### 1. Feed Types

1. **POS / Sales‑out feed** – sales data by SKU and customer.
2. **Inventory feed** – stock snapshot by SKU.

Each distributor can implement both feeds, or POS only, depending on scope.

---

### 2. Transport & Security

**Supported transports**

- **SFTP** (preferred)
- **FTPS**
- **HTTPS API** (CSV or JSON payload)

**Security requirements**

- All transfers must be encrypted (no plain FTP).
- One set of credentials (or API key) **per distributor**.
- Credentials should be rotated regularly and on personnel changes.

**To agree per distributor**

- Hostname / endpoint, port.
- Folder paths (e.g. `/incoming/POS`, `/incoming/INV`).
- Authentication method (username/password or SSH key / API key).

---

### 3. File Naming

- POS: `POS_<DistributorCode>_<YYYYMMDD>.csv`
- Inventory: `INV_<DistributorCode>_<YYYYMMDD>.csv`

Where:

- `<DistributorCode>` – short, stable identifier (e.g. `WESTCOAST`).  
- `<YYYYMMDD>` – data date in ISO format:
  - POS: last day included in the file.
  - Inventory: snapshot date.

---

### 4. POS / Sales‑Out File

**Format**

- CSV, UTF‑8, with a header row.
- Comma separator, double‑quote escaping per RFC‑4180.

**Granularity**

- Recommended: one row per **SKU per customer per day**.
- Also supported: per **transaction line**, or per **period** (week / month) if a `report_date` or `period` column indicates the period end.

**Required columns**

| Column                   | Type     | Description                                                     |
|--------------------------|----------|-----------------------------------------------------------------|
| `report_date`            | Date     | Date of sale / period end, format `YYYY-MM-DD`.                 |
| `distributor_name`       | String   | Distributor name or code (must be stable).                      |
| `distributor_account_code` | String | Internal customer account ID at distributor.                    |
| `reseller_name`          | String   | Customer / reseller name.                                      |
| `country`                | String   | Country (ISO 2/3 or free text).                                |
| `sku`                    | String   | Manufacturer part number.                                      |
| `product_name`           | String   | Product description.                                           |
| `quantity`               | Integer  | Units sold; **negative** for returns if used.                  |
| `total_value`            | Decimal  | Monetary value (prefer net of tax).                            |
| `currency`               | String   | 3‑letter ISO code, e.g. `GBP`, `EUR`.                           |

**Recommended optional columns**

- `unit_price` – price per unit (same currency as `total_value`).
- `invoice_number` – invoice / document number.
- `order_number` / `customer_po`.
- `transaction_type` – e.g. `SALE`, `RETURN`, `CREDIT`.
- `reseller_city`, `reseller_postcode`.
- `end_customer_name` – if different from billing customer.
- `salesperson` / `sales_rep`.

**Business rules**

- **Returns / credits**
  - Either:
    - Send negative `quantity` and `total_value`, or
    - Provide `transaction_type` and document how to interpret signs.
  - Use one convention consistently per distributor.
- **Dates**
  - `report_date` should reflect the **business date** (local timezone).
- **Currency**
  - Exactly one `currency` per row; do not mix currencies within a row.

---

### 5. Inventory File

**Format**

- CSV, UTF‑8, with a header row.

**Granularity**

- One row per **SKU per distributor** (or per **SKU per warehouse**, if `warehouse` is included).

**Required columns**

| Column          | Type     | Description                                                  |
|-----------------|----------|--------------------------------------------------------------|
| `snapshot_date` | Date     | Date of snapshot, `YYYY-MM-DD`.                             |
| `distributor_name` | String| Distributor name or code.                                   |
| `sku`           | String   | Manufacturer part number.                                   |
| `sku_description` | String | Product description.                                       |
| `on_hand_qty`   | Integer  | Physical units in stock.                                    |
| `unit_cost`     | Decimal  | Cost per unit.                                              |
| `inventory_value` | Decimal| Total value for this row; ideally `on_hand_qty * unit_cost`.|
| `currency`      | String   | 3‑letter ISO code, e.g. `GBP`.                              |

**Optional columns**

- `warehouse` / `location`.
- `product_category`, `product_line`, `product_family`, `product_type`.
- `source_system` / `source_file`.

**Business rules**

- Include all SKUs relevant for EPOS reporting.
- SKUs with **zero stock** can either be omitted (preferred) or included with `on_hand_qty = 0`.
- SKU codes should be consistent with those used in the POS feed.

---

### 6. Frequency & Scheduling

- **POS**
  - Daily or weekly.
  - Files delivered by an agreed cut‑off (e.g. by 03:00 local time for the previous day).

- **Inventory**
  - Weekly or aligned to the distributor’s standard inventory snapshot.

Exact schedules will be agreed and documented per distributor.

---

### 7. Validation & Acknowledgements

On import we will:

- Check file structure:
  - File readable, UTF‑8, header present.
  - Required columns.
- Validate basic types and formats:
  - `report_date` / `snapshot_date` parsable as `YYYY-MM-DD`.
  - `quantity` / `on_hand_qty` numeric.
  - `total_value`, `unit_cost`, `inventory_value` numeric.
  - `currency` non‑empty string.

We log:

- Number of rows processed.
- Number of rows rejected with high‑level reasons.

**Optional acknowledgements**

- We can write a small `ACK_<OriginalFileName>.txt` into an agreed folder, including:
  - `rows_processed`
  - `rows_rejected`
  - summary of issues (e.g. “5 rows missing sku”).

Persistent structural problems (e.g. missing columns, renamed headers) will be escalated to the distributor’s technical contact.

---

### 8. Onboarding Checklist (per Distributor)

For each distributor we will collect and record:

1. **Contacts**
   - Business owner(s).
   - Technical contact(s) for integration.

2. **Identifiers**
   - `DistributorCode` (for filenames).
   - `distributor_name` as it appears inside files.

3. **Transport**
   - SFTP/FTPS/HTTPS endpoint details.
   - Paths and any firewall requirements.

4. **Schedule**
   - POS feed frequency and delivery time.
   - Inventory feed frequency and snapshot time.

5. **Samples**
   - At least one POS sample file and one Inventory sample file with realistic data.

After a successful test import, feeds can be marked **live** and monitored as part of regular operations.

