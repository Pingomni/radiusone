CREATE TABLE job_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_name VARCHAR(80) NOT NULL,
 state ENUM('running','succeeded','failed','skipped') NOT NULL,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 summary_json JSON NULL,
 error_class VARCHAR(190) NULL,
 INDEX idx_job_monitor (job_name,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
