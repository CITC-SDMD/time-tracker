<template>
  <div>
    <UiPageHeader
      title="People"
      :description="scope === 'organization' ? 'Everyone in the organization, who they report to, and their accounts.' : 'You and everyone who reports to you.'"
    >
      <template
        v-if="canAdd"
        #actions
      >
        <FormButton
          variant="secondary"
          @click="importOpen = true"
        >
          Import from a file
        </FormButton>
        <FormButton @click="openAdd">
          Add a person
        </FormButton>
      </template>
    </UiPageHeader>

    <UiAlert
      v-if="canAdd && rolesLoaded && !addRoleOptions.length"
      variant="warning"
      class="mb-6"
    >
      There is no role you can give yet.
      <template v-if="can('roles.manage')">
        <UiLink :to="office.to('/user/roles')">
          Make a role
        </UiLink> first, then add people.
      </template>
      <template v-else>
        Ask an admin of your organization to make one.
      </template>
    </UiAlert>

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
        <UiLink :to="office.to(`/user/employees/${row.id}`)">
          {{ row.name }}
        </UiLink>
        <span
          v-if="row.id === me?.id"
          class="ml-1 text-xs text-gray-500 dark:text-gray-400"
        >(you)</span>
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ row.email }}</span>
      </template>
      <template #cell-role="{ row }">
        {{ row.role }}
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
          v-if="row.id !== me?.id && !readOnly"
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
            v-if="canChangeRole(row as EmployeeListItem)"
            variant="link"
            @click="openRole(row as EmployeeListItem)"
          >
            Change role
          </FormButton>
          <FormButton
            v-if="can('people.update') && row.accountStatus === 'active'"
            variant="link"
            :loading="busyId === row.id"
            @click="resend(row as EmployeeListItem)"
          >
            Resend link
          </FormButton>
          <FormButton
            v-if="can('people.update')"
            variant="link"
            @click="ask(row.accountStatus === 'active' ? 'deactivate' : 'reactivate', row as EmployeeListItem)"
          >
            {{ row.accountStatus === 'active' ? 'Deactivate' : 'Reactivate' }}
          </FormButton>
          <FormButton
            v-if="can('people.update')"
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
      title="Add a person"
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
          v-model="addForm.roleId"
          label="Role"
          :options="addRoleOptions"
          placeholder="Choose a role"
          :errors="addV$.roleId.$errors"
          @blur="addV$.roleId.$touch()"
        />
        <FormSelect
          v-model="addForm.managerId"
          label="Reports to"
          :options="addManagerOptions"
          placeholder="Choose who they report to"
          :errors="addV$.managerId.$errors"
          @blur="addV$.managerId.$touch()"
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

    <UiModal
      v-model="roleOpen"
      :title="`Change the role of ${changing?.name ?? ''}`"
      :persistent="roleBusy"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitRole"
      >
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          {{ changing?.name }} is {{ changing?.role }}. Their history stays with them, and who they report to does not change.
        </p>
        <FormSelect
          v-model="roleForm.roleId"
          label="New role"
          :options="roleOptionsFor"
          placeholder="Choose a role"
          :errors="roleV$.roleId.$errors"
          @blur="roleV$.roleId.$touch()"
        />
        <FormError v-if="roleError">
          {{ roleError }}
        </FormError>
        <div class="flex justify-end gap-3 pt-2">
          <FormButton
            variant="secondary"
            :disabled="roleBusy"
            @click="roleOpen = false"
          >
            Cancel
          </FormButton>
          <FormButton
            type="submit"
            :loading="roleBusy"
          >
            Change role
          </FormButton>
        </div>
      </form>
    </UiModal>

    <AppImportPeopleDrawer
      v-model="importOpen"
      :roles="roles.filter(r => r.assignable)"
      @imported="onImported"
    />

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
import type { CreatedEmployee, EmployeeListItem, ImportedPeople, RoleItem, UserStatus } from 'shared'

