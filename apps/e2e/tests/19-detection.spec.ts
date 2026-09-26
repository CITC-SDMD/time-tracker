import { as, expect, otherPerson, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

test.beforeEach(() => artisan('cache:clear'))

// Virtual machine detection (docs §16): what the desktop app reports is seen only by superadmins and the organization's
// admins; a superadmin with the permission switches it for one person. The desktop app is not part of this run, so the
// reported value is written to the database the way a sync stores it.
const flag = (environment: string | null) => artisan(
  'tinker',
  `--execute=$ids = App\\Models\\User::where('email', 'dev1@test.com')->pluck('id'); DB::table('employee_statuses')->whereIn('user_id', $ids)->update(['environment' => ${environment === null ? 'null' : `'${environment}'`}]); DB::table('daily_summaries')->whereIn('user_id', $ids)->update(['environment' => ${environment === null ? 'null' : `'${environment}'`}]);`,
)

const DAN = 'Dan Ramos'
const SWITCH = 'Virtual machine detection is on for this person'

test.describe.serial('detection: the superadmin switch', () => {
  test.use(as('admin'))

  test('the owner opens Dan\'s day inside the office and switches detection off and on', async ({ page }) => {
    flag('virtual_machine')

    await page.goto('/platform')
    await page.getByRole('row').filter({ hasText: 'Demo Office' }).getByRole('link', { name: 'Demo Office' }).click()
    await page.getByRole('button', { name: 'Open office' }).click()
    await page.getByRole('link', { name: 'People', exact: true }).click()
    await page.getByRole('link', { name: DAN }).click()

    await expect(page.getByRole('heading', { name: DAN })).toBeVisible()
    await expect(page.getByText('Virtual machine', { exact: true })).toBeVisible()
    await expect(page.getByLabel(SWITCH)).toBeChecked()

    // cancelling leaves it as it was
    await page.getByLabel(SWITCH).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click()
    await expect(page.getByLabel(SWITCH)).toBeChecked()

    await page.getByLabel(SWITCH).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Switch off' }).click()
    await expect(page.getByLabel(SWITCH)).not.toBeChecked()
    // what was stored is cleared
    await expect(page.getByText('Virtual machine', { exact: true })).toHaveCount(0)

    await page.getByLabel(SWITCH).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Switch on' }).click()
    await expect(page.getByLabel(SWITCH)).toBeChecked()
  })
})

test.describe('detection: who sees the flags', () => {
  test('the organization\'s admin sees the badge but has no switch', async ({ browser }) => {
    flag('virtual_machine')
    const admin = await otherPerson(browser, 'oic')
    await admin.goto('/user')
    await expect(admin.getByRole('row').filter({ hasText: DAN }).getByText('Virtual machine')).toBeVisible()
    await admin.getByRole('link', { name: DAN }).click()
    await expect(admin.getByText('Virtual machine', { exact: true }).first()).toBeVisible()
    await expect(admin.getByLabel(SWITCH)).toHaveCount(0)
    await admin.context().close()
  })

  test('a team leader who may open Dan\'s day sees no flag at all', async ({ browser }) => {
    flag('virtual_machine')
    const lead = await otherPerson(browser, 'tl1')
    await lead.goto('/user')
    await expect(lead.getByRole('row').filter({ hasText: DAN })).toBeVisible()
    await expect(lead.getByText('Virtual machine')).toHaveCount(0)
    await lead.getByRole('link', { name: DAN }).click()
    await expect(lead.getByRole('heading', { name: DAN })).toBeVisible()
    await expect(lead.getByText('Virtual machine')).toHaveCount(0)
    await expect(lead.getByLabel(SWITCH)).toHaveCount(0)
    await lead.context().close()
    flag(null)
  })
})
