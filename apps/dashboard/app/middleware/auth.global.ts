// Every page except /login needs a signed-in manager; Settings and Audit are OIC-only
// (docs/DEVELOPMENT_PLAN.md §9.1). The API refuses the same things on its own — this only
// keeps people out of screens that would show nothing but errors.
const OIC_ONLY = ['/settings', '/audit']

export default defineNuxtRouteMiddleware(async (to) => {
  const { me, isManager, isOic, restore } = useAuth()

  if (!me.value)
    await restore()

  if (to.path === '/login')
    return isManager.value ? navigateTo('/') : undefined

  if (!isManager.value)
    return navigateTo('/login')

  if (OIC_ONLY.includes(to.path) && !isOic.value)
    return navigateTo('/')
})
