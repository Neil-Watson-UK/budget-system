-- Budget accrual: when 1 (default), spend is attributed to activity/start/end/creation year only.
-- When 0, spend is attributed to invoiced_date year when invoiced_date is set (late invoicing into a later budget year).
ALTER TABLE budget_items
  ADD COLUMN budget_accrual_approved TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1=activity-year budget; 0=use invoice year when invoiced_date set';
