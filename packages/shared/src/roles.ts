// Mirrors docs/DEVELOPMENT_PLAN.md §9.1 (users.role / users.status) and
// apps/api/app/Models/User.php.

export type Role =
  | 'OIC'
  | 'PROJECT_MANAGER'
  | 'TEAM_LEADER'
  | 'LEAD_DEVELOPER'
  | 'DEVELOPER'
  | 'CLIENT_SUPPORT'
  | 'QA'
  | 'SYSTEM_ANALYST'

export type UserStatus = 'ACTIVE' | 'DEACTIVATED'

/** Roles that may use the dashboard. */
export const MANAGER_ROLES: readonly Role[] = ['OIC', 'PROJECT_MANAGER', 'TEAM_LEADER']

export const INDIVIDUAL_CONTRIBUTOR_ROLES: readonly Role[] = [
  'LEAD_DEVELOPER',
  'DEVELOPER',
  'CLIENT_SUPPORT',
  'QA',
  'SYSTEM_ANALYST',
]

export const ROLE_LABEL: Record<Role, string> = {
  OIC: 'OIC',
  PROJECT_MANAGER: 'Project Manager',
  TEAM_LEADER: 'Team Leader',
  LEAD_DEVELOPER: 'Lead Developer',
  DEVELOPER: 'Developer',
  CLIENT_SUPPORT: 'Client Support',
  QA: 'QA',
  SYSTEM_ANALYST: 'System Analyst',
}

export function isManagerRole(role: Role): boolean {
  return MANAGER_ROLES.includes(role)
}

/** The roles a person of `role` may create: exactly one tier below their own. */
export function rolesOneTierBelow(role: Role): readonly Role[] {
  switch (role) {
    case 'OIC':
      return ['PROJECT_MANAGER']
    case 'PROJECT_MANAGER':
      return ['TEAM_LEADER']
    case 'TEAM_LEADER':
      return INDIVIDUAL_CONTRIBUTOR_ROLES
    default:
      return []
  }
}
