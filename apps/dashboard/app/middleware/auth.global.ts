// Reachable without signing in. `/` is the sign-in page; anyone can ask for a password link or use
// one (employees who only use the desktop app set their password on those pages too).
const PUBLIC = ['/', '/forgot-password', '/reset-password']

export default defineNuxtRouteMiddleware(async (to) => {
  const { me, isManager, isOic, restore } = useAuth()

  if (!me.value)
    await restore()

  // a signed-in person who opens the sign-in page is sent to their own home
  if (to.path === '/')
    return me.value && isManager.value ? navigateTo(homeFor(me.value.role)) : undefined

  if (PUBLIC.includes(to.path))
    return

  if (!me.value || !isManager.value)
    return navigateTo('/')

  // the superadmin area is for the superadmin only
  if ((to.path === '/superadmin' || to.path.startsWith('/superadmin/')) && me.value.role !== 'superadmin')
    return navigateTo(homeFor(me.value.role))

  if (OIC_ONLY_PAGES.includes(to.path) && !isOic.value)
    return navigateTo(homeFor(me.value.role))
})
