<template>
  <div>
    <UiPageHeader
      title="Tasks"
      description="What people are working on, so hours can be reported per task. An employee only ever sees the tasks assigned to them."
    >
      <template
        v-if="canManage"
        #actions
      >
        <FormButton @click="openAdd">
          Make a task
        </FormButton>
      </template>
    </UiPageHeader>

    <UiAlert
      v-if="notice"
      :variant="notice.variant"
      class="mb-6"
    >
      {{ notice.text }}
    </UiAlert>

    <UiTable
      :columns="COLUMNS"
      :rows="tasks"
      :loading="!loaded"
      :error="loaded ? null : error"
      empty-title="No tasks yet"
      empty-description="Make one and assign it to the people working on it."
    >
      <template #cell-title="{ row }">
        <span class="font-medium text-gray-900 dark:text-white">{{ row.title }}</span>
        <span
          v-if="row.description"
          class="block text-xs text-gray-500 dark:text-gray-400"
        >{{ row.description }}</span>
      </template>
      <template #cell-status="{ row }">
        <UiBadge :variant="TASK_STATUS_VARIANT[row.status as TaskItem['status']]">
          {{ TASK_STATUS_LABEL[row.status as TaskItem['status']] }}
        </UiBadge>
      </template>
      <template #cell-assigneeCount="{ row }">
        {{ row.assigneeCount }}
        <span
          v-if="row.assigneeCount"
          class="text-gray-500 dark:text-gray-400"
        >({{ row.completedCount }} done)</span>
      </template>
      <template #cell-actions="{ row }">
        <div
          v-if="canManage"
          class="flex justify-end"
        >
          <FormButton
            variant="link"
            @click="openEdit(row as TaskItem)"
          >
            Edit
          </FormButton>
        </div>
      </template>
    </UiTable>

    <UiDrawer
      v-model="formOpen"
      :title="editing ? `Edit ${editing.title}` : 'Make a task'"
      :persistent="saving"
    >
      <form
        class="space-y-5"
        novalidate
        @submit.prevent="submit"
      >
        <FormInput
          v-model="form.title"
          label="Title"
          autocomplete="off"
          :errors="v$.title.$errors"
          @blur="v$.title.$touch()"
        />
        <FormInput
          v-model="form.description"
          label="Description (optional)"
          autocomplete="off"
          :errors="v$.description.$errors"
          @blur="v$.description.$touch()"
        />
        <FormRadioGroup
          v-if="editing"
          v-model="form.status"
          label="Status"
          name="task-status"
          :options="STATUS_OPTIONS"
        />
        <FormEmployeePicker
          v-model="form.assigneeIds"
          label="Assigned to"
          :people="people"
        />
        <div
          v-if="editing && editing.assignees.length"
          class="space-y-2"
        >
          <p class="text-sm/6 font-medium text-gray-900 dark:text-gray-100">
            Completion
          </p>
          <ul class="divide-y divide-gray-200 rounded-md border border-gray-200 dark:divide-white/10 dark:border-white/10">
            <li
              v-for="person in editing.assignees"
              :key="person.id"
              class="flex items-center justify-between gap-3 px-3 py-2"
            >
              <span class="text-sm text-gray-900 dark:text-white">{{ person.name }}</span>
              <div class="flex items-center gap-2">
                <UiBadge :variant="ASSIGNEE_COMPLETION_VARIANT[person.completedAt ? 'done' : 'not_done']">
                  {{ ASSIGNEE_COMPLETION_LABEL[person.completedAt ? 'done' : 'not_done'] }}
                </UiBadge>
                <FormButton
                  variant="link"
                  :loading="completing === person.id"
                  @click="toggleCompletion(person.id, !person.completedAt)"
                >
                  {{ person.completedAt ? 'Reopen' : 'Mark complete' }}
                </FormButton>
              </div>
            </li>
          </ul>
        </div>
        <FormError v-if="formError">
          {{ formError }}
        </FormError>
        <div class="flex justify-end gap-3 pt-2">
          <FormButton
            variant="secondary"
            :disabled="saving"
            @click="formOpen = false"
          >
            Cancel
          </FormButton>
          <FormButton
            type="submit"
            :loading="saving"
          >
            {{ saving ? 'Saving…' : 'Save task' }}
          </FormButton>
        </div>
      </form>
    </UiDrawer>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, maxLength, required } from '@vuelidate/validators'
