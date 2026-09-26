// TypeScript shapes for the Laravel API (docs/DEVELOPMENT_PLAN.md §10). These mirror
// the Laravel Form Request validation rules and JSON resources by hand — there is no
// zod here (that moved server-side, into PHP) and no codegen yet, so keep this file in
// sync with apps/api whenever a request/response shape changes.

import type { Permission, PlatformPermission, Scope, UserStatus } from './permissions'
import type { Session, TrackingState } from './session'

export interface ApiError {
  error: { code: string; message: string }
}

/** what an organization's admins set: the settings of one organization */
export interface OrganizationSettings {
  timezone: string
  idleThresholdSeconds: number
  windowTitleMode: 'full' | 'app_only'
  consentVersion: number
  /** minutes between screenshots taken by the desktop apps: 0 = off, otherwise 5, 10, 15 or 30 */
  screenshotIntervalMinutes: number
  /** true = one shot at a random moment inside each block instead of on a fixed rhythm */
  screenshotRandom: boolean
}

// GET / PUT /api/v1/admin/settings (needs settings.manage): the organization settings and the space screenshots take.
export interface AdminOrganizationSettings extends OrganizationSettings {
  /** bytes used by the stored screenshots on the storage disk */
  screenshotStorageBytes: number
  /** true in the answer to a save that changed the timezone: the stored days are being recalculated in the background */
  daysRecalculating?: boolean
}

// GET /api/v1/employees/{id}/screenshots?day=YYYY-MM-DD, oldest first. The pictures themselves are
// /api/v1/screenshots/{id}/thumb and /image (same-origin, authorised by the session cookie).
export interface ScreenshotItem {
  id: string
  takenAt: string
  width: number
  height: number
}

// GET /api/v1/me
export interface Me {
  id: string
  name: string
  email: string
  status: UserStatus
  managerName: string | null
  consentVersion: number | null
  consentRequired: boolean
  /** the role the person holds in their organization; null for a superadmin */
  role: { id: string; name: string } | null
  /** what the role allows, and how far it reaches: everything the dashboard shows follows these */
  permissions: Permission[]
  scope: Scope
  isSuperadmin: boolean
  isOwner: boolean
  /** the dashboard sign-in asks for a code from an authenticator app */
  twoFactorEnabled: boolean
  /** a superadmin's platform permissions (empty for everybody else) */
  platformPermissions: PlatformPermission[]
  /** null for a superadmin, who belongs to no organization */
  organization: { id: string; name: string; timezone: string | null } | null
  settings: OrganizationSettings | null
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
  settings: Pick<OrganizationSettings, 'idleThresholdSeconds' | 'windowTitleMode' | 'screenshotIntervalMinutes' | 'screenshotRandom'>
}

// GET /api/v1/employees
/** where the desktop app says it runs */
export type Environment = 'physical' | 'virtual_machine' | 'remote_session'

/** what the activity check made of a day: a note to look into, never a verdict */
export type IntegrityLevel = 'none' | 'review' | 'strong'

export interface IntegrityReason {
  code: 'software_input' | 'input_without_hardware' | 'macro_tool_running' | 'robotic_pattern' | 'mouse_only_hours' | 'virtual_or_remote'
  /** the reason in plain words */
  message: string
  /** how many minutes it covers, when it is about a stretch of time */
  minutes: number | null
}

/** The answer to a correct password when the person has two-factor sign-in: nothing is signed in until the code is sent (POST /auth/two-factor) */
export interface TwoFactorChallenge {
  twoFactorRequired: true
  challenge: string
}

/** A PC a person is signed in on (GET /me/devices, GET /admin/employees/{id}/devices) */
export interface SignedInPc {
  deviceId: string
  computerName: string | null
  agentVersion: string | null
  lastUsedAt: string | null
  signedInAt: string
  expiresAt: string | null
}

export interface EmployeeListItem {
  id: string
  name: string
  email: string
  /** the name of their role */
  role: string
  roleId: string | null
  accountStatus: UserStatus
  /** the set-password email could not be delivered: offer "Resend link" */
  inviteFailed?: boolean
  managerId: string | null
  managerName: string | null
  createdAt: string
  status: TrackingState | 'offline'
  trackedSeconds: number
  activeSeconds: number
  idleSeconds: number
  currentApp: string | null
  lastActivityAt: string | null
  /** virtual machine detection: only sent to superadmins and the organization's admins */
  detectionEnabled?: boolean
  environment?: Environment | null
  /** today's level of the activity check (same visibility) */
  integrityLevel?: IntegrityLevel | null
}

// GET /api/v1/employees/{id}/summary
export interface DailySummary {
  /** the strongest flag the desktop app raised that day; only sent to superadmins and the organization's admins */
  environment?: Environment | null
  integrityLevel?: IntegrityLevel | null
  integrityReasons?: IntegrityReason[]
  /** known macro programs seen running that day */
  macroTools?: string[]
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
  kind: 'active' | 'idle'
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
  role: string
  roleId: string
  emailSent: boolean // a set-password link was emailed to them
  setPasswordUrl?: string // only when the email could not be sent: pass this link on yourself
}

// POST /api/v1/admin/employees/import
export interface ImportedPeople {
  created: number
  /** the set-password emails are sent in the background; one that cannot be delivered is marked on the person */
  invitesQueued: number
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

// GET /api/v1/admin/audit?cursor=&action=&q=&from=&to=
export interface AuditLogPage {
  entries: AuditLogEntry[]
  nextCursor: number | null
}

// GET /api/v1/reports/daily?from=&to=&uid=&format=json|csv — one row per person per day
export interface ReportDailyRow {
  day: string // YYYY-MM-DD, office timezone
  userId: string
  name: string
  email: string
  role: string
  trackedSeconds: number
  activeSeconds: number
  idleSeconds: number
  firstActivityAt: string | null
  lastActivityAt: string | null
}

// GET /api/v1/reports/apps — active time per application over the range
export interface ReportAppRow {
  app: string
  seconds: number
  people: number
}

// GET /api/v1/reports/team — one row per person over the range
export interface ReportTeamRow {
  userId: string
  name: string
  email: string
  role: string
  managerName: string | null
  daysTracked: number
  trackedSeconds: number
  activeSeconds: number
  idleSeconds: number
  averageTrackedSeconds: number
}

// ---- platform (superadmins) -------------------------------------------------------------------------------------

// GET /api/v1/platform/organizations and /organizations/{id}
export interface OrganizationItem {
  id: string
  name: string
  slug: string
  status: 'active' | 'suspended'
  timezone: string | null
  peopleCount: number
  adminCount: number
  /** bytes the organization's screenshots take on the storage disk */
  storageBytes: number
  createdAt: string | null
  /** only on the profile: people whose desktop app sent time in the last 7 days, and the latest upload */
  activePeopleLast7Days?: number
  lastActivityAt?: string | null
}

// GET/POST /api/v1/platform/organizations/{id}/admins
export interface OrganizationAdminItem {
  id: string
  name: string
  email: string
  status: UserStatus
  createdAt: string | null
  emailSent?: boolean
  setPasswordUrl?: string
}

// GET/POST/PATCH /api/v1/platform/superadmins
export interface SuperadminItem {
  id: string
  name: string
  email: string
  status: UserStatus
  isOwner: boolean
  permissions: PlatformPermission[]
  createdAt: string | null
  emailSent?: boolean
  setPasswordUrl?: string
}

// GET/PUT /api/v1/platform/settings
export interface PlatformSettings {
  minAgentVersion: string
}
