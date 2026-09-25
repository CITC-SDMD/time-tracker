<template>
  <div>
    <UiPageHeader
      title="People"
      :description="isOic ? 'Everyone in the office, who they report to, and their accounts.' : 'You and everyone who reports to you.'"
    >
      <template
        v-if="addableRoles.length"
        #actions
      >
        <FormButton @click="openAdd">
          Add {{ addLabel }}
        </FormButton>
      </template>
    </UiPageHeader>

    <UiAlert
      v-if="notice"
      :variant="notice.variant"
      class="mb-6"
    >
      <p>{{ notice.text }}</p>
      <div
        v-if="notice.link"
        class="mt-3 flex flex-wrap items-center gap-3"
      >
        <code class="min-w-0 flex-1 break-all rounded bg-white/60 px-2 py-1 text-xs dark:bg-black/30">{{ notice.link }}</code>
        <UiCopyButton :text="notice.link" />
      </div>
    </UiAlert>

    <UiCard class="mb-6">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <FormInput
          v-model="search"
          label="Search"
          type="search"
          placeholder="Name or email"
        />
        <FormSelect
          v-model="roleFilter"
          label="Role"
          :options="roleOptions"
          placeholder="All roles"
        />
        <FormSelect
          v-model="statusFilter"
          label="Account"
          :options="STATUS_OPTIONS"
        />
      </div>
    </UiCard>

    <UiTable
      :columns="COLUMNS"
      :rows="filtered"
      :loading="!loaded"
      :error="loaded ? null : error"
      empty-title="No one matches"
      empty-description="Change the search or the filters."
    >
      <template #cell-name="{ row }">
        <UiLink :to="`/user/employees/${row.id}`">
          {{ row.name }}
        </UiLink>
        <span
          v-if="row.id === me?.id"
          class="ml-1 text-xs text-gray-500 dark:text-gray-400"
        >(you)</span>
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ row.email }}</span>
      </template>
      <template #cell-role="{ row }">
        {{ ROLE_LABEL[row.role as Role] }}
      </template>
      <template #cell-managerName="{ row }">
        {{ row.managerName ?? '—' }}
      </template>
      <template #cell-accountStatus="{ row }">
        <UiBadge
          dot
          :variant="ACCOUNT_STATUS_VARIANT[row.accountStatus as UserStatus]"
        >
          {{ ACCOUNT_STATUS_LABEL[row.accountStatus as UserStatus] }}
        </UiBadge>
      </template>
      <template #cell-actions="{ row }">
        <div
          v-if="row.id !== me?.id"
          class="flex flex-wrap justify-end gap-x-2"
        >
          <FormButton
            v-if="canMove(row as EmployeeListItem)"
            variant="link"
            @click="openMove(row as EmployeeListItem)"
          >
            Move
          </FormButton>
          <FormButton
            v-if="row.accountStatus === 'active'"
            variant="link"
            :loading="busyId === row.id"
            @click="resend(row as EmployeeListItem)"
          >
            Resend link
          </FormButton>
          <FormButton
            variant="link"
            @click="ask(row.accountStatus === 'active' ? 'deactivate' : 'reactivate', row as EmployeeListItem)"
          >
            {{ row.accountStatus === 'active' ? 'Deactivate' : 'Reactivate' }}
          </FormButton>
          <FormButton
            variant="link"
            class="text-red-600 hover:text-red-500 dark:text-red-400"
            @click="ask('delete', row as EmployeeListItem)"
          >
            Delete
          </FormButton>
        </div>
      </template>
    </UiTable>

    <UiModal
      v-model="addOpen"
      :title="`Add ${addLabel}`"
      :persistent="adding"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitAdd"
      >
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          They get an email with a link to choose their own password. The link works for 3 days.
        </p>
        <FormInput
          v-model="addForm.name"
          label="Full name"
          autocomplete="off"
          :errors="addV$.name.$errors"
          @blur="addV$.name.$touch()"
        />
        <FormInput
          v-model="addForm.email"
          label="Email"
          type="email"
          autocomplete="off"
          :errors="addV$.email.$errors"
          @blur="addV$.email.$touch()"
        />
        <FormSelect
          v-model="addForm.role"
          label="Role"
          :options="addRoleOptions"
          :errors="addV$.role.$errors"
          @blur="addV$.role.$touch()"
        />
        <FormError v-if="addError">
          {{ addError }}
        </FormError>
        <div class="flex justify-end gap-3 pt-2">
          <FormButton
            variant="secondary"
            :disabled="adding"
            @click="addOpen = false"
          >
            Cancel
          </FormButton>
          <FormButton
            type="submit"
            :loading="adding"
          >
            {{ adding ? 'Adding…' : 'Add and email link' }}
          </FormButton>
        </div>
      </form>
    </UiModal>

    <UiModal
      v-model="moveOpen"
      :title="`Move ${moving?.name ?? ''}`"
      :persistent="moveBusy"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitMove"
      >
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          {{ moving?.name }} currently reports to {{ moving?.managerName ?? 'no one' }}. Their own team moves with them.
        </p>
        <FormSelect
          v-model="moveForm.managerId"
          label="New manager"
          :options="moveOptions"
          placeholder="Choose a manager"
          :errors="moveV$.managerId.$errors"
          @blur="moveV$.managerId.$touch()"
        />
        <FormError v-if="moveError">
          {{ moveError }}
        </FormError>
        <div class="flex justify-end gap-3 pt-2">
          <FormButton
            variant="secondary"
            :disabled="moveBusy"
            @click="moveOpen = false"
          >
            Cancel
          </FormButton>
          <FormButton
            type="submit"
            :loading="moveBusy"
          >
            Move
          </FormButton>
        </div>
      </form>
    </UiModal>

    <UiConfirmDialog
      v-model="confirmOpen"
      :title="confirm.title"
      :message="confirm.message"
      :confirm-label="confirm.label"
      :danger="confirm.danger"
      :loading="confirmBusy"
      :error="confirmError"
      @confirm="runConfirmed"
    />
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { email as emailRule, helpers, maxLength, required } from '@vuelidate/validators'
import { ROLE_LABEL, rolesOneTierBelow, type CreatedEmployee, type EmployeeListItem, type Role, type UserStatus } from 'shared'

