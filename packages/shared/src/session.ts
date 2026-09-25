// Mirrors docs/DEVELOPMENT_PLAN.md §7 (SQLite sessions table) and §8 (sessions table
// on the server). Timestamps are ISO 8601 strings on the wire; UTC milliseconds locally.

export type SessionType = 'APPLICATION' | 'IDLE'

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
}

export type TrackingState = 'ACTIVE' | 'IDLE' | 'PAUSED' | 'AWAY' | 'NOT_TRACKING'

