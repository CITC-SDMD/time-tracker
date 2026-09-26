<template>
  <UiDrawer
    v-model="open"
    title="Import people from a file"
    :persistent="busy"
  >
    <form
      class="space-y-5"
      novalidate
      @submit.prevent="submit"
    >
      <p class="text-sm/6 text-gray-500 dark:text-gray-400">
        A CSV file with a header row and the columns
        <strong class="font-semibold text-gray-900 dark:text-white">name</strong>,
        <strong class="font-semibold text-gray-900 dark:text-white">email</strong>,
        <strong class="font-semibold text-gray-900 dark:text-white">role</strong> and, optionally,
        <strong class="font-semibold text-gray-900 dark:text-white">manager_email</strong>
        (left blank, they report to you). Up to {{ MAX_ROWS }} people. If any row is wrong, nobody is added.
      </p>
      <p class="text-sm/6 text-gray-500 dark:text-gray-400">
        Roles you can give: {{ roles.map(r => r.name).join(', ') || 'none' }}.
      </p>

      <FormFileInput
        v-model="file"
        label="CSV file"
        accept=".csv,text/csv"
        :errors="v$.file.$errors"
        @blur="v$.file.$touch()"
      />

      <p
        v-if="parsed.rows.length && !parsed.problems.length"
        class="text-sm/6 text-gray-700 dark:text-gray-300"
      >
        {{ parsed.rows.length }} {{ parsed.rows.length === 1 ? 'person' : 'people' }} ready to add.
      </p>

      <UiAlert
        v-if="serverProblems.length"
        variant="danger"
      >
        <p>Some rows need fixing. Nobody was added.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <li
            v-for="problem in serverProblems"
            :key="`${problem.row}-${problem.message}`"
          >
            Row {{ problem.row }}: {{ problem.message }}
          </li>
        </ul>
      </UiAlert>
      <FormError v-else-if="formError">
        {{ formError }}
      </FormError>

      <div class="flex justify-end gap-3 pt-2">
        <FormButton
          variant="secondary"
          :disabled="busy"
          @click="open = false"
        >
          Cancel
        </FormButton>
        <FormButton
          type="submit"
          :loading="busy"
        >
          {{ busy ? 'Adding…' : 'Add and email links' }}
        </FormButton>
      </div>
    </form>
  </UiDrawer>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers } from '@vuelidate/validators'
import type { ImportedPeople, RoleItem } from 'shared'

// Adds many people from a CSV file (docs/DEVELOPMENT_PLAN.md §9.1). The file is read here and checked (columns, emails,
// role names) before anything is sent; the API then checks every row again and adds all of them or none.
const props = defineProps<{ roles: RoleItem[] }>()
const emit = defineEmits<{ imported: [result: ImportedPeople] }>()

const open = defineModel<boolean>({ default: false })
const { api } = useApi()

const MAX_ROWS = 200

const file = ref<File | null>(null)
const busy = ref(false)
const formError = ref<string | null>(null)
const serverProblems = ref<Array<{ row: number, message: string }>>([])

interface Row { name: string, email: string, roleId: number, managerEmail: string }
const parsed = ref<{ rows: Row[], problems: string[] }>({ rows: [], problems: [] })

// read the file whenever another one is chosen
watch(file, async (chosen) => {
  serverProblems.value = []
  formError.value = null
  parsed.value = chosen ? await readFile(chosen) : { rows: [], problems: [] }
})

async function readFile(chosen: File): Promise<{ rows: Row[], problems: string[] }> {
  const cells = parseCsv(await chosen.text())
  const header = (cells[0] ?? []).map(h => h.trim().toLowerCase())
  const col = (name: string) => header.indexOf(name)
  const missing = ['name', 'email', 'role'].filter(name => col(name) === -1)
  if (missing.length)
    return { rows: [], problems: [`The first row must name the columns. Missing: ${missing.join(', ')}.`] }

  const body = cells.slice(1)
  if (!body.length)
    return { rows: [], problems: ['The file has no people in it.'] }
  if (body.length > MAX_ROWS)
    return { rows: [], problems: [`The file has ${body.length} people. Send at most ${MAX_ROWS} at a time.`] }

  const rows: Row[] = []
  const problems: string[] = []
  const managerCol = col('manager_email')
  body.forEach((cell, i) => {
    const value = (index: number) => (cell[index] ?? '').trim()
    const roleName = value(col('role'))
    const role = props.roles.find(r => r.name.toLowerCase() === roleName.toLowerCase())
    if (!role)
      problems.push(`Row ${i + 1}: ${roleName ? `"${roleName}" is not a role you can give` : 'the role is empty'}.`)
    rows.push({ name: value(col('name')), email: value(col('email')), roleId: Number(role?.id ?? 0), managerEmail: managerCol === -1 ? '' : value(managerCol) })
  })

  return { rows, problems: problems.slice(0, 10) }
}

const v$ = useVuelidate({
  file: {
    chosen: helpers.withMessage('Choose a CSV file.', (value: File | null) => value !== null),
    readable: helpers.withMessage(() => parsed.value.problems.join(' '), () => parsed.value.problems.length === 0),
  },
}, { file }, { $scope: false }) // not collected by the People page's own form: a parent validates its children too

// each time the drawer opens it starts empty
watch(open, (isOpen) => {
  if (!isOpen)
    return
  file.value = null
  parsed.value = { rows: [], problems: [] }
  serverProblems.value = []
  formError.value = null
  v$.value.$reset()
})

async function submit() {
  if (!(await v$.value.$validate()))
    return
  busy.value = true
  serverProblems.value = []
  formError.value = null
  try {
    const result = await api<ImportedPeople>('/admin/employees/import', { method: 'POST', body: { rows: parsed.value.rows } })
    open.value = false
    emit('imported', result)
  }
  catch (e) {
    const data = (e as { data?: { error?: { rows?: Array<{ row: number, message: string }> } } }).data
    serverProblems.value = data?.error?.rows ?? []
    if (!serverProblems.value.length)
      formError.value = messageOf(e, 'Could not add these people.')
  }
  finally {
    busy.value = false
  }
}
</script>
