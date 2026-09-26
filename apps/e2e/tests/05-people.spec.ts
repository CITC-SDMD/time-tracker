import type { Page } from '@playwright/test'
import { ACCOUNTS, DASH_HEADERS, PASSWORD, as, expect, idOf, loginAs, otherPerson, roleIdOf, test } from '../fixtures'
import { artisan } from '../helpers/artisan'
import { mailLinkCount, nextMailLink } from '../helpers/mail'

const NEW_PERSON = { name: 'Quentin Estrada', email: 'quentin.e2e@test.com' }
const NEW_PASSWORD = 'quentin-secret-99'

// a person's row is found by the link on their name, since the Manager column repeats other names
const row = (page: Page, name: string) => page.getByRole('row').filter({ has: page.getByRole('link', { name, exact: true }) })
const openAdd = (page: Page) => page.getByRole('button', { name: 'Add a person' }).click()
const dialog = (page: Page) => page.getByRole('dialog')
const options = (page: Page, label: string) => dialog(page).getByLabel(label).locator('option').allTextContents()

test.beforeEach(() => artisan('cache:clear'))

// Add, read, change and delete the accounts of an organization, the way its admin does. There are no tiers: any role can
// report to any person, and the roles offered are the ones the organization made.
test.describe.serial('people as the admin', () => {
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
    await expect(row(page, 'Dan Ramos')).toContainText('dev1@test.com')
    await expect(row(page, 'Dan Ramos')).toContainText('Active')
    await expect(row(page, 'OIC')).toContainText('(you)')
    // the people of the other organization are not here
    await expect(page.getByText('Bea Admin')).toHaveCount(0)
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

  test('your own row has no actions, and every role of the organization can be given', async ({ page }) => {
    await page.goto('/user/people')
    await expect(row(page, 'OIC').getByRole('button')).toHaveCount(0)
    await openAdd(page)
    const roles = await options(page, 'Role')
    expect(roles).toEqual(expect.arrayContaining(['Choose a role', 'Project Manager', 'Team Leader', 'Lead Developer', 'Developer', 'Client Support', 'QA', 'System Analyst']))
    // who they will report to: anyone active, the admin themselves first by default
    await expect(dialog(page).getByLabel('Reports to')).toHaveValue(/\d+/)
    expect(await options(page, 'Reports to')).toEqual(expect.arrayContaining(['OIC (you)', 'Paula Reyes (Project Manager)', 'Dan Ramos (Lead Developer)', 'No manager']))
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
    await expect(dialog(page).getByText('Choose a role.')).toBeVisible()
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

  test('CREATE: an email already used, here or in another organization, is refused with the server\'s message', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/admin\/employees (409|422)/)
    await page.goto('/user/people')
    for (const email of ['pm1@test.com', 'Admin.B@test.com']) {
      await openAdd(page)
      await dialog(page).getByLabel('Full name').fill('Another Person')
      await dialog(page).getByLabel('Email').fill(email)
      await dialog(page).getByLabel('Role').selectOption({ label: 'Developer' })
      await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
      await expect(dialog(page).getByText(/already|taken|in use/i)).toBeVisible()
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    }
  })

  test('CREATE: a new Project Manager is added, listed under the admin, and emailed a link', async ({ page }) => {
    const before = mailLinkCount()
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill(`  ${NEW_PERSON.name}  `)
    await dialog(page).getByLabel('Email').fill(NEW_PERSON.email)
    await dialog(page).getByLabel('Role').selectOption({ label: 'Project Manager' })
    await dialog(page).getByRole('button', { name: 'Add and email link' }).click()
    await expect(dialog(page)).toHaveCount(0)
    await expect(page.getByText(`We emailed a set-password link to ${NEW_PERSON.email}.`)).toBeVisible()
    await expect(row(page, NEW_PERSON.name)).toContainText('Project Manager')
    await expect(row(page, NEW_PERSON.name)).toContainText('OIC')
    await expect(row(page, NEW_PERSON.name)).toContainText('Active')
    await expect(page.getByRole('row')).toHaveCount(14)
    const link = await nextMailLink(before)
    expect(link).toContain('/reset-password?link=')
  })

  test('CREATE: the new person picks a password from the emailed link and sees what their role allows', async ({ browser }) => {
    const before = mailLinkCount() - 1 // the welcome mail of the previous test is the newest one
    const link = await nextMailLink(Math.max(before, 0))
    const them = await otherPerson(browser)
    await them.goto(link)
    await them.getByLabel('New password (at least 10 characters)').fill(NEW_PASSWORD)
    await them.getByLabel('Type it again').fill(NEW_PASSWORD)
    await them.getByRole('button', { name: 'Set password' }).click()
    await expect(them.getByText('Your password is set.')).toBeVisible()
    await loginAs(them, NEW_PERSON.email, NEW_PASSWORD)
    await expect(them).toHaveURL(/\/user$/)
    // a Project Manager role: people and reports, no settings, audit log or roles
    for (const name of ['People', 'Reports']) await expect(them.getByRole('link', { name, exact: true }).first()).toBeVisible()
    for (const name of ['Settings', 'Audit log', 'Roles']) await expect(them.getByRole('link', { name, exact: true })).toHaveCount(0)
    await them.goto('/user/people')
    await expect(them.getByRole('button', { name: 'Add a person' })).toBeVisible()
    await them.context().close()
  })

  test('CREATE: the new person is now in the organization totals', async ({ page }) => {
    await page.goto('/user')
    await expect(page.getByRole('row').filter({ hasText: NEW_PERSON.name })).toBeVisible()
    await expect(page.getByRole('row')).toHaveCount(14)
  })

  // ---- RESEND --------------------------------------------------------------------------------

  test('UPDATE: Resend link sends a fresh link that works', async ({ page, browser }) => {
    const before = mailLinkCount()
    await page.goto('/user/people')
    await row(page, NEW_PERSON.name).getByRole('button', { name: 'Resend link' }).click()
    await expect(page.getByText(`We emailed a new set-password link to ${NEW_PERSON.email}.`)).toBeVisible()
    const link = await nextMailLink(before)
    const them = await otherPerson(browser)
    await them.goto(link)
    await expect(them.getByText(`For ${NEW_PERSON.email}`)).toBeVisible()
    await them.context().close()
  })

  // ---- UPDATE: move --------------------------------------------------------------------------

  test('UPDATE: the move form offers anyone active who is not above the loop, and asks for a choice', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Move Tess Lim' })).toBeVisible()
    await expect(dialog(page).getByText('currently reports to Pedro Santos')).toBeVisible()
    const managers = await options(page, 'New manager')
    // no tiers: team leaders and members can be managers too. Never herself, her current manager, or her own team.
    expect(managers).toEqual(expect.arrayContaining(['Choose a manager', 'Paula Reyes (Project Manager)', 'Tina Cruz (Team Leader)', 'Dan Ramos (Lead Developer)', `${NEW_PERSON.name} (Project Manager)`, 'No manager']))
    expect(managers.some(o => o.startsWith('OIC'))).toBe(true)
    for (const gone of ['Tess Lim', 'Pedro Santos', 'Cara Sy', 'Sam Ong'])
      expect(managers.some(o => o.startsWith(gone)), gone).toBe(false)
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

  test('UPDATE: a person can be put under someone of any role, and back', async ({ page }) => {
    await page.goto('/user/people')
    // a team leader under a lead developer: nothing forbids it
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Dan Ramos (Lead Developer)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, 'Tess Lim')).toContainText('Dan Ramos')
    await row(page, 'Tess Lim').getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'Pedro Santos (Project Manager)' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, 'Tess Lim')).toContainText('Pedro Santos')
  })

  test('UPDATE: nobody is offered a manager below them, so no loop can be made', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Paula Reyes').getByRole('button', { name: 'Move' }).click()
    const managers = await options(page, 'New manager')
    for (const below of ['Tina Cruz', 'Tomas Diaz', 'Dan Ramos', 'Dana Uy', 'Dex Tan', 'Quinn Go'])
      expect(managers.some(o => o.startsWith(below)), below).toBe(false)
    expect(managers).toContain('Pedro Santos (Project Manager)')
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
  })

  test('UPDATE: someone reaching the whole organization can leave a person without a manager, and put them back', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, NEW_PERSON.name).getByRole('button', { name: 'Move' }).click()
    await dialog(page).getByLabel('New manager').selectOption({ label: 'No manager' })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(page.getByText(`${NEW_PERSON.name} no longer reports to anyone.`)).toBeVisible()
    await expect(row(page, NEW_PERSON.name)).toContainText('—')
    await row(page, NEW_PERSON.name).getByRole('button', { name: 'Move' }).click()
    const admin = (await options(page, 'New manager')).find(o => o.startsWith('OIC ('))!
    await dialog(page).getByLabel('New manager').selectOption({ label: admin })
    await dialog(page).getByRole('button', { name: 'Move', exact: true }).click()
    await expect(row(page, NEW_PERSON.name)).toContainText('OIC')
  })

  // ---- UPDATE: change a role -----------------------------------------------------------------

  test('UPDATE role: the form offers the other roles of the organization and never asks for a manager', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Sam Ong').getByRole('button', { name: 'Change role' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Change the role of Sam Ong' })).toBeVisible()
    const roles = await options(page, 'New role')
    expect(roles).toEqual(expect.arrayContaining(['Choose a role', 'Project Manager', 'Team Leader', 'Lead Developer', 'Developer', 'Client Support', 'QA']))
    expect(roles).not.toContain('System Analyst') // the role Sam already has
    await expect(dialog(page).getByLabel('Reports to')).toHaveCount(0)
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(dialog(page).getByText('Choose the new role.')).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, 'Sam Ong')).toContainText('System Analyst')
  })

  test('UPDATE role: a change keeps the manager, and is put back', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Sam Ong').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'QA' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(page.getByText('Sam Ong is now QA.')).toBeVisible()
    await expect(row(page, 'Sam Ong')).toContainText('QA')
    await expect(row(page, 'Sam Ong')).toContainText('Tess Lim')

    await row(page, 'Sam Ong').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'System Analyst' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(row(page, 'Sam Ong')).toContainText('System Analyst')
  })

  test('UPDATE role: a developer becomes a Team Leader without moving, then goes back', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Dana Uy').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'Team Leader' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(page.getByText('Dana Uy is now Team Leader.')).toBeVisible()
    await expect(row(page, 'Dana Uy')).toContainText('Team Leader')
    await expect(row(page, 'Dana Uy')).toContainText('Tina Cruz') // still reports to Tina

    await row(page, 'Dana Uy').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'Developer' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(row(page, 'Dana Uy')).toContainText('Developer')
  })

  test('UPDATE role: a manager with a team can take another role, the team stays with them', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tina Cruz').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'Project Manager' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(row(page, 'Tina Cruz')).toContainText('Project Manager')
    await expect(row(page, 'Dan Ramos')).toContainText('Tina Cruz')
    await row(page, 'Tina Cruz').getByRole('button', { name: 'Change role' }).click()
    await dialog(page).getByLabel('New role').selectOption({ label: 'Team Leader' })
    await dialog(page).getByRole('button', { name: 'Change role', exact: true }).click()
    await expect(row(page, 'Tina Cruz')).toContainText('Team Leader')
  })

  // ---- UPDATE: deactivate / reactivate -------------------------------------------------------

  test('UPDATE: Cancel on the deactivate dialog changes nothing', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, 'Tomas Diaz').getByRole('button', { name: 'Deactivate' }).click()
    await expect(dialog(page).getByRole('heading', { name: 'Deactivate Tomas Diaz?' })).toBeVisible()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, 'Tomas Diaz')).toContainText('Active')
  })

  test('UPDATE: deactivating a person signs them out and blocks sign-in, reactivating restores it', async ({ page, browser, allow }) => {
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
    await tomas.goto('/user')
    await expect(tomas.getByRole('heading', { name: 'Overview' })).toBeVisible()
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
    await row(page, NEW_PERSON.name).getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(row(page, NEW_PERSON.name)).toBeVisible()
  })

  test('DELETE: an unused account is deleted and disappears everywhere', async ({ page }) => {
    await page.goto('/user/people')
    await row(page, NEW_PERSON.name).getByRole('button', { name: 'Delete' }).click()
    await dialog(page).getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByText(`${NEW_PERSON.name} was deleted.`)).toBeVisible()
    await expect(row(page, NEW_PERSON.name)).toHaveCount(0)
    await expect(page.getByRole('row')).toHaveCount(13)
    await page.goto('/user')
    await expect(page.getByRole('row')).toHaveCount(13)
  })

  test('DELETE: the deleted person can no longer sign in', async ({ browser, allow }) => {
    allow(/POST \/auth\/login 401/)
    const them = await otherPerson(browser)
    await them.goto('/')
    await them.getByLabel('Email address').fill(NEW_PERSON.email)
    await them.getByLabel('Password').fill(NEW_PASSWORD)
    await them.getByRole('button', { name: 'Sign in' }).click()
    await expect(them.getByText(/incorrect email or password/i)).toBeVisible()
    await them.context().close()
  })

  test('the audit log recorded every change made here', async ({ page }) => {
    await page.goto('/user/audit')
    for (const label of ['Added an account', 'Sent a new set-password link', 'Moved a person to another manager', 'Changed a role', 'Deactivated an account', 'Reactivated an account', 'Deleted an account'])
      await expect(page.locator('td', { hasText: label }).first()).toBeVisible()
    // the role change says what became what
    await expect(page.getByRole('row').filter({ hasText: 'Changed a role' }).filter({ hasText: 'System Analyst → QA' })).toBeVisible()
    await expect(page.getByRole('row').filter({ hasText: 'Changed a role' }).filter({ hasText: 'Developer → Team Leader' })).toBeVisible()
    // and a move says who it was to
    await expect(page.getByRole('row').filter({ hasText: 'Moved a person to another manager' }).filter({ hasText: 'no one' }).first()).toBeVisible()
  })
})

