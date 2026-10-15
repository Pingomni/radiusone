-- Portal grant for job history retention (artisan frweb:prune-job-runs, scheduled daily). Apply as a
-- MariaDB administrator. Only DELETE on job_runs is added; no existing grant changes.
GRANT DELETE ON radius.job_runs TO 'frweb_portal'@'localhost';
