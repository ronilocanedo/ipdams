CREATE TABLE IF NOT EXISTS textbook_co_receipt_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  title_id BIGINT UNSIGNED NOT NULL,
  isbn13 CHAR(13) NOT NULL,
  expected_quantity INT UNSIGNED NOT NULL,
  delivered_quantity INT UNSIGNED NULL,
  serviceable_quantity INT UNSIGNED NULL,
  damaged_quantity INT UNSIGNED NULL,
  held_quantity INT UNSIGNED NULL,
  source_batch VARCHAR(120) NOT NULL,
  storage VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_co_receipt_isbn (receipt_id, isbn13),
  KEY idx_co_receipt_title (title_id),
  FOREIGN KEY (receipt_id) REFERENCES textbook_co_receipts(id),
  FOREIGN KEY (title_id) REFERENCES textbook_titles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
