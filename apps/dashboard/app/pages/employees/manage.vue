<script setup lang="ts">
import { ROLE_LABEL, rolesOneTierBelow, type CreatedEmployee, type EmployeeListItem, type Role } from 'shared'

// Manage employees (docs/DEVELOPMENT_PLAN.md §12 Phase 2 task 13): everyone in the caller's part
// of the organisation, add a direct report one tier below, deactivate or reactivate anyone
// below them. The server re-checks every rule; this only offers what is allowed.
const { api } = useApi()
const { me } = useAuth()

const people = ref<EmployeeListItem[]>([])
const error = ref<string | null>(null)
const busyId = ref<string | null>(null)

const allowedRoles = computed(() => (me.value ? rolesOneTierBelow(me.value.role) : []))

const name = ref('')
const email = ref('')
const role = ref<Role | ''>('')
const creating = ref(false)
const created = ref<CreatedEmployee | null>(null)
const copied = ref(false)

async function load() {
  try {
    people.value = await api<EmployeeListItem[]>('/employees', { query: { includeDeactivated: 1 } })
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the list.')
  }
}

async function add() {
  creating.value = true
  error.value = null
  created.value = null
  try {
    created.value = await api<CreatedEmployee>('/admin/employees', {
      method: 'POST',
      body: { name: name.value, email: email.value, role: role.value },
    })
    name.value = ''
    email.value = ''
    copied.value = false
    await load()
  }
  catch (e) {
    error.value = messageOf(e, 'Could not add this person.')
  }
  finally {
    creating.value = false
  }
}

async function setStatus(person: EmployeeListItem, status: 'ACTIVE' | 'DEACTIVATED') {
  if (status === 'DEACTIVATED' && !confirm(`Deactivate ${person.name}? They will be signed out and can no longer log in.`))
    return
  busyId.value = person.id
  error.value = null
  try {
    await api(`/admin/employees/${person.id}`, { method: 'PATCH', body: { status } })
    await load()
  }
  catch (e) {
    error.value = messageOf(e, 'Could not change this account.')
  }
  finally {
    busyId.value = null
  }
}

async function remove(person: EmployeeListItem) {
  if (!confirm(`Delete ${person.name} (${person.email})? This cannot be undone. Only accounts that never tracked any time can be deleted; anyone else should be deactivated.`))
    return
  busyId.value = person.id
  error.value = null
  try {
    await api(`/admin/employees/${person.id}`, { method: 'DELETE' })
    if (created.value?.id === person.id)
      created.value = null
    await load()
  }
  catch (e) {
    error.value = messageOf(e, 'Could not delete this account.')
  }
  finally {
    busyId.value = null
  }
}

async function copyLink() {
  if (!created.value?.setPasswordUrl)
    return
  try {
    await navigator.clipboard.writeText(created.value.setPasswordUrl)
    copied.value = true
  }
  catch {
    // Clipboard blocked: the link is still on screen to copy by hand.
  }
}

