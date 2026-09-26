// Reachable without signing in. `/` is the sign-in page; anyone can ask for a password link or use
// one (employees who only use the desktop app set their password on those pages too).
const PUBLIC = ['/', '/forgot-password', '/reset-password']

// Pages that need only a signed-in person, whoever they are (a superadmin included).
const FOR_EVERYONE = ['/user/profile']

// Which pages someone may open follows their permissions (docs/DEVELOPMENT_PLAN.md §9.1): each page names the one it
// needs with definePageMeta({ permission }) or ({ platformPermission }). The API checks them again on every call.
export default defineNuxtRouteMiddleware(async (to) => {
  const { me, restore } = useAuth()

  if (!me.value)
    await restore()

  // a signed-in person who opens the sign-in page is sent to their own home
  if (to.path === '/')
    return me.value ? navigateTo(homeFor(me.value)) : undefined

  if (PUBLIC.includes(to.path))
    return

  if (!me.value)
    return navigateTo('/')

  if (FOR_EVERYONE.includes(to.path))
    return

  const onPlatformPages = to.path === '/platform' || to.path.startsWith('/platform/')
  // the platform pages are for superadmins, the /user pages for the people of an organization
  if (onPlatformPages !== me.value.isSuperadmin)
    return navigateTo(homeFor(me.value))

  if (OFFICE_PATH.test(to.path) && !me.value.platformPermissions.includes('organizations.data.view'))
    return navigateTo('/platform')

  if (to.meta.permission && !permissionsFor(me.value, OFFICE_PATH.test(to.path)).includes(to.meta.permission))
    return navigateTo(OFFICE_PATH.test(to.path) ? to.path.replace(/\/office\/.*$/, '/office') : homeFor(me.value))

  if (to.meta.platformPermission && !me.value.platformPermissions.includes(to.meta.platformPermission))
    return navigateTo(homeFor(me.value))
})
