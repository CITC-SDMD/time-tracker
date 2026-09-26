-- the activity check (docs/DEVELOPMENT_PLAN.md section 16): counts of the input that happened during a session,
-- as JSON, only while the server has the detection on for this person. Never which keys, never text.
ALTER TABLE sessions ADD COLUMN input_stats TEXT;
