import { ROLE_LABEL, type AuditLogEntry, type EmployeeListItem, type Role, type UserStatus } from 'shared'

// The words and colours the dashboard uses for the values the API sends as snake_case codes.

export type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary'

type LiveStatus = EmployeeListItem['status']

export const LIVE_STATUS_LABEL: Record<LiveStatus, string> = {
  active: 'Tracking',
  idle: 'Idle',
  paused: 'Paused',
  away: 'Away',
  not_tracking: 'Not tracking',
  offline: 'Offline',
}

export const LIVE_STATUS_VARIANT: Record<LiveStatus, BadgeVariant> = {
  active: 'success',
  idle: 'warning',
  paused: 'info',
  away: 'neutral',
  not_tracking: 'neutral',
  offline: 'danger',
}

export const ACCOUNT_STATUS_LABEL: Record<UserStatus, string> = {
  active: 'Active',
  inactive: 'Deactivated',
}

export const ACCOUNT_STATUS_VARIANT: Record<UserStatus, BadgeVariant> = {
  active: 'success',
  inactive: 'neutral',
}

// Every action the API writes to the audit log (apps/api: AuditLog::record).
export const AUDIT_ACTION_LABEL: Record<string, string> = {
  'employee.created': 'Added an account',
  'employee.deactivated': 'Deactivated an account',
  'employee.reactivated': 'Reactivated an account',
  'employee.deleted': 'Deleted an account',
  'employee.moved': 'Moved a person to another manager',
  'employee.invite_resent': 'Sent a new set-password link',
  'settings.updated': 'Changed office settings',
  'timeline.viewed': 'Viewed a timeline',
  'screenshots.viewed': 'Viewed screenshots',
  'password.reset': 'Reset a password with an emailed link',
  'password.changed': 'Changed their password',
  'report.exported': 'Downloaded a report',
}

export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABEL[action] ?? action
}

const SETTING_LABEL: Record<string, string> = {
  timezone: 'timezone',
  idleThresholdSeconds: 'idle limit',
  windowTitleMode: 'window titles',
  minAgentVersion: 'minimum app version',
  consentVersion: 'consent version',
  screenshotIntervalMinutes: 'screenshot interval (minutes)',
  screenshotRandom: 'screenshots at a random moment',
}

/** The part of an audit entry after the action, in words ("Pat A → Pam B", "idle limit → 600"). */
export function auditDetails(entry: Pick<AuditLogEntry, 'action' | 'details'>): string {
  const d = entry.details ?? {}
  switch (entry.action) {
    case 'employee.created':
    case 'employee.deleted':
      return typeof d.role === 'string' ? (ROLE_LABEL[d.role as Role] ?? d.role) : ''
    case 'employee.moved':
      return `${d.from ?? 'no one'} → ${d.to ?? '?'}`
    case 'employee.invite_resent':
      return d.emailSent === false ? 'The email could not be sent' : ''
    case 'timeline.viewed':
    case 'screenshots.viewed':
      return typeof d.day === 'string' ? d.day : ''
    case 'settings.updated':
      return Object.entries(d).map(([key, value]) => `${SETTING_LABEL[key] ?? key} → ${value}`).join(', ')
    default:
      return ''
  }
}
