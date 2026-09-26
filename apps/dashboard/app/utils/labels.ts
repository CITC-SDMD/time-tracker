import type { AuditLogEntry, EmployeeListItem, Environment, IntegrityLevel, UserStatus } from 'shared'

// The words and colours the dashboard uses for the values the API sends as snake_case codes.

export type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary'

type LiveStatus = EmployeeListItem['status']

/** the flags of the virtual machine detection (the physical value raises none) */
export const ENVIRONMENT_LABEL: Partial<Record<Environment, string>> = {
  virtual_machine: 'Virtual machine',
  remote_session: 'Remote session',
}

/** the activity check: a note to look into, so the words never say someone cheated */
export const INTEGRITY_LABEL: Partial<Record<IntegrityLevel, string>> = {
  review: 'Review activity',
  strong: 'Automation likely',
}

export const INTEGRITY_VARIANT: Partial<Record<IntegrityLevel, BadgeVariant>> = {
  review: 'warning',
  strong: 'danger',
}

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
  'employee.role_changed': 'Changed a role',
  'employee.invite_resent': 'Sent a new set-password link',
  'detection.toggled': 'Switched the virtual machine detection on or off for a person',
  'employee.invite_failed': 'A set-password email could not be delivered',
  'role.created': 'Made a role',
  'role.updated': 'Edited a role',
  'role.deleted': 'Deleted a role',
  'settings.updated': 'Changed organization settings',
  'organization.created': 'Created an organization',
  'organization.renamed': 'Renamed an organization',
  'organization.timezone_changed': 'Changed the timezone of an organization',
  'organization.suspended': 'Suspended an organization',
  'superadmin.updated': 'Changed the name or email of a superadmin',
  'organization.reactivated': 'Reactivated an organization',
  'superadmin.created': 'Added a superadmin',
  'superadmin.permissions_changed': 'Changed the permissions of a superadmin',
  'platform.settings_updated': 'Changed platform settings',
  'timeline.viewed': 'Viewed a timeline',
  'screenshots.viewed': 'Viewed screenshots',
  'password.reset': 'Reset a password with an emailed link',
  'password.changed': 'Changed their password',
  'profile.email_changed': 'Changed their email',
  'report.exported': 'Downloaded a report',
}

export function auditActionLabel(action: string): string {
  return AUDIT_ACTION_LABEL[action] ?? action
}

const SETTING_LABEL: Record<string, string> = {
  timezone: 'timezone',
  idleThresholdSeconds: 'idle limit',
  windowTitleMode: 'window titles',
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
      return typeof d.role === 'string' ? d.role : ''
    case 'employee.moved':
      return `${d.from ?? 'no one'} → ${d.to ?? 'no one'}`
    case 'employee.role_changed': {
      const moved = 'managerTo' in d ? `, now reports to ${d.managerTo ?? 'no one'}` : ''
      return `${d.from ?? '?'} → ${d.to ?? '?'}${moved}`
    }
    case 'role.created':
    case 'role.deleted':
      return typeof d.role === 'string' ? d.role : ''
    case 'role.updated': {
      const added = Array.isArray(d.permissionsAdded) ? d.permissionsAdded.length : 0
      const removed = Array.isArray(d.permissionsRemoved) ? d.permissionsRemoved.length : 0
      const changes = [
        typeof d.renamedFrom === 'string' ? `was ${d.renamedFrom}` : '',
        added ? `+${added} permission${added === 1 ? '' : 's'}` : '',
        removed ? `−${removed} permission${removed === 1 ? '' : 's'}` : '',
        d.scopeTo ? `reach: ${d.scopeFrom} → ${d.scopeTo}` : '',
      ].filter(Boolean)
      return [d.role, changes.length ? `(${changes.join(', ')})` : ''].filter(Boolean).join(' ')
    }
    case 'organization.created':
    case 'organization.suspended':
    case 'organization.reactivated':
      return typeof d.organization === 'string' ? d.organization : ''
    case 'organization.renamed':
    case 'organization.timezone_changed':
      return `${d.from ?? '?'} → ${d.to ?? '?'}`
    case 'superadmin.updated':
      return [d.nameTo ? `name → ${d.nameTo}` : '', d.emailTo ? `email → ${d.emailTo}` : ''].filter(Boolean).join(', ')
    case 'superadmin.permissions_changed': {
      const added = Array.isArray(d.added) ? d.added.length : 0
      const removed = Array.isArray(d.removed) ? d.removed.length : 0
      return [added ? `+${added}` : '', removed ? `−${removed}` : ''].filter(Boolean).join(', ')
    }
    case 'platform.settings_updated':
      return `minimum app version ${d.minAgentVersionFrom ?? '?'} → ${d.minAgentVersionTo ?? '?'}`
    case 'detection.toggled':
      return d.enabled ? 'on' : 'off'
    case 'profile.email_changed':
      return `${d.from ?? '?'} → ${d.to ?? '?'}`
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
