-- A third role: Onboarding Specialist.
--
-- Sits between a team member and the administrator. May add and edit
-- practices, shape the task library, and move any task forward on any
-- practice. May not delete, deactivate or archive anything, and cannot
-- reach Products, Categories, People or the Archive.
--
-- Paste into cPanel > phpMyAdmin > SQL if you cannot run migrate.php.

ALTER TABLE assignees
  ADD COLUMN access_level VARCHAR(20) NOT NULL DEFAULT 'member' AFTER role_title;

-- Everyone keeps the access they have today. Promote people on the
-- People screen, not here.
UPDATE assignees SET access_level = 'member' WHERE access_level = '';
