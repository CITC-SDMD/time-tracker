import type { Page } from '@playwright/test'
import { ACCOUNTS, PASSWORD, as, expect, loginAs, otherPerson, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { mailLinkCount, nextMailLink } from '../helpers/mail'

const ORG = 'Water District'
const ADMIN = { name: 'Wanda Water', email: 'wanda.e2e@test.com', password: 'wanda-secret-99' }
const LIMITED = { name: 'Vic Viewer', email: 'vic.e2e@test.com', password: 'vic-viewer-secret-9' }

const dialog = (page: Page) => page.getByRole('dialog')
const row = (page: Page, text: string) => page.getByRole('row').filter({ hasText: text })
// a person's row by the link on their name (the Manager column repeats other names)
const person = (page: Page, name: string) => page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) })

test.beforeEach(() => artisan('cache:clear'))

async function choosePassword(page: Page, link: string, password: string) {
  await page.goto(link)
  await page.getByLabel('New password (at least 10 characters)').fill(password)
  await page.getByLabel('Type it again').fill(password)
  await page.getByRole('button', { name: 'Set password' }).click()
  await expect(page.getByText('Your password is set.')).toBeVisible()
}

// The platform layer (docs §9.4): a superadmin creates an organization and its admin, looks inside it when their
// permissions allow, and sees only what they have permission for. Serial: each test builds on the one before.
test.describe.serial('the platform as the owner', () => {
  test.use(as('admin'))

  test('the organizations are listed with numbers only', async ({ page }) => {
    await page.goto('/platform')
    await expect(page.getByRole('heading', { name: 'Organizations' })).toBeVisible()
    await expect(row(page, 'Demo Office')).toContainText('Active')
    await expect(row(page, 'Demo Office')).toContainText('12') // its people
    await expect(row(page, 'Other Office')).toContainText('4')
    // no employee names anywhere on this page
    await expect(page.getByText('Tina Cruz')).toHaveCount(0)
    // the platform sidebar, not an organization's
    for (const name of ['Organizations', 'Superadmins', 'Platform settings', 'Platform audit log'])
      await expect(page.getByRole('link', { name, exact: true }).first()).toBeVisible()
    await expect(page.getByRole('link', { name: 'People', exact: true })).toHaveCount(0)
  })

  test('a superadmin has no organization pages of their own', async ({ page }) => {
    await page.goto('/user')
    await expect(page).toHaveURL(/\/platform$/)
  })

  test('CREATE: an organization is made, starting with nothing but its admin role', async ({ page }) => {
    await page.goto('/platform')
    await page.getByRole('button', { name: 'Create an organization' }).click()
    await dialog(page).getByRole('button', { name: 'Create', exact: true }).click()
    await expect(dialog(page).getByText('Enter the name of the organization.')).toBeVisible()
    await dialog(page).getByLabel('Name').fill(ORG)
    await dialog(page).getByRole('button', { name: 'Create', exact: true }).click()
    await expect(page).toHaveURL(/\/platform\/organizations\/\d+$/)
    await expect(page.getByRole('heading', { name: ORG })).toBeVisible()
    await expect(page.getByText('No admin yet')).toBeVisible()
  })

  test('CREATE: an organization name that exists is refused', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/platform\/organizations 409/)
    await page.goto('/platform')
    await page.getByRole('button', { name: 'Create an organization' }).click()
    await dialog(page).getByLabel('Name').fill('water district')
    await dialog(page).getByRole('button', { name: 'Create', exact: true }).click()
    await expect(dialog(page).getByText('already exists')).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
  })

  test('CREATE: the admin is added on the organization profile and emailed a link', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/platform')
    await page.getByRole('link', { name: ORG }).click()
    await page.getByRole('button', { name: 'Add an admin' }).click()
    await dialog(page).getByLabel('Full name').fill(ADMIN.name)
    await dialog(page).getByLabel('Email').fill(ADMIN.email)
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(page.getByText(`We emailed a set-password link to ${ADMIN.email}.`)).toBeVisible()
    await expect(row(page, ADMIN.name)).toContainText('Active')
    await expect(page.getByText('1', { exact: true }).first()).toBeVisible()
    expect(await nextMailLink(before)).toContain('/reset-password?link=')
  })

  test('CREATE: the admin sets a password, signs in, and finds an empty organization to build', async ({ browser }) => {
    const before = mailLinkCount() - 1
    const them = await otherPerson(browser)
    await choosePassword(them, await nextMailLink(Math.max(before, 0)), ADMIN.password)
    await loginAs(them, ADMIN.email, ADMIN.password)
    await expect(them).toHaveURL(/\/user$/)
    for (const name of ['Overview', 'People', 'Reports', 'Roles', 'Settings', 'Audit log'])
      await expect(them.getByRole('link', { name, exact: true }).first()).toBeVisible()
    await expect(them.getByText(ORG).first()).toBeVisible() // the sidebar names the organization
    // nothing of the demo organization, and no platform pages
    await expect(them.getByRole('row')).toHaveCount(2) // the header and themselves
    await them.goto('/platform')
    await expect(them).toHaveURL(/\/user$/)
    // the admin makes a role and adds a person with it
    await them.goto('/user/roles')
    await them.getByRole('button', { name: 'Make a role' }).click()
    await dialog(them).getByLabel('Name', { exact: true }).fill('Employee')
    await dialog(them).getByRole('button', { name: 'Save role' }).click()
    await expect(them.getByText('The role Employee was saved.')).toBeVisible()
    await them.goto('/user/people')
    await them.getByRole('button', { name: 'Add a person' }).click()
    await dialog(them).getByLabel('Full name').fill('Eli Employee')
    await dialog(them).getByLabel('Email').fill('eli.e2e@test.com')
    await dialog(them).getByLabel('Role').selectOption({ label: 'Employee' })
    await dialog(them).getByRole('button', { name: 'Add and email link' }).click()
    await expect(them.getByRole('row').filter({ has: them.getByRole('link', { name: 'Eli Employee', exact: true }) })).toContainText('Employee')
    await them.context().close()
  })

  test('OPEN OFFICE: the owner works inside the organization through its own pages, with a banner', async ({ page }) => {
    await page.goto('/platform')
    await page.getByRole('link', { name: ORG }).click()
    await page.getByRole('button', { name: 'Open office' }).click()
    await expect(page).toHaveURL(/\/platform\/organizations\/\d+\/office$/)
    await expect(page.getByText(`Viewing ${ORG} as platform staff`)).toBeVisible()
    await expect(page.getByText('you can change things here as its admin')).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible()
    await page.getByRole('link', { name: 'People', exact: true }).click()
    await expect(page).toHaveURL(/\/office\/people$/)
    await expect(person(page, 'Wanda Water')).toBeVisible()
    await expect(person(page, 'Eli Employee')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Add a person' })).toBeVisible()
    // the roles page is the organization's, not the demo office's
    await page.getByRole('link', { name: 'Roles', exact: true }).click()
    await expect(page.getByRole('row').filter({ hasText: 'Only themselves' }).first()).toContainText('Employee')
    await expect(page.getByText('Team Leader', { exact: true })).toHaveCount(0)
    await page.getByRole('link', { name: 'Back to organizations' }).click()
    await expect(page).toHaveURL(/\/platform$/)
  })

  test('SUSPEND: a suspended organization cannot sign in, and reactivating brings it back', async ({ page, browser, allow }) => {
    allow(/POST \/auth\/login 403/)
    await page.goto('/platform')
    await page.getByRole('link', { name: ORG }).click()
    await page.getByRole('button', { name: 'Suspend' }).click()
    await dialog(page).getByRole('button', { name: 'Suspend', exact: true }).click()
    await expect(page.getByText('Suspended').first()).toBeVisible()

    const wanda = await otherPerson(browser)
    await wanda.goto('/')
    await wanda.getByLabel('Email address').fill(ADMIN.email)
    await wanda.getByLabel('Password').fill(ADMIN.password)
    await wanda.getByRole('button', { name: 'Sign in' }).click()
    await expect(wanda.getByText('Your organization is suspended.')).toBeVisible()

    await page.getByRole('button', { name: 'Reactivate' }).click()
    await dialog(page).getByRole('button', { name: 'Reactivate', exact: true }).click()
    await expect(page.getByText('Active').first()).toBeVisible()
    await wanda.getByRole('button', { name: 'Sign in' }).click()
    await expect(wanda).toHaveURL(/\/user$/)
    await wanda.context().close()
  })

  test('SUPERADMINS: one is added with only some permissions, and the audit log shows the platform actions', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/platform/superadmins')
    await expect(row(page, 'admin@test.com')).toContainText('Owner')
    await page.getByRole('button', { name: 'Add a superadmin' }).click()
    await dialog(page).getByLabel('Full name').fill(LIMITED.name)
    await dialog(page).getByLabel('Email').fill(LIMITED.email)
    await dialog(page).getByLabel('See organizations').check()
    await dialog(page).getByLabel('Look inside an organization').check()
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(page.getByText(`We emailed a set-password link to ${LIMITED.email}.`)).toBeVisible()
    await expect(row(page, LIMITED.name)).toContainText('2 of 10')
    expect(await nextMailLink(before)).toContain('/reset-password?link=')

    await page.goto('/platform/audit')
    for (const label of ['Created an organization', 'Suspended an organization', 'Reactivated an organization', 'Added a superadmin'])
      await expect(page.locator('td', { hasText: label }).first()).toBeVisible()
  })

  test('SETTINGS: the oldest allowed app version is a platform setting with its own validation', async ({ page }) => {
    await page.goto('/platform/settings')
    const version = page.getByLabel('Minimum desktop app version')
    await expect(version).toHaveValue('0.1.0')
    await version.fill('1.2')
    await version.blur()
    await expect(page.getByText('Use the form 1.2.3.')).toBeVisible()
    await version.fill('0.1.1')
    await page.getByRole('button', { name: 'Save settings' }).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
    await version.fill('0.1.0')
    await page.getByRole('button', { name: 'Save settings' }).click()
    await expect(page.getByText('Settings saved.')).toBeVisible()
  })
})

