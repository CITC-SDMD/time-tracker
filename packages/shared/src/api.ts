// TypeScript shapes for the Laravel API (docs/DEVELOPMENT_PLAN.md §10). These mirror
// the Laravel Form Request validation rules and JSON resources by hand — there is no
// zod here (that moved server-side, into PHP) and no codegen yet, so keep this file in
// sync with apps/api whenever a request/response shape changes.

import type { Role, UserStatus } from './roles'
import type { Session, TrackingState } from './session'

export interface ApiError {
  error: { code: string; message: string }
}

export interface OfficeSettings {
  timezone: string
  idleThresholdSeconds: number
  windowTitleMode: 'FULL' | 'APP_ONLY'
  minAgentVersion: string
  consentVersion: number
}

// GET /api/v1/me
export interface Me {
  id: string
  name: string
  email: string
  role: Role
  status: UserStatus
  consentVersion: number | null
  consentRequired: boolean
  officeSettings: OfficeSettings
}

// POST /api/v1/agent/sync — headers: Authorization, X-Agent-Version, X-Device-Id (UUID).
// Mirrors app/Http/Requests/AgentSyncRequest.php: at most 100 sessions (more -> 400
// TOO_MANY_SESSIONS); a bad individual session is rejected with a reason, not a 4xx.
export interface AgentSyncRequest {
  clientTime: string
  computerName: string | null
  dbReset?: boolean
  status: {
    state: TrackingState
    currentApp: string | null
    idleAppName: string | null
    since: string | null
    trackingStartedAt: string | null
  }
  sessions: Session[]
}

export type SyncRejectReason =
  | 'INVALID'
  | 'BAD_TIMES'
  | 'BAD_DURATION'
  | 'FUTURE'
  | 'TOO_OLD'
  | 'FIELD_TOO_LONG'
  | 'OTHER_DEVICE_ACTIVE'
  | 'ACCOUNT_DEACTIVATED'

export interface AgentSyncResponse {
  accepted: string[]
  duplicates: string[]
  rejected: Array<{ id: string; reason: SyncRejectReason }>
  serverTime: string
  commands: {
    stopTracking: boolean
    stopReason: 'STARTED_ON_OTHER_PC' | null
    signOut: boolean
  }
  settings: Pick<OfficeSettings, 'idleThresholdSeconds' | 'windowTitleMode'>
}

// GET /api/v1/employees
export interface EmployeeListItem {
  id: string
  name: string
  email: string
  role: Role
  accountStatus: UserStatus
  status: TrackingState | 'OFFLINE'
  trackedSeconds: number
  activeSeconds: number
  idleSeconds: number
  currentApp: string | null
  lastActivityAt: string | null
}

// GET /api/v1/employees/{id}/summary
export interface DailySummary {
  day: string // YYYY-MM-DD, office timezone
  trackedSeconds: number
  activeSeconds: number
  idleSeconds: number
  apps: Record<string, number> // appKey -> active seconds
  appNames: Record<string, string> // appKey -> friendly name
  firstActivityAt: string | null
  lastActivityAt: string | null
}

// GET /api/v1/employees/{id}/timeline?day=YYYY-MM-DD&cursor= — merged blocks of one office-timezone day
export interface TimelineSegment {
  kind: 'ACTIVE' | 'IDLE'
  label: string // app name, or "Idle (in Zoom)"
  appName: string | null
  idleAppName: string | null
  title: string | null
  startedAt: string
  endedAt: string
  durationSeconds: number
}

export interface TimelineResponse {
  day: string
  firstActivityAt: string | null
  lastActivityAt: string | null
  segments: TimelineSegment[]
  nextCursor: number | null // pass back as ?cursor= for the next page
}

// POST /api/v1/admin/employees
export interface CreatedEmployee {
  id: string
  name: string
  email: string
  role: Role
  temporaryPassword: string
}

// GET /api/v1/admin/audit
export interface AuditLogEntry {
  id: number
  actorName: string
  action: string
  targetName: string | null
  details: Record<string, unknown> | null
  at: string
}
