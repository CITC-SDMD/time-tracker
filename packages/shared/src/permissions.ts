// Mirrors docs/DEVELOPMENT_PLAN.md §9.1 and apps/api/app/Support/Permissions.php: the fixed lists of permissions.
// The platform defines them; each organization chooses which of its own roles hold which. The texts shown to people
// (labels, descriptions) come from GET /api/v1/permissions, so the keys are the only thing kept here.

export type UserStatus = 'active' | 'inactive'

/** How far a role reaches: only the person, their team (everyone below them at any depth), or the whole organization. */
export type Scope = 'self' | 'team' | 'organization'

export const SCOPES: readonly Scope[] = ['self', 'team', 'organization']

export const SCOPE_LABEL: Record<Scope, string> = {
  self: 'Only themselves',
  team: 'Their team',
  organization: 'The whole organization',
}

/** Wider is higher: nobody gives a role that reaches further than their own. */
export const SCOPE_RANK: Record<Scope, number> = { self: 0, team: 1, organization: 2 }

export type Permission =
  | 'people.view'
  | 'people.create'
  | 'people.update'
  | 'people.assign_role'
  | 'timeline.view'
  | 'screenshots.view'
  | 'reports.view'
  | 'reports.export'
  | 'settings.manage'
  | 'audit.view'
  | 'roles.manage'
  | 'tasks.view'
  | 'tasks.manage'

/** Permissions about the whole organization: a role holding one has to reach the whole organization. */
export const ORGANIZATION_WIDE_PERMISSIONS: readonly Permission[] = ['settings.manage', 'audit.view', 'roles.manage', 'tasks.manage']

/**
 * Why `permissions` cannot go with `scope`, or null when the pair is fine. The same rule the server applies, so a
 * role form can say so while it is being filled in.
 */
export function roleProblem(scope: Scope, permissions: readonly Permission[]): string | null {
  if (permissions.length > 0 && scope === 'self')
    return 'These permissions are about other people, so the role has to reach a team or the whole organization.'
  if (scope !== 'organization' && permissions.some(p => ORGANIZATION_WIDE_PERMISSIONS.includes(p)))
    return 'Settings, the audit log and roles are for the whole organization, so the role has to reach the whole organization.'
  return null
}

export type PlatformPermission =
  | 'organizations.view'
  | 'organizations.create'
  | 'organizations.update'
  | 'organizations.admins.manage'
  | 'organizations.data.view'
  | 'organizations.data.manage'
  | 'organizations.detection.manage'
  | 'platform.settings'
  | 'platform.staff.manage'
  | 'platform.audit.view'

// GET /api/v1/permissions and /api/v1/platform/permissions
export interface PermissionInfo<K extends string = Permission> {
  key: K
  group: string
  label: string
  description: string
}

export interface ScopeInfo {
  key: Scope
  label: string
  description: string
}

export interface PermissionCatalog {
  permissions: PermissionInfo[]
  organizationWide: Permission[]
  scopes: ScopeInfo[]
}

export interface PlatformPermissionCatalog {
  permissions: PermissionInfo<PlatformPermission>[]
}

// GET/POST /api/v1/roles, PATCH/DELETE /api/v1/roles/{id}
export interface RoleItem {
  id: string
  name: string
  description: string | null
  scope: Scope
  permissions: Permission[]
  /** the organization's built-in admin role: permissions and reach are locked */
  isSystem: boolean
  memberCount: number
  /** whether the caller may give this role to somebody (nobody gives more than they have) */
  assignable: boolean
}

/** Every organization permission (what the admin role holds, and what a superadmin who may manage an office acts with). */
export const ALL_PERMISSIONS: readonly Permission[] = [
  'people.view',
  'people.create',
  'people.update',
  'people.assign_role',
  'timeline.view',
  'screenshots.view',
  'reports.view',
  'reports.export',
  'settings.manage',
  'audit.view',
  'roles.manage',
  'tasks.view',
  'tasks.manage',
]

/** What a superadmin who may only look inside an office gets there. */
export const READ_ONLY_PERMISSIONS: readonly Permission[] = [
  'people.view',
  'timeline.view',
  'screenshots.view',
  'reports.view',
  'reports.export',
  'audit.view',
  'tasks.view',
]
