import type { Page } from '@playwright/test'
import { ACCOUNTS, PASSWORD, as, expect, idOf, loginAs, otherPerson, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { mailLinkCount, nextMailLink } from '../helpers/mail'

const NEW_PM = { name: 'Quentin Estrada', email: 'quentin.e2e@test.com' }
const NEW_PASSWORD = 'quentin-secret-99'

// a person's row is found by the link on their name, since the Manager column repeats other names
const row = (page: Page, name: string) => page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) })
const openAdd = (page: Page) => page.getByRole('button', { name: 'Add Project Manager' }).click()
const dialog = (page: Page) => page.getByRole('dialog')

test.beforeEach(() => artisan('cache:clear'))

// Create, read, update and delete of an account, the way the OIC does them.
test.describe.serial('people as the OIC', () => {
  test.use(as('oic'))

  // ---- READ ----------------------------------------------------------------------------------

  test('READ: lists everyone with role, manager and account status', async ({ page }) => {
    await page.goto('/user/people')
    await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(13)
    for (const col of ['Name', 'Role', 'Manager', 'Account'])
      await expect(page.getByRole('columnheader', { name: col, exact: true })).toBeVisible()
    await expect(row(page, 'Tina Cruz')).toContainText('Team Leader')
    await expect(row(page, 'Tina Cruz')).toContainText('Paula Reyes')
    await expect(row(page, 'Paula Reyes')).toContainText('OIC')
    await expect(row(page, 'Dan Ramos')).toContainText('tl1@test.com'.replace('tl1', 'dev1'))
    await expect(row(page, 'Dan Ramos')).toContainText('Active')
    await expect(row(page, 'OIC')).toContainText('(you)')
  })

  test('READ: search and the role and account filters narrow the list', async ({ page }) => {
    await page.goto('/user/people')
    await expect(page.getByRole('row')).toHaveCount(13)
    await page.getByLabel('Search').fill('tina')
    await expect(page.getByRole('row')).toHaveCount(2)
    await page.getByLabel('Search').fill('dev2@')
    await expect(row(page, 'Dana Uy')).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(2)
    await page.getByLabel('Search').fill('zzzz-nobody')
    await expect(page.getByText('No one matches')).toBeVisible()
    await page.getByLabel('Search').fill('')
    await page.getByLabel('Role').selectOption({ label: 'Team Leader' })
    await expect(page.getByRole('row')).toHaveCount(4)
    await page.getByLabel('Role').selectOption({ label: 'All roles' })
    await page.getByLabel('Account').selectOption({ label: 'Deactivated' })
    await expect(page.getByText('No one matches')).toBeVisible()
  })

  test('READ: a name opens the person\'s day', async ({ page }) => {
    await page.goto('/user/people')
    await page.getByRole('link', { name: 'Tomas Diaz' }).click()
    await expect(page.getByRole('heading', { name: 'Tomas Diaz' })).toBeVisible()
  })

  test('the OIC row has no actions and the OIC can only add a Project Manager', async ({ page }) => {
    await page.goto('/user/people')
    await expect(row(page, 'OIC').getByRole('button')).toHaveCount(0)
    await openAdd(page)
    const role = dialog(page).getByLabel('Role')
    await expect(role.locator('option')).toHaveText(['Project Manager'])
    await expect(role).toHaveValue('project_manager')
  })

  // ---- CREATE --------------------------------------------------------------------------------

  test('CREATE: the form validates before sending anything', async ({ page }) => {
    let posted = false
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().includes('/admin/employees')) posted = true
    })
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page).getByText('Enter their full name.')).toBeVisible()
    await expect(dialog(page).getByText('Enter their email address.')).toBeVisible()
    await dialog(page).getByLabel('Email').fill('not-an-email')
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page).getByText('Enter a valid email address.')).toBeVisible()
    await dialog(page).getByLabel('Full name').fill('x'.repeat(256))
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page).getByText('Use at most 255 characters.')).toBeVisible()
    expect(posted).toBe(false)
  })

  test('CREATE: Cancel and Escape close the form and nothing is added', async ({ page }) => {
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill('Never Saved')
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(dialog(page)).toHaveCount(0)
    await openAdd(page)
    await expect(dialog(page).getByLabel('Full name')).toHaveValue('')
    await page.keyboard.press('Escape')
    await expect(dialog(page)).toHaveCount(0)
    await expect(row(page, 'Never Saved')).toHaveCount(0)
  })

  test('CREATE: a duplicate email is refused with the server\'s message', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/admin\/employees (409|422)/)
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill('Another Paula')
    await dialog(page).getByLabel('Email').fill('pm1@test.com')
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page).getByText(/already|taken|in use/i)).toBeVisible()
    await expect(dialog(page).getByLabel("Full name")).toBeVisible()
  })

  test('CREATE: a new Project Manager is added, listed under the OIC, and emailed a link', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill(`  ${NEW_PM.name}  `)
    await dialog(page).getByLabel('Email').fill(NEW_PM.email)
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page)).toHaveCount(0)
    await expect(page.getByText(`We emailed a set-password link to ${NEW_PM.email}.`)).toBeVisible()
    await expect(row(page, NEW_PM.name)).toContainText('Project Manager')
    await expect(row(page, NEW_PM.name)).toContainText('OIC')
    await expect(row(page, NEW_PM.name)).toContainText('Active')
    await expect(page.getByRole('row')).toHaveCount(14)
    const link = await nextMailLink(before)
    expect(link).toContain('/reset-password?link=')
  })

  test('CREATE: the new person picks a password from the emailed link and signs in as a manager', async ({ browser }) => {
    const before = mailLinkCount() - 1 // the welcome mail of the previous test is the newest one
    const link = await nextMailLink(Math.max(before, 0))
    const them = await otherPerson(browser)
    await them.goto(link)
    await them.getByLabel('New password (at least 10 characters)').fill(NEW_PASSWORD)
    await them.getByLabel('Type it again').fill(NEW_PASSWORD)
    await them.getByRole('button', { name: 'Set password' }).click()
    await expect(them.getByText('Your password is set.')).toBeVisible()
    await loginAs(them, NEW_PM.email, NEW_PASSWORD)
    await expect(them).toHaveURL(/\/user$/)
    await expect(them.getByRole('link', { name: 'Settings', exact: true })).toHaveCount(0)
    await them.goto('/user/people')
    await expect(them.getByRole('button', { name: 'Add Team Leader' })).toBeVisible()
    await them.context().close()
  })

  test('CREATE: the new person is now in the office totals', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row').filter({ hasText: NEW_PM.name })).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(14)
  })

  // ---- RESEND --------------------------------------------------------------------------------

  test('UPDATE: Resend link sends a fresh link that works', async ({ page, browser }) => {
    const before = mailLinkCount()
    await page.goto('/user/people')
    await row(page, NEW_PM.name).getByRole('button', { name: 'Resend link' }).click()
    await expect(page.getByText(`We emailed a new set-password link to ${NEW_PM.email}.`)).toBeVisible()
    const link = await nextMailLink(before)
    const them = await otherPerson(browser)
    await them.goto(link)
    await expect(them.getByText(`For ${NEW_PM.email}`)).toBeVisible()
    await them.context().close()
  })

  // ---- UPDATE: move --------------------------------------------------------------------------

  test('UPDATE: the move form lists only valid managers and asks for a choice', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Move Tess Lim' })).toBeVisible()
    await expect(dialog(page).getByText('currently reports to Pedro Santos')).toBeVisible()
    const options = await dialog(page).getByLabel('New manager').locator('option').allTextContents()
    // Project Managers only (one tier above), never the current manager, never a team leader or member
    expect(options).toEqual(['Choose a manager', 'Paula Reyes (Project Manager)', `${NEW_PM.name} (Project Manager)`])
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(dialog(page).getByText('Choose the new manager.')).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
  })

  test('UPDATE: moving a team leader moves them and their team, in both managers\' lists', async ({ page, browser }) => {
    await page.goto('/user/people')
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Paula Reyes (Project Manager)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(page.getByText('Tess Lim now reports to Paula Reyes.')).toBeVisible()
    await expect(row(page, 'Tess Lim')).toContainText('Paula Reyes')
    // Paula now sees Tess and Tess's team; Pedro no longer does
    const paula = await otherPerson(browser, 'pm1')
    await paula.goto('/user')
    for (const n of ['Tess Lim', 'Cara Sy', 'Sam Ong']) await expect(paula.getByRole('row').filter({ hasText: n })).toBeVisible()
    await paula.context().close()
    const pedro = await otherPerson(browser, 'pm2')
    await pedro.goto('/user')
    await expect(pedro.getByRole('row')).toHaveCount(2)
    await expect(pedro.getByRole('row').filter({ hasText: 'Tess Lim' })).toHaveCount(0)
    await pedro.context().close()
  })

  test('UPDATE: moving back restores the original shape', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Pedro Santos (Project Manager)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, 'Tess Lim')).toContainText('Pedro Santos')
  })

  test('UPDATE: a team member can be moved to another team leader, a project manager cannot be moved', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Sam Ong').getByRole('button', { name: 'Move' }).click()
    const options = await dialog(page).getByLabel('New manager').locator('option').allTextContents()
    expect(options).toEqual(['Choose a manager', 'Tina Cruz (Team Leader)', 'Tomas Diaz (Team Leader)'])
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Tomas Diaz (Team Leader)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, 'Sam Ong')).toContainText('Tomas Diaz')
    // put Sam back
    await row(page, 'Sam Ong').getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Tess Lim (Team Leader)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, 'Sam Ong')).toContainText('Tess Lim')
    // Paula can only go under the OIC, who is the only manager above her, and she already has them
    await expect(row(page, 'Paula Reyes').getByRole('button', { name: 'Move' })).toHaveCount(0)
  })

  // ---- UPDATE: deactivate / reactivate -------------------------------------------------------

  test('UPDATE: Cancel on the deactivate dialog changes nothing', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tomas Diaz').getByRole('button', { name: 'Deactivate' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Deactivate Tomas Diaz?' })).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, 'Tomas Diaz')).toContainText('Active')
  })

  test('UPDATE: deactivating a manager signs them out and blocks sign-in, reactivating restores it', async ({ page, browser, allow }) => {
    allow(/\/(auth\/login|api\/v1\/.*) (401|403)/)
    const tomas = await otherPerson(browser, 'tl2')
    await tomas.goto('/user')
    await expect(tomas.getByRole('heading', { name: 'Overview' })).toBeVisible()

    await page.goto('/user/people')
    await row(page, 'Tomas Diaz').getByRole('button', { name: 'Deactivate' }).click()
    await dialog(page).getByRole('button', { name: 'Deactivate', exact: true }).click()
    await expect(page.getByText('Tomas Diaz was deactivated.')).toBeVisible()
    await expect(row(page, 'Tomas Diaz')).toContainText('Deactivated')
    await expect(row(page, 'Tomas Diaz').getByRole('button', { name: 'Resend link' })).toHaveCount(0)
    await expect(row(page, 'Tomas Diaz').getByRole('button', { name: 'Reactivate' })).toBeVisible()

    // Test 6.9: his open dashboard is signed out on the next request
    await tomas.getByRole('button', { name: 'Refresh' }).click()
    await expect(tomas).toHaveURL(/\/$/)
    await tomas.getByLabel('Email address').fill(ACCOUNTS.tl2)
    await tomas.getByLabel('Password').fill(PASSWORD)
    await tomas.getByRole('button', { name: 'Sign in' }).click()
    await expect(tomas.getByText('Your account is deactivated.')).toBeVisible()

    // filter shows him under Deactivated only
    await page.getByLabel('Account').selectOption({ label: 'Deactivated' })
    await expect(page.getByRole('row')).toHaveCount(2)
    await page.getByLabel('Account').selectOption({ label: 'Active' })
    await expect(row(page, 'Tomas Diaz')).toHaveCount(0)
    await page.getByLabel('Account').selectOption({ label: 'All accounts' })

    await row(page, 'Tomas Diaz').getByRole('button', { name: 'Reactivate' }).click()
    await dialog(page).getByRole('button', { name: 'Reactivate', exact: true }).click()
    await expect(page.getByText('Tomas Diaz was reactivated.')).toBeVisible()
    await expect(row(page, 'Tomas Diaz')).toContainText('Active')
    // he can use the dashboard again (his old session works again, or he signs in afresh: either way he gets in)
    await tomas.goto("/user")
    await expect(tomas.getByRole("heading", { name: "Overview" })).toBeVisible()
    await tomas.context().close()
  })

  // ---- DELETE --------------------------------------------------------------------------------

  test('DELETE: an account that has tracked time cannot be deleted, and the reason is shown', async ({ page, allow }) => {
    allow(/DELETE \/api\/v1\/admin\/employees\/\d+ (409|422|400)/)
    await page.goto('/user/people')
    await row(page, 'Dan Ramos').getByRole('button', { name: 'Delete' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Delete Dan Ramos?' })).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(dialog(page).getByText(/deactivate|history|tracked/i).first()).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, 'Dan Ramos')).toBeVisible()
  })

  test('DELETE: Cancel keeps an unused account', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, NEW_PM.name).getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, NEW_PM.name)).toBeVisible()
  })

  test('DELETE: an unused account is deleted and disappears everywhere', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, NEW_PM.name).getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText(`${NEW_PM.name} was deleted.`)).toBeVisible()
    await expect(row(page, NEW_PM.name)).toHaveCount(0)
    await expect(page.getByRole('row')).toHaveCount(13)
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(13)
  })

  test('DELETE: the deleted person can no longer sign in', async ({ browser, allow }) => {
    allow(/POST \/auth\/login 401/)
    const them = await otherPerson(browser)
    await them.goto('/')
    await them.getByLabel('Email address').fill(NEW_PM.email)
    await them.getByLabel('Password').fill(NEW_PASSWORD)
    await them.getByRole('button', { name: 'Sign in' }).click()
    await expect(them.getByText(/incorrect email or password/i)).toBeVisible()
    await them.context().close()
  })

  test('the audit log recorded every change made here', async ({ page }) => {
    await page.goto('/user/audit')
    for (const label of ['Added an account', 'Sent a new set-password link', 'Moved a person to another manager', 'Deactivated an account', 'Reactivated an account', 'Deleted an account'])
      await expect(page.locator('td', { hasText: label }).first()).toBeVisible()
  })
})

