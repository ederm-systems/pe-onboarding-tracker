-- Mark which categories are the practice's own work.
--
-- Tasks in a flagged category are the things we are waiting on the
-- practice for, so the shared practice page lists those, and only
-- those, as actions. A flag rather than matching a category named
-- "Customer", so renaming it does not quietly break the page and more
-- than one category can be flagged.
--
-- Paste into cPanel > phpMyAdmin > SQL if you cannot run migrate.php.

ALTER TABLE categories
  ADD COLUMN is_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER color;

-- If a category called Customer already exists, flag it.
UPDATE categories SET is_customer = 1 WHERE name = 'Customer';
