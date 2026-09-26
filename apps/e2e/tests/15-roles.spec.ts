import type { Page } from '@playwright/test'
import { DASH_HEADERS, as, expect, otherPerson, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { mailLinkCount, nextMailLink } from '../helpers/mail'

const PASSWORD_FOR_NEW = 'records-officer-99'
const NEW_ROLE = 'Records Officer'
const PERSON = { name: 'Rita Records', email: 'rita.e2e@test.com' }

const dialog = (page: Page) => page.getByRole('dialog')
const roleRow = (page: Page, name: string) => page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) })

test.beforeEach(() => artisan('cache:clear'))

// Each organization makes its own roles from the platform's fixed list of permissions (docs §9.1). The admin of the demo
// organization is the person here; the same rules are tested against the API in the PHP suite.
test.describe.serial('roles as the admin', () => {
  test.use(as('oic'))

  test('READ: lists the roles with their reach, permissions and people, and the built-in one is marked', async ({ page }) => {
    await page.goto('/user/roles')
    await expect(page.getByRole('heading', { name: 'Roles' })).toBeVisible()
    for (const col of ['Role', 'Reaches', 'Permissions', 'People'])
      await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    await expect(page.getByText('Built in')).toBeVisible()
    await expect(roleRow(page, 'Team Leader')).toContainText('Their team')
    await expect(roleRow(page, 'Team Leader')).toContainText('3') // three team leaders
    await expect(roleRow(page, 'Developer')).toContainText('Only themselves')
    // the role the admin holds cannot be edited or deleted by them
    await expect(page.getByRole('row').filter({ hasText: 'Built in' }).getByRole('button')).toHaveCount(0)
    // the other organization's roles are not here
    await expect(page.getByText('Staff', { exact: true })).toHaveCount(0)
  })

  test('CREATE: the form asks for a name and refuses permissions that do not fit the reach, with the reason', async ({ page }) => {
    let posted = false
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().endsWith('/api/v1/roles')) posted = true
    })
    await page.goto('/user/roles')
    await page.getByRole('button', { name: 'Make a role' }).click()
    // every permission of the platform's list is offered, in groups
    for (const label of ['See people', 'Add people', 'Change roles', 'See screenshots', 'Download reports', 'Change settings', 'See the audit log', 'Manage roles'])
      await expect(dialog(page).getByLabel(label)).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(dialog(page).getByText('Enter a name for the role.')).toBeVisible()

    await dialog(page).getByLabel('Name', { exact: true }).fill(NEW_ROLE)
    // a role that only reaches the person cannot hold a permission about others
    await dialog(page).getByLabel('See people').check()
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(dialog(page).getByText('These permissions are about other people, so the role has to reach a team or the whole organization.')).toBeVisible()
    // organization-wide permissions need the whole organization
    await dialog(page).getByLabel('Their team').check()
    await dialog(page).getByLabel('Manage roles').check()
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(dialog(page).getByText('Settings, the audit log and roles are for the whole organization, so the role has to reach the whole organization.')).toBeVisible()
    expect(posted).toBe(false)
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
  })

  test('CREATE: a role is made, listed, and offered when adding a person', async ({ page }) => {
    await page.goto('/user/roles')
    await page.getByRole('button', { name: 'Make a role' }).click()
    await dialog(page).getByLabel('Name', { exact: true }).fill(NEW_ROLE)
    await dialog(page).getByLabel('Description (optional)').fill('Keeps the records')
    await dialog(page).getByLabel('Their team').check()
    await dialog(page).getByLabel('See people').check()
    await dialog(page).getByLabel('See screenshots').check()
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(page.getByText(`The role ${NEW_ROLE} was saved.`)).toBeVisible()
    await expect(roleRow(page, NEW_ROLE)).toContainText('Their team')
    await expect(roleRow(page, NEW_ROLE)).toContainText('2')
    await expect(roleRow(page, NEW_ROLE)).toContainText('Keeps the records')
    await page.goto('/user/people')
    await page.getByRole('button', { name: 'Add a person' }).click()
    expect(await dialog(page).getByLabel('Role').locator('option').allTextContents()).toContain(NEW_ROLE)
  })

  test('CREATE: a role that already exists is refused with the server\'s message', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/roles 409/)
    await page.goto('/user/roles')
    await page.getByRole('button', { name: 'Make a role' }).click()
    await dialog(page).getByLabel('Name', { exact: true }).fill('team leader')
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(dialog(page).getByText('already has a role with that name')).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
  })

  test('the person given the new role signs in and sees only what it allows', async ({ page, browser }) => {
    const before = mailLinkCount()
    await page.goto('/user/people')
    await page.getByRole('button', { name: 'Add a person' }).click()
    await dialog(page).getByLabel('Full name').fill(PERSON.name)
    await dialog(page).getByLabel('Email').fill(PERSON.email)
    await dialog(page).getByLabel('Role').selectOption({ label: NEW_ROLE })
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(page.getByText(`We emailed a set-password link to ${PERSON.email}.`)).toBeVisible()

    const them = await otherPerson(browser)
    await them.goto(await nextMailLink(before))
    await them.getByLabel('New password (at least 10 characters)').fill(PASSWORD_FOR_NEW)
    await them.getByLabel('Type it again').fill(PASSWORD_FOR_NEW)
    await them.getByRole('button', { name: 'Set password' }).click()
    await expect(them.getByText('Your password is set.')).toBeVisible()
    await them.goto('/')
    await them.getByLabel('Email address').fill(PERSON.email)
    await them.getByLabel('Password').fill(PASSWORD_FOR_NEW)
    await them.getByRole('button', { name: 'Sign in' }).click()
    await expect(them).toHaveURL(/\/user$/)
    // people.view yes; reports, roles, settings and the audit log no
    await expect(them.getByRole('link', { name: 'People', exact: true }).first()).toBeVisible()
    for (const name of ['Reports', 'Roles', 'Settings', 'Audit log']) await expect(them.getByRole('link', { name, exact: true })).toHaveCount(0)
    // reaching only their own (empty) team, they see just themselves
    await them.goto('/user/people')
    await expect(them.getByRole('row')).toHaveCount(2)
    // and cannot add people: their role does not hold that permission
    await expect(them.getByRole('button', { name: 'Add a person' })).toHaveCount(0)
    await them.context().close()
  })

  test('EDIT: a role is renamed and given a permission, and the audit log says what changed', async ({ page }) => {
    await page.goto('/user/roles')
    await roleRow(page, NEW_ROLE).getByRole('button', { name: 'Edit' }).click()
    await expect(dialog(page).getByRole('heading', { name: `Edit ${NEW_ROLE}` })).toBeVisible()
    await dialog(page).getByLabel('Name', { exact: true }).fill('Records Clerk')
    await dialog(page).getByLabel('See screenshots').uncheck()
    await dialog(page).getByLabel('Download reports').check()
    await dialog(page).getByLabel('See reports').check()
    await dialog(page).getByRole('button', { name: 'Save role' }).click()
    await expect(page.getByText('The role Records Clerk was saved.')).toBeVisible()
    await expect(roleRow(page, 'Records Clerk')).toContainText('3')
    await page.goto('/user/audit')
    const entry = page.getByRole('row').filter({ hasText: 'Edited a role' }).first()
    await expect(entry).toContainText('Records Clerk')
    await expect(entry).toContainText('was Records Officer')
    await expect(entry).toContainText('+2 permissions')
    await expect(entry).toContainText('−1 permission')
  })

  test('DELETE: a role that people hold cannot be deleted; an unused one can', async ({ page, allow }) => {
    allow(/DELETE \/api\/v1\/roles\/\d+ 409/)
    await page.goto('/user/roles')
    await roleRow(page, 'Records Clerk').getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(dialog(page).getByText('1 people hold this role. Give them another role first.')).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()

    // remove the person (no tracked time), then the role
    await page.goto('/user/people')
    await page.getByRole('row').filter({ has: page.getByRole('link', { name: PERSON.name, exact: true }) }).getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText(`${PERSON.name} was deleted.`)).toBeVisible()
    await page.goto('/user/roles')
    await roleRow(page, 'Records Clerk').getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText('The role Records Clerk was deleted.')).toBeVisible()
    await expect(roleRow(page, 'Records Clerk')).toHaveCount(0)
  })
})

test.describe('roles need their own permission', () => {
  test.use(as('pm1'))

  test('a project manager has no Roles page and the API refuses them', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/roles 403/)
    await page.goto('/user/roles')
    await expect(page).toHaveURL(/\/user$/)
    await expect(page.getByRole('link', { name: 'Roles', exact: true })).toHaveCount(0)
    const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
    const res = await page.request.post('/api/v1/roles', { headers: { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }, data: { name: 'Sneaky', scope: 'self', permissions: [] } })
    expect(res.status()).toBe(403)
  })
})
