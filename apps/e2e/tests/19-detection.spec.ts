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
const SWITCH = 'Detection is on for this person'

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

// The activity check: what a day of software input looks like to the people who may see it. The level and reasons are
// written the way the server stores them after a sync (the rules themselves are covered by the PHP tests).
const REASON = 'Most mouse and keyboard input during 30 minutes was sent by software, not by a keyboard or mouse.'
const integrity = (level: 'strong' | 'review' | null) => artisan(
  'tinker',
  `--execute=$ids = App\\Models\\User::where('email', 'dev1@test.com')->pluck('id'); DB::table('daily_summaries')->whereIn('user_id', $ids)->update(['integrity_level' => ${level === null ? 'null' : `'${level}'`}, 'integrity_reasons' => ${level === null ? 'null' : `json_encode([['code' => 'software_input', 'message' => '${REASON}', 'minutes' => 30]])`}, 'macro_tools' => ${level === null ? 'null' : `json_encode(['autohotkey'])`}]);`,
)

test.describe.serial('activity check: who sees it and how the switch clears it', () => {
  test('the organization admin sees the level, the reasons and the fixed note', async ({ browser }) => {
    integrity('strong')
    const admin = await otherPerson(browser, 'oic')
    await admin.goto('/user')
    await expect(admin.getByRole('row').filter({ hasText: DAN }).getByText('Automation likely')).toBeVisible()

    await admin.getByRole('link', { name: DAN }).click()
    await expect(admin.getByRole('heading', { name: 'Activity check' })).toBeVisible()
    await expect(admin.getByText(REASON)).toBeVisible()
    await expect(admin.getByText('A flag is a reason to look, not proof.')).toBeVisible()
    await admin.context().close()
  })

  test('a team leader who may open the day sees nothing of it', async ({ browser }) => {
    integrity('strong')
    const lead = await otherPerson(browser, 'tl1')
    await lead.goto('/user')
    await expect(lead.getByRole('row').filter({ hasText: DAN })).toBeVisible()
    await expect(lead.getByText('Automation likely')).toHaveCount(0)
    await lead.getByRole('link', { name: DAN }).click()
    await expect(lead.getByRole('heading', { name: DAN })).toBeVisible()
    await expect(lead.getByRole('heading', { name: 'Activity check' })).toHaveCount(0)
    await expect(lead.getByText(REASON)).toHaveCount(0)
    await lead.context().close()
  })

  test.describe('the owner switches detection off', () => {
    test.use(as('admin'))

    test('the card goes with the data', async ({ page }) => {
      integrity('review')
      await page.goto('/platform')
      await page.getByRole('row').filter({ hasText: 'Demo Office' }).getByRole('link', { name: 'Demo Office' }).click()
      await page.getByRole('button', { name: 'Open office' }).click()
      await page.getByRole('link', { name: 'People', exact: true }).click()
      await page.getByRole('link', { name: DAN }).click()
      await expect(page.getByRole('heading', { name: 'Activity check' })).toBeVisible()
      await expect(page.getByText('Review activity').first()).toBeVisible()

      await page.getByLabel(SWITCH).click()
      await page.getByRole('dialog').getByRole('button', { name: 'Switch off' }).click()
      await expect(page.getByLabel(SWITCH)).not.toBeChecked()
      await expect(page.getByRole('heading', { name: 'Activity check' })).toHaveCount(0)

      await page.getByLabel(SWITCH).click()
      await page.getByRole('dialog').getByRole('button', { name: 'Switch on' }).click()
      await expect(page.getByLabel(SWITCH)).toBeChecked()
      integrity(null)
    })
  })
})
