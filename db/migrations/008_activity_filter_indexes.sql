-- Indexes for filtering the activity log.
--
-- The Activity screen used to read only the 300 newest rows, so it never
-- needed these. Now that its filters run across the whole table, a
-- lookup by person, action or type would otherwise scan every row, and
-- the log only ever grows.
--
-- Each index leads with the filtered column and follows with created_at,
-- because every query ends in ORDER BY created_at DESC. That lets MySQL
-- use one index for both the filtering and the sort.
--
-- Safe to run once. If you see "Duplicate key name" it has already been
-- applied and there is nothing to do.

ALTER TABLE activity_log ADD KEY ix_log_actor  (actor, created_at);
ALTER TABLE activity_log ADD KEY ix_log_action (action, created_at);
ALTER TABLE activity_log ADD KEY ix_log_entity (entity, created_at);
ALTER TABLE activity_log ADD KEY ix_log_field  (field, created_at);