definePageMeta({
  layout: 'user',
  permission: 'people.view',
  alias: ['/platform/organizations/:orgId/office/people'],
})

// Add, move, change the role of, deactivate and delete the accounts the signed-in person reaches (docs/DEVELOPMENT_PLAN.md
// §9.1). What they see depends on how far their role reaches, and each button on the permission behind it. The API
// enforces every rule again; this page only offers what the person is allowed to do.
const { api } = useApi()
const { me } = useAuth()
const { can, scope, readOnly } = useAccess()
const office = useOffice()

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
const roles = ref<RoleItem[]>([])
const loaded = ref(false)
const rolesLoaded = ref(false)
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

// the organization's roles, to choose from when adding a person or changing a role
async function loadRoles() {
  if (!can('people.create') && !can('people.assign_role'))
    return
  try {
    roles.value = await api<RoleItem[]>('/roles')
  }
  catch {
    roles.value = []
  }
  finally {
    rolesLoaded.value = true
  }
}

onMounted(() => {
  load()
  loadRoles()
})

const roleOptions = computed(() => [...new Set(people.value.map(p => p.role))].sort().map(name => ({ value: name, label: name })))

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return people.value.filter(p =>
    (!needle || p.name.toLowerCase().includes(needle) || p.email.toLowerCase().includes(needle))
    && (!roleFilter.value || p.role === roleFilter.value)
    && (statusFilter.value === 'all' || p.accountStatus === statusFilter.value),
  )
})

/** everyone below `id` in the reporting line, at any depth (a person cannot be put under someone below them) */
function below(id: string): Set<string> {
  const found = new Set<string>()
  const queue = [id]
  while (queue.length) {
    const current = queue.shift()!
    for (const p of people.value) {
      if (p.managerId === current && !found.has(p.id)) {
        found.add(p.id)
        queue.push(p.id)
      }
    }
  }
  return found
}

const NO_MANAGER = 'none'

// ---- import from a file ----------------------------------------------------------------------

const importOpen = ref(false)

async function onImported(result: ImportedPeople) {
  const first = result.emailFailed[0]
  notice.value = first
    ? { variant: 'danger', text: `${result.created} added, but ${result.emailFailed.length} email(s) could not be sent (use Resend link for the others). Pass this link on to ${first.email} yourself:`, link: first.setPasswordUrl }
    : { variant: 'success', text: `${result.created} ${result.created === 1 ? 'person was' : 'people were'} added. Each got an email with a link to choose a password.` }
  await load()
}

// ---- add a person ----------------------------------------------------------------------------

// shown once the roles are known, so the form always has its choices
const canAdd = computed(() => can('people.create') && !readOnly.value && rolesLoaded.value)
// only roles the person may give (nobody gives more than they have)
const addRoleOptions = computed(() => roles.value.filter(r => r.assignable).map(r => ({ value: r.id, label: r.name })))
const addManagerOptions = computed(() => [
  ...people.value.filter(p => p.accountStatus === 'active').map(p => ({ value: p.id, label: p.id === me.value?.id ? `${p.name} (you)` : `${p.name} (${p.role})` })),
  ...(scope.value === 'organization' ? [{ value: NO_MANAGER, label: 'No manager' }] : []),
])

const addOpen = ref(false)
const adding = ref(false)
const addError = ref<string | null>(null)
const addForm = reactive({ name: '', email: '', roleId: '', managerId: '' })
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
  roleId: { required: helpers.withMessage('Choose a role.', required) },
  managerId: { required: helpers.withMessage('Choose who they report to.', required) },
}
const addV$ = useVuelidate(addRules, addForm)

