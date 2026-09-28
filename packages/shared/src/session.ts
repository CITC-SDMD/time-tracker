// Mirrors docs/DEVELOPMENT_PLAN.md §7 (SQLite sessions table) and §8 (sessions table
// on the server). Timestamps are ISO 8601 strings on the wire; UTC milliseconds locally.

export type SessionType = 'application' | 'idle'

export interface Session {
  id: string // UUID v7, made on the PC — also the server's sessions.id primary key
  type: SessionType
  appName: string | null
  processName: string | null
  windowTitle: string | null
  idleAppName: string | null
  startedAt: string
  endedAt: string
  durationSeconds: number
  clockChanged: boolean
  /** the task picked while this was tracked, or null for general/untagged time */
  taskId: string | null
}

export type TrackingState = 'active' | 'idle' | 'paused' | 'away' | 'not_tracking'

