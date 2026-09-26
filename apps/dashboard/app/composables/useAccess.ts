import type { Permission, PlatformPermission, Scope } from 'shared'

// What the signed-in person may do, for the dashboard to show only that (docs/DEVELOPMENT_PLAN.md §9.1). The API checks
// every one of these again on its own; this only keeps people out of screens and buttons that would show nothing but
// errors. Inside an office a superadmin has a virtual role that follows their platform permissions: everything with
// `organizations.data.manage`, look-only with `organizations.data.view`.
export function useAccess() {
  const { me } = useAuth()
  const office = useOffice()

  const permissions = computed<readonly Permission[]>(() => permissionsFor(me.value, office.inOffice.value))

  const scope = computed<Scope>(() => (me.value?.isSuperadmin ? 'organization' : (me.value?.scope ?? 'self')))

  /** looking at an office without the right to change anything in it */
  const readOnly = computed(() => !!me.value?.isSuperadmin && office.inOffice.value && !me.value.platformPermissions.includes('organizations.data.manage'))

  function can(permission: Permission): boolean {
    return permissions.value.includes(permission)
  }

  function canPlatform(permission: PlatformPermission): boolean {
    return !!me.value?.platformPermissions.includes(permission)
  }

  return { permissions, scope, readOnly, can, canPlatform }
}
