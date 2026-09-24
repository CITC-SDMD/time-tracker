// Sends people to the right screen: login when nobody is logged in, the consent screen
// while consent is missing or out of date, and the main screen otherwise. Reads the
// session from Rust (`get_session`), which needs no network.
export default defineNuxtRouteMiddleware(async (to) => {
  const { me, load } = useAuth()
  if (me.value === undefined)
    await load()

  const user = me.value
  if (!user)
    return to.path === '/login' ? undefined : navigateTo('/login')
  if (user.consentRequired)
    return to.path === '/consent' ? undefined : navigateTo('/consent')
  if (to.path === '/login' || to.path === '/consent')
    return navigateTo('/')
})