onMounted(() => {
  load()
  role.value = allowedRoles.value[0] ?? ''
})
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-4 p-4">
    <header>
      <h1 class="text-lg font-semibold">
        Manage employees
      </h1>
      <p class="text-sm text-slate-500">
        People in your part of the organisation.
      </p>
    </header>

    <p
      v-if="error"
      class="text-sm text-red-600"
      role="alert"
    >
      {{ error }}
    </p>

    <section
      v-if="allowedRoles.length"
      class="rounded-lg border border-slate-200 bg-white p-4"
    >
      <h2 class="mb-2 text-sm font-medium text-slate-500">
        Add a direct report
      </h2>
      <form
        class="flex flex-wrap items-end gap-3 text-sm"
        @submit.prevent="add"
      >
        <label class="block">
          <span class="text-slate-500">Name</span>
          <input
            v-model="name"
            required
            class="mt-1 block rounded border border-slate-300 px-2 py-1.5"
          >
        </label>
        <label class="block">
          <span class="text-slate-500">Email</span>
          <input
            v-model="email"
            type="email"
            required
            class="mt-1 block rounded border border-slate-300 px-2 py-1.5"
          >
        </label>
        <label class="block">
          <span class="text-slate-500">Role</span>
          <select
            v-model="role"
            class="mt-1 block rounded border border-slate-300 px-2 py-1.5"
          >
            <option
              v-for="r in allowedRoles"
              :key="r"
              :value="r"
            >
              {{ ROLE_LABEL[r] }}
            </option>
          </select>
        </label>
        <button
          type="submit"
          :disabled="creating"
          class="rounded bg-slate-900 px-4 py-1.5 text-white disabled:opacity-50"
        >
          {{ creating ? 'Adding…' : 'Add' }}
        </button>
      </form>

      <div
        v-if="created?.emailSent"
        class="mt-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800"
        role="status"
      >
        <span class="font-medium">{{ created.name }}</span> was added as {{ ROLE_LABEL[created.role] }}.
        We emailed a link to {{ created.email }} so they can choose their password. It works for 3 days.
      </div>
      <div
        v-else-if="created"
        class="mt-4 rounded border border-amber-200 bg-amber-50 p-3 text-sm"
        role="alert"
      >
        <p>
          <span class="font-medium">{{ created.name }}</span> was added as {{ ROLE_LABEL[created.role] }},
          but the email could not be sent. Give them this link so they can choose their password (it works for 3 days):
        </p>
        <p class="mt-2 flex flex-wrap items-center gap-3">
          <code
            class="break-all rounded bg-white px-2 py-1 font-mono text-xs"
            data-testid="set-password-url"
          >{{ created.setPasswordUrl }}</code>
          <button
            class="rounded bg-white px-2 py-1 underline"
            @click="copyLink"
          >
            {{ copied ? 'Copied' : 'Copy' }}
          </button>
        </p>
      </div>
    </section>

    <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
      <table class="w-full min-w-[40rem] text-left text-sm">
        <thead class="border-b border-slate-200 text-slate-500">
          <tr>
            <th class="px-3 py-2 font-medium">
              Name
            </th>
            <th class="px-3 py-2 font-medium">
              Email
            </th>
            <th class="px-3 py-2 font-medium">
              Role
            </th>
            <th class="px-3 py-2 font-medium">
              Account
            </th>
            <th class="px-3 py-2" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="p in people"
            :key="p.id"
            class="border-b border-slate-100 last:border-0"
            :class="p.accountStatus === 'DEACTIVATED' ? 'text-slate-400' : ''"
          >
            <td class="px-3 py-2">
              <NuxtLink
                :to="`/employees/${p.id}`"
                class="underline"
              >
                {{ p.name }}
              </NuxtLink>
            </td>
            <td class="px-3 py-2">
              {{ p.email }}
            </td>
            <td class="px-3 py-2">
              {{ ROLE_LABEL[p.role] }}
            </td>
            <td class="px-3 py-2">
              {{ p.accountStatus === 'ACTIVE' ? 'Active' : 'Deactivated' }}
            </td>
            <td class="px-3 py-2 text-right">
              <template v-if="p.id !== me?.id">
                <button
                  v-if="p.accountStatus === 'ACTIVE'"
                  :disabled="busyId === p.id"
                  class="rounded bg-slate-100 px-3 py-1 hover:bg-slate-200 disabled:opacity-50"
                  @click="setStatus(p, 'DEACTIVATED')"
                >
                  Deactivate
                </button>
                <button
                  v-else
                  :disabled="busyId === p.id"
                  class="rounded bg-slate-100 px-3 py-1 hover:bg-slate-200 disabled:opacity-50"
                  @click="setStatus(p, 'ACTIVE')"
                >
                  Reactivate
                </button>
                <button
                  :disabled="busyId === p.id"
                  class="ml-2 rounded bg-red-50 px-3 py-1 text-red-700 hover:bg-red-100 disabled:opacity-50"
                  title="Only for accounts that never tracked any time"
                  @click="remove(p)"
                >
                  Delete
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </main>
</template>