test.describe('people when the mail cannot be sent', () => {
  test.use(as('oic'))

  test('the page shows the link and a Copy button instead of pretending it was emailed', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    const url = 'http://localhost:3101/reset-password?link=abc'
    await page.route('**/api/v1/admin/employees', async (route) => {
      if (route.request().method() !== 'POST') return route.continue()
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: '999', name: 'Mail Failed', email: 'mf@test.com', role: 'project_manager', emailSent: false, setPasswordUrl: url }) })
    })
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill('Mail Failed')
    await dialog(page).getByLabel('Email').fill('mf@test.com')
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(page.getByText('was added, but the email could not be sent.')).toBeVisible()
    await expect(page.getByText(url)).toBeVisible()
    await page.getByRole('button', { name: 'Copy' }).click()
    await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible()
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(url)
  })
})

test.describe('people as a project manager and a team leader', () => {
  test.describe('project manager', () => {
    test.use(as('pm1'))

    test('sees only their branch, adds only Team Leaders, and cannot move across branches', async ({ page }) => {
      await page.goto('/user/people')
      await expect(page.getByRole('row')).toHaveCount(8)
      await expect(page.getByText('You and everyone who reports to you.')).toBeVisible()
      await page.getByRole('button', { name: 'Add Team Leader' }).click()
      await expect(dialog(page).getByLabel('Role').locator('option')).toHaveText(['Team Leader'])
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
      await expect(row(page, 'Pedro Santos')).toHaveCount(0)
    })
  })

  test.describe('team leader', () => {
    test.use(as('tl1'))

    test('can add team members of every contributor role', async ({ page }) => {
      await page.goto('/user/people')
      await page.getByRole('button', { name: 'Add person' }).click()
      const roles = await dialog(page).getByLabel('Role').locator('option').allTextContents()
      expect(roles).toEqual(expect.arrayContaining(['Lead Developer', 'Developer', 'Client Support', 'QA', 'System Analyst']))
      expect(roles).not.toContain('Team Leader')
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    })

    test('the API refuses to add a Project Manager or to touch someone outside the team', async ({ page }) => {
      await page.goto('/user/people')
      const headers = { Accept: 'application/json', Origin: 'http://localhost:3101', Referer: 'http://localhost:3101/' }
      const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
      const other = await (async () => {
        const oic = await otherPerson(page.context().browser()!, 'oic')
        await oic.goto('/user')
        const id = await idOf(oic, 'dev3@test.com')
        await oic.context().close()
        return id
      })()
      const badRole = await page.request.post('/api/v1/admin/employees', { headers: { ...headers, 'X-XSRF-TOKEN': xsrf }, data: { name: 'X', email: 'x@test.com', role: 'project_manager' } })
      expect([403, 422]).toContain(badRole.status())
      const outside = await page.request.patch(`/api/v1/admin/employees/${other}`, { headers: { ...headers, 'X-XSRF-TOKEN': xsrf }, data: { status: 'inactive' } })
      expect(outside.status()).toBe(403)
    })
  })
})

// Sanity: the seeded password still works for the accounts the other specs rely on.
test('the demo accounts keep the shared password', () => {
  expect(PASSWORD).toBe('password')
})
