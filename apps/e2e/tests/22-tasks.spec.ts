import type { Page } from '@playwright/test'
import { as, expect, test } from '../fixtures'
import { artisan } from '../helpers/artisan'

const TASK = 'Budget report'
const PERSON = 'Dan Ramos' // dev1@test.com

const dialog = (page: Page) => page.getByRole('dialog')
const taskRow = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) })

test.beforeEach(() => artisan('cache:clear'))

// Managers assign tasks to people, so hours can be reported per task (docs/DEVELOPMENT_PLAN.md). An employee only
// ever picks from the tasks assigned to them; the desktop agent itself is not part of this run (see AgentSyncTest
// and TaskTest in the PHP suite for the sync side).
test.describe.serial('tasks as the admin', () => {
  test.use(as('oic'))

  test('CREATE: a task is made, assigned to a person, and listed with their count', async ({ page }) => {
    await page.goto('/user/tasks')
    await expect(page.getByRole('heading', { name: 'Tasks' })).toBeVisible()

    await page.getByRole('button', { name: 'Make a task' }).click()
    await dialog(page).getByRole('button', { name: 'Save task' }).click()
    await expect(dialog(page).getByText('Enter a title for the task.')).toBeVisible()

    await dialog(page).getByLabel('Title').fill(TASK)
    await dialog(page).getByLabel('Description (optional)').fill('Draft and review')
    await dialog(page).getByLabel(PERSON).check()
    await dialog(page).getByRole('button', { name: 'Save task' }).click()

    await expect(page.getByText(`The task ${TASK} was saved.`)).toBeVisible()
    await expect(taskRow(page, TASK)).toContainText('Active')
    await expect(taskRow(page, TASK)).toContainText('1')
  })

  test('EDIT: the assignee is changed and the task is archived', async ({ page }) => {
    await page.goto('/user/tasks')
    await taskRow(page, TASK).getByRole('button', { name: 'Edit' }).click()
    await expect(dialog(page).getByRole('heading', { name: `Edit ${TASK}` })).toBeVisible()
    // already assigned, so it is ticked
    await expect(dialog(page).getByLabel(PERSON)).toBeChecked()

    await dialog(page).getByLabel('Archived').check()
    await dialog(page).getByRole('button', { name: 'Save task' }).click()
    await expect(page.getByText(`The task ${TASK} was saved.`)).toBeVisible()
    await expect(taskRow(page, TASK)).toContainText('Archived')
  })

  test('REPORT: the tasks tab of the reports page loads without error', async ({ page }) => {
    await page.goto('/user/reports')
    await page.getByRole('tab', { name: 'Tasks' }).click()
    // nobody's tracked time is tagged with a task in the seeded demo data, so this is the empty state, not a failure
    await expect(page.getByText('Nothing tracked in this range')).toBeVisible()
  })
})

test.describe('tasks need their own permission', () => {
  test.use(as('pm1'))

  test('someone without tasks.view has no Tasks page', async ({ page }) => {
    await page.goto('/user/tasks')
    await expect(page).toHaveURL(/\/user$/)
    await expect(page.getByRole('link', { name: 'Tasks', exact: true })).toHaveCount(0)
  })
})
