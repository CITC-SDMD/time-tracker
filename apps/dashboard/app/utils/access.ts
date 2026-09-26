import { ALL_PERMISSIONS, READ_ONLY_PERMISSIONS, type Me, type Permission } from 'shared'

/** The pages of an office a superadmin has opened: /platform/organizations/{id}/office/... */
export const OFFICE_PATH = /^\/platform\/organizations\/(\d+)\/office(?:\/|$)/

/**
 * What `me` may do on organization pages. A superadmin has none of their own: inside an opened office they act as a
 * virtual role that follows their platform permissions (everything with `organizations.data.manage`, look-only with
 * `organizations.data.view`), and outside one they have nothing here.
 */
export function permissionsFor(me: Me | null, inOffice: boolean): readonly Permission[] {
  if (!me)
    return []
  if (!me.isSuperadmin)
    return me.permissions
  if (!inOffice)
    return []
  if (me.platformPermissions.includes('organizations.data.manage'))
    return ALL_PERMISSIONS
  return me.platformPermissions.includes('organizations.data.view') ? READ_ONLY_PERMISSIONS : []
}