test.describe.serial('a superadmin with limited permissions', () => {
  test('only what they may use is shown, and an office is look-only', async ({ browser }) => {
    const before = mailLinkCount() - 1
    const vic = await otherPerson(browser)
    await choosePassword(vic, await nextMailLink(Math.max(before, 0)), LIMITED.password)
    await loginAs(vic, LIMITED.email, LIMITED.password)
    await expect(vic).toHaveURL(/\/platform$/)
    // organizations yes; the other platform pages are hidden, and closed if typed in
    await expect(vic.getByRole('link', { name: 'Organizations', exact: true }).first()).toBeVisible()
    for (const name of ['Superadmins', 'Platform settings', 'Platform audit log']) await expect(vic.getByRole('link', { name, exact: true })).toHaveCount(0)
    await vic.goto('/platform/superadmins')
    await expect(vic).toHaveURL(/\/platform$/)
    await expect(vic.getByRole('button', { name: 'Create an organization' })).toHaveCount(0)

    // open an office: look only
    await vic.getByRole('row').filter({ hasText: 'Demo Office' }).getByRole('link', { name: 'Demo Office' }).click()
    await expect(vic.getByRole('button', { name: 'Edit details' })).toHaveCount(0)
    await expect(vic.getByRole('button', { name: 'Add an admin' })).toHaveCount(0)
    await vic.getByRole('button', { name: 'Open office' }).click()
    await expect(vic.getByText('Viewing Demo Office as platform staff')).toBeVisible()
    await expect(vic.getByText('look only, nothing can be changed')).toBeVisible()
    await vic.getByRole('link', { name: 'People', exact: true }).click()
    await expect(person(vic, 'Tina Cruz')).toBeVisible()
    await expect(vic.getByRole('button', { name: 'Add a person' })).toHaveCount(0)
    await expect(person(vic, 'Tina Cruz')).toBeVisible()
    await expect(person(vic, 'Tina Cruz').getByRole('button')).toHaveCount(0)
    for (const name of ['Roles', 'Settings']) await expect(vic.getByRole('link', { name, exact: true })).toHaveCount(0)
    await expect(vic.getByRole('link', { name: 'Audit log', exact: true })).toBeVisible()
    // a person's day opens (and reading is not logged)
    await vic.getByRole('link', { name: 'Tina Cruz' }).click()
    await expect(vic.getByRole('heading', { name: 'Tina Cruz' })).toBeVisible()
    await vic.context().close()
  })

  test('looking at an office\'s days is not logged in that office\'s audit log', async ({ browser }) => {
    const admin = await otherPerson(browser, 'oic')
    await admin.goto('/user/audit')
    await expect(admin.getByRole('heading', { name: 'Audit log' })).toBeVisible()
    await expect(admin.getByRole('row').filter({ hasText: LIMITED.name })).toHaveCount(0)
    await admin.context().close()
  })
})

test.describe('the platform is closed to everyone else', () => {
  test('a person of an organization cannot open the platform pages', async ({ page }) => {
    await loginAs(page, ACCOUNTS.oic)
    await page.goto('/platform')
    await expect(page).toHaveURL(/\/user$/)
    expect(PASSWORD).toBe('password')
  })
})