import type { EmployeeListItem, TaskItem } from 'shared'
import { ASSIGNEE_COMPLETION_LABEL, ASSIGNEE_COMPLETION_VARIANT, TASK_STATUS_LABEL, TASK_STATUS_VARIANT } from '~/utils/labels'

definePageMeta({
  layout: 'user',
  permission: 'tasks.view',
  alias: ['/platform/organizations/:orgId/office/tasks'],
})

// Tasks (docs/DEVELOPMENT_PLAN.md): managing them (tasks.manage) is organization-wide, like roles, so it is not
// limited to a reach; assigning a task to someone still is (the API refuses anyone out of the caller's reach).
const { api } = useApi()
const { can, readOnly } = useAccess()

const canManage = computed(() => can('tasks.manage') && !readOnly.value)

const COLUMNS = [
  { key: 'title', label: 'Task' },
  { key: 'status', label: 'Status' },
  { key: 'assigneeCount', label: 'Assigned to', align: 'right' as const },
  { key: 'actions', label: '', align: 'right' as const },
]

const STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'archived', label: 'Archived' },
]

const tasks = ref<TaskItem[]>([])
const people = ref<Array<{ id: string, name: string }>>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const notice = ref<{ variant: 'success' | 'danger', text: string } | null>(null)

async function load() {
  try {
    const [list, employees] = await Promise.all([
      api<TaskItem[]>('/tasks'),
      api<EmployeeListItem[]>('/employees'),
    ])
    tasks.value = list
    people.value = employees.map(e => ({ id: e.id, name: e.name }))
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the tasks.')
  }
  finally {
    loaded.value = true
  }
}

onMounted(load)

// ---- make or edit -----------------------------------------------------------------------------

const formOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const editing = ref<TaskItem | null>(null)
const form = reactive({ title: '', description: '', status: 'active' as TaskItem['status'], assigneeIds: [] as string[] })

const rules = {
  title: {
    required: helpers.withMessage('Enter a title for the task.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  description: { maxLength: helpers.withMessage('Use at most 1000 characters.', maxLength(1000)) },
}
const v$ = useVuelidate(rules, form)

function openAdd() {
  editing.value = null
  form.title = ''
  form.description = ''
  form.status = 'active'
  form.assigneeIds = []
  formError.value = null
  v$.value.$reset()
  formOpen.value = true
}

function openEdit(task: TaskItem) {
  editing.value = task
  form.title = task.title
  form.description = task.description ?? ''
  form.status = task.status
  form.assigneeIds = [...task.assigneeIds]
  formError.value = null
  v$.value.$reset()
  formOpen.value = true
}

async function submit() {
  if (!(await v$.value.$validate()))
    return
  saving.value = true
  formError.value = null
  const body = {
    title: form.title.trim(),
    description: form.description.trim() || null,
    assigneeIds: form.assigneeIds,
    ...(editing.value ? { status: form.status } : {}),
  }
  try {
    if (editing.value)
      await api(`/tasks/${editing.value.id}`, { method: 'PATCH', body })
    else
      await api('/tasks', { method: 'POST', body })
    notice.value = { variant: 'success', text: `The task ${body.title} was saved.` }
    formOpen.value = false
    await load()
  }
  catch (e) {
    formError.value = messageOf(e, 'Could not save the task.')
  }
  finally {
    saving.value = false
  }
}

// ---- completion (per person, not part of the form save) ---------------------------------------

const completing = ref<string | null>(null)

async function toggleCompletion(userId: string, completed: boolean) {
  if (!editing.value)
    return
  completing.value = userId
  formError.value = null
  try {
    const updated = await api<TaskItem>(`/tasks/${editing.value.id}/assignments/${userId}`, { method: 'PATCH', body: { completed } })
    editing.value = updated
    const index = tasks.value.findIndex(t => t.id === updated.id)
    if (index !== -1)
      tasks.value[index] = updated
  }
  catch (e) {
    formError.value = messageOf(e, 'Could not update completion.')
  }
  finally {
    completing.value = null
  }
}
</script>
