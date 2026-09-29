-- A shareable read-only link per practice, for the practice's own
-- representative, who has no account here.
--
-- The link is the credential, so the token is long and random rather
-- than the practice id, which would be guessable by counting. A link
-- exists only once someone creates one, and can be revoked by clearing
-- the token or replaced by generating a new one.
--
-- Paste into cPanel > phpMyAdmin > SQL if you cannot run migrate.php.

ALTER TABLE practices
  ADD COLUMN share_token CHAR(32) DEFAULT NULL AFTER notes,
  ADD COLUMN share_created_at DATETIME DEFAULT NULL AFTER share_token;

ALTER TABLE practices
  ADD UNIQUE KEY uq_practices_share_token (share_token);
