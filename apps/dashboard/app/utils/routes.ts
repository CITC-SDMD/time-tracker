import type { Me, Permission, PlatformPermission } from 'shared'

// Where someone lands after signing in, and when they open the sign-in page (`/`) while signed in:
// a superadmin goes to the platform pages, everyone else to their own organization's.
export function homeFor(me: Pick<Me, 'isSuperadmin'>): string {
  return me.isSuperadmin ? '/platform' : '/user'
}

export type NavIcon = 'overview' | 'people' | 'reports' | 'roles' | 'settings' | 'audit' | 'organizations' | 'superadmins'

export interface NavItem {
  name: string
  to: string
  icon: NavIcon
  /** shown only to someone holding it */
  permission?: Permission
  platformPermission?: PlatformPermission
}

/** The sidebar of an organization's own pages (and of an office a superadmin has opened): what shows follows the permissions. */
export const ORGANIZATION_NAV: readonly NavItem[] = [
  { name: 'Overview', to: '/user', icon: 'overview' },
  { name: 'People', to: '/user/people', icon: 'people', permission: 'people.view' },
  { name: 'Reports', to: '/user/reports', icon: 'reports', permission: 'reports.view' },
  { name: 'Roles', to: '/user/roles', icon: 'roles', permission: 'roles.manage' },
  { name: 'Settings', to: '/user/settings', icon: 'settings', permission: 'settings.manage' },
  { name: 'Audit log', to: '/user/audit', icon: 'audit', permission: 'audit.view' },
]

/** The sidebar of the platform pages, for a superadmin: only what their platform permissions allow. */
export const PLATFORM_NAV: readonly NavItem[] = [
  { name: 'Organizations', to: '/platform', icon: 'organizations', platformPermission: 'organizations.view' },
  { name: 'Superadmins', to: '/platform/superadmins', icon: 'superadmins', platformPermission: 'platform.staff.manage' },
  { name: 'Platform settings', to: '/platform/settings', icon: 'settings', platformPermission: 'platform.settings' },
  { name: 'Platform audit log', to: '/platform/audit', icon: 'audit', platformPermission: 'platform.audit.view' },
]

/**
 * True when `path` is the page `to` or one below it (`/user/employees/5` belongs to Overview). Paths inside an opened
 * office are compared as the organization's own pages.
 */
export function isCurrent(path: string, to: string): boolean {
  const own = path.replace(/^\/platform\/organizations\/\d+\/office/, '/user')
  if (to === '/user')
    return own === '/user' || own.startsWith('/user/employees')
  if (to === '/platform')
    return path === '/platform' || path.startsWith('/platform/organizations')
  return own === to || own.startsWith(`${to}/`)
}

const PAGE_TITLE: Record<string, string> = {
  '/': 'Sign in',
  '/forgot-password': 'Forgot password',
  '/reset-password': 'Choose your password',
  '/user': 'Overview',
  '/user/people': 'People',
  '/user/reports': 'Reports',
  '/user/roles': 'Roles',
  '/user/settings': 'Organization settings',
  '/user/audit': 'Audit log',
  '/user/profile': 'Your profile',
  '/platform': 'Organizations',
  '/platform/superadmins': 'Superadmins',
  '/platform/settings': 'Platform settings',
  '/platform/audit': 'Platform audit log',
}

/** The words in the browser tab and for screen readers: "People · Time Tracker". */
export function titleFor(path: string): string {
  const own = path.replace(/^\/platform\/organizations\/\d+\/office/, '/user')
  if (own.startsWith('/user/employees/'))
    return 'Person'
  if (/^\/platform\/organizations\/\d+$/.test(path))
    return 'Organization'
  return PAGE_TITLE[own] ?? ''
}