definePageMeta({
  layout: 'user',
})

// Add, move, deactivate and delete the accounts the signed-in person manages (docs/DEVELOPMENT_PLAN.md
// §9.1): an OIC sees the whole office, everyone else only their own branch. The API enforces every
// rule again; this page only offers what the person is allowed to do.
const { api } = useApi()
const { me, isOic } = useAuth()

const COLUMNS = [
  { key: 'name', label: 'Name' },
  { key: 'role', label: 'Role' },
  { key: 'managerName', label: 'Manager' },
  { key: 'accountStatus', label: 'Account' },
  { key: 'actions', label: '', align: 'right' as const },
]

const STATUS_OPTIONS = [
  { value: 'all', label: 'All accounts' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Deactivated' },
]

const people = ref<EmployeeListItem[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)

const search = ref('')
const roleFilter = ref('')
const statusFilter = ref('all')

// what happened last, shown at the top; `link` is set when an email could not be sent
const notice = ref<{ variant: 'success' | 'danger', text: string, link?: string } | null>(null)

async function load() {
  try {
    people.value = await api<EmployeeListItem[]>('/employees', { query: { includeDeactivated: 1 } })
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the list.')
  }
  finally {
    loaded.value = true
  }
}

onMounted(load)

const roleOptions = computed(() => {
  const present = new Set(people.value.map(p => p.role))
  return [...present].map(role => ({ value: role, label: ROLE_LABEL[role] }))
})

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return people.value.filter(p =>
    (!needle || p.name.toLowerCase().includes(needle) || p.email.toLowerCase().includes(needle))
    && (!roleFilter.value || p.role === roleFilter.value)
    && (statusFilter.value === 'all' || p.accountStatus === statusFilter.value),
  )
})

// ---- add a person ----------------------------------------------------------------------------

const addableRoles = computed<readonly Role[]>(() => (me.value ? rolesOneTierBelow(me.value.role) : []))
const addRoleOptions = computed(() => addableRoles.value.map(role => ({ value: role, label: ROLE_LABEL[role] })))
const addLabel = computed(() => addableRoles.value.length === 1 ? ROLE_LABEL[addableRoles.value[0]!] : 'person')

