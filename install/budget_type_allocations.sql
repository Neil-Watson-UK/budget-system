-- Run once: stores per-region, per-year percentage split across Item Types (Distributor, Reseller, End User, Other).
-- Used by Budget Planner on regional_view and allocation hints on add_item.

CREATE TABLE IF NOT EXISTS budget_type_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    region VARCHAR(32) NOT NULL,
    year SMALLINT NOT NULL,
    item_type VARCHAR(64) NOT NULL,
    pct DECIMAL(8,3) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_region_year_type (region, year, item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
