## Data Exchange – POS & Inventory (Distributor Summary)

### Purpose

We’d like to receive **regular sales‑out (POS)** and **inventory** data from you so we can:

- Give EPOS clear visibility of **what’s selling, where, and at what value**.
- Show **current stock levels and weeks of stock** on key products.
- Reduce manual reporting and one‑off spreadsheet requests.

### What we need from you

You provide **two simple data files** on a regular schedule:

1. **POS / Sales‑Out file**
   - A list of what you have sold (per product and customer) for a given period.
   - Frequency: ideally **daily**, minimum **weekly**.
   - Each row should include (at minimum):
     - **Date of sale**  
     - Your **distributor name/code**  
     - Your **customer account code** and **customer name**  
     - Product **SKU** (manufacturer part number) and **product name**  
     - **Quantity sold**  
     - **Total value** (net of tax if possible) and **currency**
   - Returns / credits can be sent as **negative quantity and negative value**.

2. **Inventory file**
   - A **snapshot** of your stock per product.
   - Frequency: at least **weekly** (e.g. every Monday), or aligned to your internal stock snapshot.
   - Each row should include:
     - **Snapshot date**  
     - Your **distributor name/code**  
     - Product **SKU** and **description**  
     - **On‑hand quantity**  
     - **Unit cost** and **total inventory value** (or we can calculate from qty × cost)  
     - **Currency**

### How to send the files

We support secure, automated delivery:

- **Preferred**
  - You **upload** the files to our secure **SFTP/FTPS** server, or  
  - We **download** the files from your **SFTP/FTPS** location.

- **Alternative**
  - Send via a secure **HTTPS API** (CSV/JSON) if you already expose one.

We’ll agree one method per distributor and provide / accept credentials as needed.

### File format

- **CSV** with a **header row** is ideal (Excel also acceptable if columns are clearly labelled).
- Stable **file names**, for example:
  - `POS_<YourCode>_<YYYYMMDD>.csv`
  - `INV_<YourCode>_<YYYYMMDD>.csv`
- Encoding: **UTF‑8**.

### What we do with the data

- Load it automatically into our reporting system.
- Use it only for **EPOS analytics and reporting**.
- Provide you (and EPOS) with visibility via dashboards and exports where appropriate.