const addOpen = ref(false)
const adding = ref(false)
const addError = ref<string | null>(null)
const addForm = reactive({ name: '', email: '', role: '' })
const addRules = {
  name: {
    required: helpers.withMessage('Enter their full name.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  email: {
    required: helpers.withMessage('Enter their email address.', required),
    email: helpers.withMessage('Enter a valid email address.', emailRule),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  role: { required: helpers.withMessage('Choose a role.', required) },
}
const addV$ = useVuelidate(addRules, addForm)

function openAdd() {
  addForm.name = ''
  addForm.email = ''
  addForm.role = addableRoles.value.length === 1 ? addableRoles.value[0]! : ''
  addError.value = null
  addV$.value.$reset()
  addOpen.value = true
}

async function submitAdd() {
  if (!(await addV$.value.$validate()))
    return
  adding.value = true
  addError.value = null
  try {
    const created = await api<CreatedEmployee>('/admin/employees', {
      method: 'POST',
      body: { name: addForm.name.trim(), email: addForm.email.trim(), role: addForm.role },
    })
    addOpen.value = false
    notice.value = created.emailSent
      ? { variant: 'success', text: `We emailed a set-password link to ${created.email}. It works for 3 days.` }
      : { variant: 'danger', text: `${created.name} was added, but the email could not be sent. Pass this link on yourself:`, link: created.setPasswordUrl }
    await load()
  }
  catch (e) {
    addError.value = messageOf(e, 'Could not add this person.')
  }
  finally {
    adding.value = false
  }
}

// ---- resend the link -------------------------------------------------------------------------

const busyId = ref<string | null>(null)

async function resend(person: EmployeeListItem) {
  busyId.value = person.id
  try {
    const result = await api<{ emailSent: boolean, setPasswordUrl?: string }>(`/admin/employees/${person.id}/resend-invite`, { method: 'POST' })
    notice.value = result.emailSent
      ? { variant: 'success', text: `We emailed a new set-password link to ${person.email}. It works for 3 days.` }
      : { variant: 'danger', text: `The email to ${person.email} could not be sent. Pass this link on yourself:`, link: result.setPasswordUrl }
  }
  catch (e) {
    notice.value = { variant: 'danger', text: messageOf(e, 'Could not send the link.') }
  }
  finally {
    busyId.value = null
  }
}

// ---- move to another manager -----------------------------------------------------------------

const moveOpen = ref(false)
const moveBusy = ref(false)
const moveError = ref<string | null>(null)
const moving = ref<EmployeeListItem | null>(null)
const moveForm = reactive({ managerId: '' })
const moveV$ = useVuelidate({ managerId: { required: helpers.withMessage('Choose the new manager.', required) } }, moveForm)

// people who could take `person`: active, holding the role one tier above theirs, and not the current manager
function managersFor(person: EmployeeListItem): EmployeeListItem[] {
  return people.value.filter(p =>
    p.id !== person.id
    && p.id !== person.managerId
    && p.accountStatus === 'active'
    && rolesOneTierBelow(p.role).includes(person.role),
  )
}

const moveOptions = computed(() => moving.value
  ? managersFor(moving.value).map(m => ({ value: m.id, label: `${m.name} (${ROLE_LABEL[m.role]})` }))
  : [])

function canMove(person: EmployeeListItem): boolean {
  return person.role !== 'oic' && managersFor(person).length > 0
}

function openMove(person: EmployeeListItem) {
  moving.value = person
  moveForm.managerId = ''
  moveError.value = null
  moveV$.value.$reset()
  moveOpen.value = true
}

async function submitMove() {
  if (!moving.value || !(await moveV$.value.$validate()))
    return
  moveBusy.value = true
  moveError.value = null
  try {
    await api(`/admin/employees/${moving.value.id}`, { method: 'PATCH', body: { managerId: Number(moveForm.managerId) } })
    const to = people.value.find(p => p.id === moveForm.managerId)
    notice.value = { variant: 'success', text: `${moving.value.name} now reports to ${to?.name ?? 'the new manager'}.` }
    moveOpen.value = false
    await load()
  }
  catch (e) {
    moveError.value = messageOf(e, 'Could not move this person.')
  }
  finally {
    moveBusy.value = false
  }
}

// ---- deactivate, reactivate, delete ----------------------------------------------------------

type Action = 'deactivate' | 'reactivate' | 'delete'

const confirmOpen = ref(false)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)
const pending = ref<{ action: Action, person: EmployeeListItem } | null>(null)

const confirm = computed(() => {
  const name = pending.value?.person.name ?? ''
  switch (pending.value?.action) {
    case 'deactivate':
      return { title: `Deactivate ${name}?`, message: 'They will be signed out and can no longer log in. Their history is kept.', label: 'Deactivate', danger: true }
    case 'reactivate':
      return { title: `Reactivate ${name}?`, message: 'They can log in again.', label: 'Reactivate', danger: false }
    default:
      return { title: `Delete ${name}?`, message: 'This cannot be undone. Only accounts that never tracked any time can be deleted; anyone else has to be deactivated, so their history is kept.', label: 'Delete', danger: true }
  }
})

function ask(action: Action, person: EmployeeListItem) {
  pending.value = { action, person }
  confirmError.value = null
  confirmOpen.value = true
}

async function runConfirmed() {
  if (!pending.value)
    return
  const { action, person } = pending.value
  confirmBusy.value = true
  confirmError.value = null
  try {
    if (action === 'delete')
      await api(`/admin/employees/${person.id}`, { method: 'DELETE' })
    else
      await api(`/admin/employees/${person.id}`, { method: 'PATCH', body: { status: action === 'deactivate' ? 'inactive' : 'active' } })
    notice.value = { variant: 'success', text: `${person.name} was ${action === 'delete' ? 'deleted' : `${action}d`}.` }
    confirmOpen.value = false
    await load()
  }
  catch (e) {
    confirmError.value = messageOf(e, 'Could not do that.')
  }
  finally {
    confirmBusy.value = false
  }
}
</script>
