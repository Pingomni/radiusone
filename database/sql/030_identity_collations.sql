-- One collation for every table keyed by a RADIUS username or holding one: utf8mb4_unicode_ci, as customer_profiles
-- and the reservation tables. These were created without one and took the database default, so two usernames
-- could be one key here and two customers there (access_policies), or a username could not be stored at all
-- (usage_daily on a database whose default is latin1).
ALTER TABLE access_policies CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usage_daily CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usage_session_marks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE usage_cycles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE nas_access_profiles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE billing_accounts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE policy_revisions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE network_operations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE address_assignments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
