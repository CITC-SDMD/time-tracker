import { DASH_HEADERS, as, expect, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

test.beforeEach(() => artisan('cache:clear'))

// Adding many people from a CSV file, as the admin of the other organization (so the demo office's counts stay as
// the other specs expect them). The file is checked in the drawer first; the API then adds all rows or none.
test.describe.serial('import people from a file', () => {
  test.use(as('adminB'))

  const csv = (text: string) => ({ name: 'people.csv', mimeType: 'text/csv', buffer: Buffer.from(text) })

  test('a file without the columns is refused before anything is sent', async ({ page }) => {
    await page.goto('/user/people')
    await page.getByRole('button', { name: 'Import from a file' }).click()
    const drawer = page.getByRole('dialog')

    await drawer.getByLabel('CSV file').setInputFiles(csv('who,mail\nAnn,ann@x.com\n'))
    await drawer.getByRole('button', { name: 'Add and email links' }).click()
    await expect(drawer.getByText('Missing: name, email, role')).toBeVisible()

    await drawer.getByLabel('CSV file').setInputFiles(csv('name,email,role\nAnn,ann@x.com,No Such Role\n'))
    await drawer.getByRole('button', { name: 'Add and email links' }).click()
    await expect(drawer.getByText('"No Such Role" is not a role you can give')).toBeVisible()
  })

  test('one wrong row adds nobody and names the row', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/admin\/employees\/import 422/)
    await page.goto('/user/people')
    const roles = await (await page.request.get('/api/v1/roles', { headers: DASH_HEADERS })).json() as { name: string, assignable: boolean }[]
    const role = roles.find(r => r.assignable)!.name

    await page.getByRole('button', { name: 'Import from a file' }).click()
    const drawer = page.getByRole('dialog')
    await drawer.getByLabel('CSV file').setInputFiles(csv(`name,email,role\nGood One,good.import@test.com,${role}\nBad Two,admin.b@test.com,${role}\n`))
    await drawer.getByRole('button', { name: 'Add and email links' }).click()

    await expect(drawer.getByText('Nobody was added.')).toBeVisible()
    await expect(drawer.getByText(/Row 2: A user with the email admin.b@test.com already exists/)).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('link', { name: 'Good One' })).toHaveCount(0)
  })

  test('a good file adds everyone and they show up in the list', async ({ page }) => {
    await page.goto('/user/people')
    const roles = await (await page.request.get('/api/v1/roles', { headers: DASH_HEADERS })).json() as { name: string, assignable: boolean }[]
    const role = roles.find(r => r.assignable)!.name

    await page.getByRole('button', { name: 'Import from a file' }).click()
    const drawer = page.getByRole('dialog')
    await drawer.getByLabel('CSV file').setInputFiles(csv(`name,email,role,manager_email\nIna Import,ina.import@test.com,${role},\n"Ito, Import",ito.import@test.com,${role},lead.b@test.com\n`))
    await expect(drawer.getByText('2 people ready to add.')).toBeVisible()
    await drawer.getByRole('button', { name: 'Add and email links' }).click()

    await expect(page.getByText('2 people were added.')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Ina Import', exact: true })).toBeVisible()
    await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Ito, Import', exact: true }) })).toContainText('Lena Lead')
  })

  test('a manager can be someone further down the same file, and a circle is refused', async ({ page, allow }) => {
    allow(/POST \/api\/v1\/admin\/employees\/import 422/)
    await page.goto('/user/people')
    const roles = await (await page.request.get('/api/v1/roles', { headers: DASH_HEADERS })).json() as { name: string, assignable: boolean }[]
    const role = roles.find(r => r.assignable)!.name

    await page.getByRole('button', { name: 'Import from a file' }).click()
    const drawer = page.getByRole('dialog')
    await drawer.getByLabel('CSV file').setInputFiles(csv(`name,email,role,manager_email
Circ A,circ.a@test.com,${role},circ.b@test.com
Circ B,circ.b@test.com,${role},circ.a@test.com
`))
    await drawer.getByRole('button', { name: 'Add and email links' }).click()
    await expect(drawer.getByText(/report to each other in a circle/).first()).toBeVisible()
    await page.keyboard.press('Escape')

    await page.getByRole('button', { name: 'Import from a file' }).click()
    await drawer.getByLabel('CSV file').setInputFiles(csv(`name,email,role,manager_email
Late Junior,late.junior@test.com,${role},late.senior@test.com
Late Senior,late.senior@test.com,${role},
`))
    await drawer.getByRole('button', { name: 'Add and email links' }).click()

    await expect(page.getByText('2 people were added.')).toBeVisible()
    await expect(page.getByRole('row').filter({ has: page.getByRole('link', { name: 'Late Junior', exact: true }) })).toContainText('Late Senior')
  })
})
