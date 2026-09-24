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

// POST /api/v1/agent/sync
export interface AgentSyncRequest {
  clientTime: string
  computerName: string
  dbReset: boolean
  status: {
    state: TrackingState
    currentApp: string | null
    idleAppName: string | null
    since: string
    trackingStartedAt: string
  }
  sessions: Session[]
}

export interface AgentSyncResponse {
  accepted: string[]
  duplicates: string[]
  rejected: Array<{ id: string; reason: string }>
  serverTime: string
  commands: {
    stopTracking: boolean
    stopReason: string | null
    signOut: boolean
  }
  settings: Pick<OfficeSettings, 'idleThresholdSeconds' | 'windowTitleMode'>
}

// GET /api/v1/employees
export interface EmployeeListItem {
  id: string
  name: string
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