function openAdd() {
  addForm.name = ''
  addForm.email = ''
  addForm.roleId = addRoleOptions.value.length === 1 ? addRoleOptions.value[0]!.value : ''
  // unless said otherwise the new person reports to whoever adds them
  addForm.managerId = me.value && !me.value.isSuperadmin ? me.value.id : ''
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
      body: {
        name: addForm.name.trim(),
        email: addForm.email.trim(),
        roleId: Number(addForm.roleId),
        managerId: addForm.managerId === NO_MANAGER ? null : Number(addForm.managerId),
      },
    })
    addOpen.value = false
    notice.value = created.emailSent
      ? { variant: 'success', text: `We emailed a set-password link to ${created.email}. It works for 3 days.` }
      : { variant: 'danger', text: `${created.name} was added, but the email could not be sent. Pass this link on yourself:`, link: created.setPasswordUrl }
    await load()
    await loadRoles()
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

// anyone active who is not the person, not their current manager and not somebody below them (that would be a loop)
function managersFor(person: EmployeeListItem): EmployeeListItem[] {
  const under = below(person.id)
  return people.value.filter(p =>
    p.id !== person.id
    && p.id !== person.managerId
    && p.accountStatus === 'active'
    && !under.has(p.id),
  )
}

const moveOptions = computed(() => {
  const person = moving.value
  if (!person)
    return []
  return [
    ...managersFor(person).map(m => ({ value: m.id, label: `${m.name} (${m.role})` })),
    ...(scope.value === 'organization' && person.managerId !== null ? [{ value: NO_MANAGER, label: 'No manager' }] : []),
  ]
})

function canMove(person: EmployeeListItem): boolean {
  return can('people.update') && (managersFor(person).length > 0 || (scope.value === 'organization' && person.managerId !== null))
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
    await api(`/admin/employees/${moving.value.id}`, { method: 'PATCH', body: { managerId: moveForm.managerId === NO_MANAGER ? null : Number(moveForm.managerId) } })
    const to = people.value.find(p => p.id === moveForm.managerId)
    notice.value = { variant: 'success', text: moveForm.managerId === NO_MANAGER ? `${moving.value.name} no longer reports to anyone.` : `${moving.value.name} now reports to ${to?.name ?? 'the new manager'}.` }
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

// ---- change role ------------------------------------------------------------------------------

// Giving a person another role is separate from moving them: nobody's manager changes here. The person may only
// offer roles they may give (nobody gives more than they have).
const roleOpen = ref(false)
const roleBusy = ref(false)
const roleError = ref<string | null>(null)
const changing = ref<EmployeeListItem | null>(null)
const roleForm = reactive({ roleId: '' })

// the roles that could replace the person's current one
function roleChoices(person: EmployeeListItem): RoleItem[] {
  return roles.value.filter(r => r.assignable && r.id !== person.roleId)
}

function canChangeRole(person: EmployeeListItem): boolean {
  // someone holding a role that can do more than the caller's cannot be changed by them (the API says so too)
  const held = roles.value.find(r => r.id === person.roleId)
  return can('people.assign_role') && (held?.assignable ?? true) && roleChoices(person).length > 0
}

const roleOptionsFor = computed(() => changing.value ? roleChoices(changing.value).map(r => ({ value: r.id, label: r.name })) : [])

const roleV$ = useVuelidate({ roleId: { required: helpers.withMessage('Choose the new role.', required) } }, roleForm)

function openRole(person: EmployeeListItem) {
  changing.value = person
  roleForm.roleId = ''
  roleError.value = null
  roleV$.value.$reset()
  roleOpen.value = true
}

async function submitRole() {
  if (!changing.value || !(await roleV$.value.$validate()))
    return
  roleBusy.value = true
  roleError.value = null
  try {
    await api(`/admin/employees/${changing.value.id}`, { method: 'PATCH', body: { roleId: Number(roleForm.roleId) } })
    const role = roles.value.find(r => r.id === roleForm.roleId)
    notice.value = { variant: 'success', text: `${changing.value.name} is now ${role?.name ?? 'in the new role'}.` }
    roleOpen.value = false
    await load()
    await loadRoles()
  }
  catch (e) {
    roleError.value = messageOf(e, 'Could not change this role.')
  }
  finally {
    roleBusy.value = false
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
