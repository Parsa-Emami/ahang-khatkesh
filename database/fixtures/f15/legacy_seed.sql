-- Artificial F15 legacy seed for MySQL 8.4 ephemeral DBs only.
-- Assumes base Laravel auth tables already exist from migrate; this file is documentation
-- for the upgrade path. Feature tests seed via factories on SQLite; gated MySQL tests
-- recreate the same invariants programmatically when AHANG_F15_MYSQL=1.

SELECT 'f15-legacy-fixture-placeholder' AS note;