test.describe('people when the mail cannot be sent', () => {
  test.use(as('oic'))

  test('the page shows the link and a Copy button instead of pretending it was emailed', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write'])
    const url = 'http://localhost:3101/reset-password?link=abc'
    await page.route('**/api/v1/admin/employees', async (route) => {
      if (route.request().method() !== 'POST') return route.continue()
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: '999', name: 'Mail Failed', email: 'mf@test.com', role: 'Project Manager', roleId: '2', emailSent: false, setPasswordUrl: url }) })
    })
    await page.goto('/user/people')
    await openAdd(page)
    await dialog(page).getByLabel('Full name').fill('Mail Failed')
    await dialog(page).getByLabel('Email').fill('mf@test.com')
    await dialog(page).getByLabel('Role').selectOption({ label: 'Project Manager' })
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

    test('sees only their branch, adds people under themselves, and is never offered the admin role', async ({ page }) => {
      await page.goto('/user/people')
      await expect(page.getByRole('row')).toHaveCount(8)
      await expect(page.getByText('You and everyone who reports to you.')).toBeVisible()
      await openAdd(page)
      const roles = await options(page, 'Role')
      expect(roles).toEqual(expect.arrayContaining(['Team Leader', 'Project Manager', 'Developer', 'QA']))
      expect(roles).not.toContain('OIC') // holds settings, the audit log and roles: more than a project manager has
      expect(roles).not.toContain('Admin')
      // reaching a team, they cannot leave someone without a manager
      expect(await options(page, 'Reports to')).not.toContain('No manager')
      await expect(dialog(page).getByLabel('Reports to')).toHaveValue(/\d+/)
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
      await expect(row(page, 'Pedro Santos')).toHaveCount(0)
    })

    test('can give any role up to their own, but never touches the admin', async ({ page }) => {
      await page.goto('/user/people')
      await row(page, 'Dana Uy').getByRole('button', { name: 'Change role' }).click()
      const roles = await options(page, 'New role')
      expect(roles).toEqual(expect.arrayContaining(['Choose a role', 'Team Leader', 'Project Manager', 'Lead Developer', 'Client Support', 'QA', 'System Analyst']))
      expect(roles).not.toContain('Developer') // the role Dana has
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
      // the admin is not in their reach at all
      await expect(row(page, 'OIC')).toHaveCount(0)
    })
  })

  test.describe('team leader', () => {
    test.use(as('tl1'))

    test('can add team members of any role their own role allows', async ({ page }) => {
      await page.goto('/user/people')
      await openAdd(page)
      const roles = await options(page, 'Role')
      expect(roles).toEqual(expect.arrayContaining(['Lead Developer', 'Developer', 'Client Support', 'QA', 'System Analyst']))
      expect(roles).not.toContain('OIC')
      await dialog(page).getByRole('button', { name: 'Cancel' }).click()
    })

    test('the API refuses the admin role and anyone outside the team', async ({ page }) => {
      await page.goto('/user/people')
      const xsrf = decodeURIComponent((await page.context().cookies()).find(c => c.name === 'XSRF-TOKEN')?.value ?? '')
      const headers = { ...DASH_HEADERS, 'X-XSRF-TOKEN': xsrf }
      const admin = await otherPerson(page.context().browser()!, 'oic')
      await admin.goto('/user')
      const other = await idOf(admin, 'dev3@test.com')
      const adminRoleId = await roleIdOf(admin, 'Admin').catch(() => roleIdOf(admin, 'OIC'))
      await admin.context().close()
      const tooMuch = await page.request.post('/api/v1/admin/employees', { headers, data: { name: 'X', email: 'x@test.com', roleId: adminRoleId } })
      expect(tooMuch.status()).toBe(403)
      expect((await tooMuch.json()).error.code).toBe('ROLE_ESCALATION')
      const outside = await page.request.patch(`/api/v1/admin/employees/${other}`, { headers, data: { status: 'inactive' } })
      expect(outside.status()).toBe(403)
    })
  })
})

test.describe('someone whose role has no permissions', () => {
  test('has no People page: it sends them back to their own overview', async ({ page }) => {
    await loginAs(page, ACCOUNTS.dev1)
    await page.goto('/user/people')
    await expect(page).toHaveURL(/\/user$/)
  })
})

// Sanity: the seeded password still works for the accounts the other specs rely on.
test('the demo accounts keep the shared password', () => {
  expect(PASSWORD).toBe('password')
})
