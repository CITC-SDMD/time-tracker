-- the task the employee had picked while a session was tracked (docs/DEVELOPMENT_PLAN.md), or NULL for general,
-- untagged time. Kept in the clear like `sync_status`: it is an opaque id needed to group today's timeline by
-- task, not personal data the way a window title is.
ALTER TABLE sessions ADD COLUMN task_id TEXT;
