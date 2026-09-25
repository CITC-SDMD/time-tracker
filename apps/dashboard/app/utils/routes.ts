import type { Role } from 'shared'

// Where someone lands after signing in, and when they open the sign-in page (`/`) while signed in:
// a superadmin goes to `/superadmin`, everyone else to `/user`.
export function homeFor(role: Role): string {
  return role === 'superadmin' ? '/superadmin' : '/user'
}

// Office settings and the audit log are OIC-only (docs/DEVELOPMENT_PLAN.md §9.1). The API refuses
// the same things on its own; this only keeps people out of screens that would show nothing but errors.
export const OIC_ONLY_PAGES = ['/user/settings', '/user/audit']

export interface NavItem {
  name: string
  to: string
  icon: 'overview' | 'people' | 'reports' | 'settings' | 'audit'
}

/** The sidebar for someone on the `/user` pages: what they may open depends on their role. */
export function navFor(role: Role): NavItem[] {
  const items: NavItem[] = [
    { name: 'Overview', to: '/user', icon: 'overview' },
    { name: 'People', to: '/user/people', icon: 'people' },
    { name: 'Reports', to: '/user/reports', icon: 'reports' },
  ]
  if (role === 'oic') {
    items.push(
      { name: 'Settings', to: '/user/settings', icon: 'settings' },
      { name: 'Audit log', to: '/user/audit', icon: 'audit' },
    )
  }
  return items
}

/** True when `path` is the page `to` or one below it (`/user/employees/5` belongs to Overview). */
export function isCurrent(path: string, to: string): boolean {
  if (to === '/user')
    return path === '/user' || path.startsWith('/user/employees')
  return path === to || path.startsWith(`${to}/`)
}

const PAGE_TITLE: Record<string, string> = {
  '/': 'Sign in',
  '/forgot-password': 'Forgot password',
  '/reset-password': 'Choose your password',
  '/user': 'Overview',
  '/user/people': 'People',
  '/user/reports': 'Reports',
  '/user/settings': 'Office settings',
  '/user/audit': 'Audit log',
  '/user/profile': 'Your profile',
  '/superadmin': 'Superadmin',
}

/** The words in the browser tab and for screen readers: "People · Time Tracker". */
export function titleFor(path: string): string {
  if (path.startsWith('/user/employees/'))
    return 'Person'
  return PAGE_TITLE[path] ?? ''
}
