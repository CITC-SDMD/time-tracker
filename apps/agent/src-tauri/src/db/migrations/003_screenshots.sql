-- docs/DEVELOPMENT_PLAN.md phase 10. One row per screenshot waiting to be sent. The picture is a JPEG file
-- in the app data folder (`path`); the row and the file are deleted once the server has it.
CREATE TABLE screenshots (
  id              TEXT PRIMARY KEY,          -- UUID v7, made on the PC. Also the screenshots.id on the server (a repeat is a duplicate)
  user_id         TEXT NOT NULL,             -- only sent with this user's token
  taken_at        INTEGER NOT NULL,          -- UTC milliseconds
  path            TEXT NOT NULL,
  width           INTEGER NOT NULL,
  height          INTEGER NOT NULL,
  attempts        INTEGER NOT NULL DEFAULT 0,
  next_attempt_at INTEGER NOT NULL DEFAULT 0, -- for retry wait times
  created_at      INTEGER NOT NULL
);
CREATE INDEX idx_screenshots_ready ON screenshots (user_id, next_attempt_at, taken_at);
