-- Run once on the existing Your Good Time MySQL/MariaDB database.
-- Keep the product foreign key while making the reference optional for Past Prediction.
SET @ygt_previous_foreign_key_checks = @@SESSION.FOREIGN_KEY_CHECKS;
SET SESSION FOREIGN_KEY_CHECKS = 0;

ALTER TABLE future_video_manual_payments
    MODIFY COLUMN product_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN payment_proof_path VARCHAR(255) NULL,
    ADD COLUMN transaction_reference VARCHAR(120) NULL,
    ADD UNIQUE KEY prediction_manual_transaction_reference_unique (transaction_reference);

SET SESSION FOREIGN_KEY_CHECKS = @ygt_previous_foreign_key_checks;

INSERT INTO migrations (migration, batch)
SELECT '2026_11_27_000001_extend_prediction_manual_payments', batches.next_batch
FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM migrations) AS batches
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migration = '2026_11_27_000001_extend_prediction_manual_payments'
);
