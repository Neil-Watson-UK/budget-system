# Vendor API – Budget & SalesOut Integration

This document describes how the budget/salesout system expects the **Vendor API** (emailpos) to behave for live vendor sync.

---

## Endpoint

- **Method:** `GET`
- **Path:** `/vendors`
- **Base URL:** configure in `VENDOR_API_URL` (see `config.php`), e.g.:
  - `https://emailpos-api.example.com/vendors`

The budget system calls `VENDOR_API_URL` as a simple HTTP GET and expects a JSON array of vendor objects.

---

## Expected JSON shape

The response MUST be a JSON array, for example:

```json
[
  {
    "salesforce_id": "001XXXXXXXXXXXXXXX",
    "vendor_name":   "Exertis UK",
    "region":        "UKI",
    "account_type":  "Reseller",
    "amplify_level": "Gold",
    "account_status": "Active",
    "owner_full_name": "Jane Smith",
    "type_value":    "Channel Partner"
  }
]
```

### Required fields

- **`vendor_name`** (string): Display name of the vendor / account.
- **`salesforce_id`** (string, 18 chars, optional but strongly recommended): Salesforce Account Id.
- **`region`** (string): Budget region code (e.g. `UKI`, `DACH`, `AMER`, etc.).

### Optional fields (used when present)

- **`account_type`** (string): Maps to `vendors.account_type`.
- **`amplify_level`** (string): Maps to `vendors.AMPLIFY_Level__c` when that column exists.
- **`account_status`** (string): Maps to `vendors.Account_Status__c` when that column exists.
- **`owner_full_name`** (string): Maps to `vendors.Owner_Full_Name__c` when that column exists.
- **`type_value`** (string): Maps to `vendors.Type_value__c` when that column exists.

Any extra fields are ignored by the sync script.

---

## How the sync works (summary)

- Script: `sync_vendors_from_api.php` (CLI – run from the budget root).
- Config:
  - Set `VENDOR_API_URL` (and optionally `VENDOR_API_KEY`) in `config.php` or a local override.
- Behaviour:
  - Fetches JSON from `VENDOR_API_URL` with `Accept: application/json`.
  - If `VENDOR_API_KEY` is non-empty, sends `Authorization: Bearer {VENDOR_API_KEY}`.
  - For each row:
    - Tries to find an existing vendor by `salesforce_id`, falling back to case-insensitive `vendor_name`.
    - **If found:** updates core fields and any optional fields where the corresponding DB columns exist.
    - **If not found:** inserts a new vendor row.

Run from CLI:

```bash
php sync_vendors_from_api.php
```

This will print counts of inserted / updated / skipped records.

