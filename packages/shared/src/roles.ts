// Mirrors docs/DEVELOPMENT_PLAN.md §9.1 (users.role / users.status) and
// apps/api/app/Models/User.php.

export type Role =
  | 'superadmin'
  | 'oic'
  | 'project_manager'
  | 'team_leader'
  | 'lead_developer'
  | 'developer'
  | 'client_support'
  | 'qa'
  | 'system_analyst'

export type UserStatus = 'active' | 'inactive'

/** Roles that may use the dashboard. */
export const MANAGER_ROLES: readonly Role[] = ['superadmin', 'oic', 'project_manager', 'team_leader']

export const INDIVIDUAL_CONTRIBUTOR_ROLES: readonly Role[] = [
  'lead_developer',
  'developer',
  'client_support',
  'qa',
  'system_analyst',
]

export const ROLE_LABEL: Record<Role, string> = {
  superadmin: 'Superadmin',
  oic: 'OIC',
  project_manager: 'Project Manager',
  team_leader: 'Team Leader',
  lead_developer: 'Lead Developer',
  developer: 'Developer',
  client_support: 'Client Support',
  qa: 'QA',
  system_analyst: 'System Analyst',
}

export function isManagerRole(role: Role): boolean {
  return MANAGER_ROLES.includes(role)
}

/** The roles a person of `role` may create: exactly one tier below their own. */
export function rolesOneTierBelow(role: Role): readonly Role[] {
  switch (role) {
    case 'oic':
      return ['project_manager']
    case 'project_manager':
      return ['team_leader']
    case 'team_leader':
      return INDIVIDUAL_CONTRIBUTOR_ROLES
    default:
      return []
  }
}
