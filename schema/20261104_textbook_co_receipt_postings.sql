CREATE TABLE IF NOT EXISTS textbook_co_receipt_postings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_line_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  condition_kind VARCHAR(16) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_co_posting_line_condition (receipt_line_id, condition_kind),
  UNIQUE KEY uq_co_posting_batch (batch_id),
  FOREIGN KEY (receipt_line_id) REFERENCES textbook_co_receipt_lines(id),
  FOREIGN KEY (batch_id) REFERENCES textbook_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
