-- Budget item file attachments
-- Run this once to create the table
CREATE TABLE IF NOT EXISTS budget_item_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_item_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    file_size INT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_item_id) REFERENCES budget_items(id) ON DELETE CASCADE,
    INDEX idx_budget_item (budget_item_id)
);
