import type { Permission, PlatformPermission } from 'shared'

// What a page needs, read by middleware/auth.global.ts: `definePageMeta({ permission: 'people.view' })`.
declare module '#app' {
  interface PageMeta {
    /** an organization permission the person needs (inside an opened office: the virtual one, see permissionsFor) */
    permission?: Permission
    /** a platform permission a superadmin needs */
    platformPermission?: PlatformPermission
  }
}

export {}
