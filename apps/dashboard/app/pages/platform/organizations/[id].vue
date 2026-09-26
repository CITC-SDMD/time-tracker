<template>
  <div>
    <UiLink
      to="/platform"
      variant="muted"
      class="text-sm/6"
    >
      ← Organizations
    </UiLink>

    <UiSpinner v-if="!organization && !error" />

    <UiAlert
      v-else-if="!organization"
      variant="danger"
      class="mt-4"
    >
      {{ error }}
    </UiAlert>

    <template v-else>
      <UiPageHeader
        class="mt-4"
        :title="organization.name"
      >
        <template #below>
          <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm/6 text-gray-500 dark:text-gray-400">
            <UiBadge
              dot
              :variant="organization.status === 'active' ? 'success' : 'danger'"
            >
              {{ organization.status === 'active' ? 'Active' : 'Suspended' }}
            </UiBadge>
            <span>Timezone {{ organization.timezone ?? '—' }}</span>
            <span v-if="organization.createdAt">· Created {{ formatDateTime(organization.createdAt) }}</span>
          </p>
        </template>
        <template #actions>
          <FormButton
            v-if="canPlatform('organizations.data.view')"
            @click="navigateTo(`/platform/organizations/${organization.id}/office`)"
          >
            Open office
          </FormButton>
          <FormButton
            v-if="canPlatform('organizations.update')"
            variant="secondary"
            @click="openRename"
          >
            Rename
          </FormButton>
          <FormButton
            v-if="canPlatform('organizations.update')"
            variant="secondary"
            @click="askStatus"
          >
            {{ organization.status === 'active' ? 'Suspend' : 'Reactivate' }}
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

      <section
        class="grid grid-cols-1 gap-4 sm:grid-cols-3"
        aria-label="Usage"
      >
        <UiStatCard
          label="People"
          :value="String(organization.peopleCount)"
        />
        <UiStatCard
          label="Admins"
          :value="String(organization.adminCount)"
        />
        <UiStatCard
          label="Screenshot storage"
          :value="formatBytes(organization.storageBytes)"
        />
      </section>

      <template v-if="canPlatform('organizations.admins.manage')">
        <div class="mb-3 mt-10 flex items-center justify-between gap-4">
          <h2 class="text-base/7 font-semibold text-gray-900 dark:text-white">
            Admins
          </h2>
          <FormButton @click="openAdd">
            Add an admin
          </FormButton>
        </div>
        <p class="mb-3 text-sm/6 text-gray-500 dark:text-gray-400">
          Admins run this organization: they make its roles and add its people. An admin gets an email with a link to choose a password.
        </p>
        <UiTable
          :columns="ADMIN_COLUMNS"
          :rows="admins"
          :loading="!adminsLoaded"
          :error="adminsLoaded ? null : adminsError"
          empty-title="No admin yet"
          empty-description="Add one so the organization can start."
        >
          <template #cell-name="{ row }">
            {{ row.name }}
            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ row.email }}</span>
          </template>
          <template #cell-status="{ row }">
            <UiBadge
              dot
              :variant="ACCOUNT_STATUS_VARIANT[row.status as UserStatus]"
            >
              {{ ACCOUNT_STATUS_LABEL[row.status as UserStatus] }}
            </UiBadge>
          </template>
          <template #cell-actions="{ row }">
            <div class="flex flex-wrap justify-end gap-x-2">
              <FormButton
                v-if="row.status === 'active'"
                variant="link"
                :loading="busyId === row.id"
                @click="resend(row as OrganizationAdminItem)"
              >
                Resend link
              </FormButton>
              <FormButton
                variant="link"
                @click="askAdmin(row as OrganizationAdminItem)"
              >
                {{ row.status === 'active' ? 'Deactivate' : 'Reactivate' }}
              </FormButton>
            </div>
          </template>
        </UiTable>
      </template>
    </template>

    <UiModal
      v-model="addOpen"
      title="Add an admin"
      :persistent="adding"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitAdd"
      >
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
      v-model="renameOpen"
      title="Rename the organization"
      :persistent="renaming"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitRename"
      >
        <FormInput
          v-model="renameForm.name"
          label="Name"
          autocomplete="off"
          :errors="renameV$.name.$errors"
          @blur="renameV$.name.$touch()"
        />
        <FormError v-if="renameError">
          {{ renameError }}
        </FormError>
        <div class="flex justify-end gap-3 pt-2">
          <FormButton
            variant="secondary"
            :disabled="renaming"
            @click="renameOpen = false"
          >
            Cancel
          </FormButton>
          <FormButton
            type="submit"
            :loading="renaming"
          >
            Save
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
import type { OrganizationAdminItem, OrganizationItem, UserStatus } from 'shared'

definePageMeta({
  layout: 'user',
})

// One organization's profile for a superadmin (docs/DEVELOPMENT_PLAN.md §9.4): its numbers, its admins (added here, and they
// then make everything else themselves), and a button to open the office. Each button follows a platform permission.
const route = useRoute()
const { api } = useApi()
const { canPlatform } = useAccess()
const { formatBytes, formatDateTime } = useFormat()

const id = computed(() => String(route.params.id))
const organization = ref<OrganizationItem | null>(null)
const error = ref<string | null>(null)
const notice = ref<{ variant: 'success' | 'danger', text: string, link?: string } | null>(null)

async function load() {
  try {
    organization.value = await api<OrganizationItem>(`/platform/organizations/${id.value}`)
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the organization.')
  }
}

// ---- admins -----------------------------------------------------------------------------------

const ADMIN_COLUMNS = [
  { key: 'name', label: 'Admin' },
  { key: 'status', label: 'Account' },
  { key: 'actions', label: '', align: 'right' as const },
]

const admins = ref<OrganizationAdminItem[]>([])
const adminsLoaded = ref(false)
const adminsError = ref<string | null>(null)

async function loadAdmins() {
  if (!canPlatform('organizations.admins.manage')) {
    adminsLoaded.value = true
    return
  }
  try {
    admins.value = await api<OrganizationAdminItem[]>(`/platform/organizations/${id.value}/admins`)
    adminsError.value = null
  }
  catch (e) {
    adminsError.value = messageOf(e, 'Could not load the admins.')
  }
  finally {
    adminsLoaded.value = true
  }
}

onMounted(async () => {
  await load()
  await loadAdmins()
})

const addOpen = ref(false)
const adding = ref(false)
const addError = ref<string | null>(null)
const addForm = reactive({ name: '', email: '' })
const addV$ = useVuelidate({
  name: {
    required: helpers.withMessage('Enter their full name.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  email: {
    required: helpers.withMessage('Enter their email address.', required),
    email: helpers.withMessage('Enter a valid email address.', emailRule),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
}, addForm)

function openAdd() {
  addForm.name = ''
  addForm.email = ''
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
    const created = await api<OrganizationAdminItem>(`/platform/organizations/${id.value}/admins`, {
      method: 'POST',
      body: { name: addForm.name.trim(), email: addForm.email.trim() },
    })
    addOpen.value = false
    notice.value = created.emailSent
      ? { variant: 'success', text: `We emailed a set-password link to ${created.email}. It works for 3 days.` }
      : { variant: 'danger', text: `${created.name} was added, but the email could not be sent. Pass this link on yourself:`, link: created.setPasswordUrl }
    await load()
    await loadAdmins()
  }
  catch (e) {
    addError.value = messageOf(e, 'Could not add this admin.')
  }
  finally {
    adding.value = false
  }
}

const busyId = ref<string | null>(null)

async function resend(admin: OrganizationAdminItem) {
  busyId.value = admin.id
  try {
    const result = await api<{ emailSent: boolean, setPasswordUrl?: string }>(`/platform/organizations/${id.value}/admins/${admin.id}/resend-invite`, { method: 'POST' })
    notice.value = result.emailSent
      ? { variant: 'success', text: `We emailed a new set-password link to ${admin.email}. It works for 3 days.` }
      : { variant: 'danger', text: `The email to ${admin.email} could not be sent. Pass this link on yourself:`, link: result.setPasswordUrl }
  }
  catch (e) {
    notice.value = { variant: 'danger', text: messageOf(e, 'Could not send the link.') }
  }
  finally {
    busyId.value = null
  }
}

// ---- rename -----------------------------------------------------------------------------------

const renameOpen = ref(false)
const renaming = ref(false)
const renameError = ref<string | null>(null)
const renameForm = reactive({ name: '' })
const renameV$ = useVuelidate({
  name: {
    required: helpers.withMessage('Enter the name of the organization.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
}, renameForm)

function openRename() {
  renameForm.name = organization.value?.name ?? ''
  renameError.value = null
  renameV$.value.$reset()
  renameOpen.value = true
}

async function submitRename() {
  if (!(await renameV$.value.$validate()))
    return
  renaming.value = true
  renameError.value = null
  try {
    organization.value = await api<OrganizationItem>(`/platform/organizations/${id.value}`, { method: 'PATCH', body: { name: renameForm.name.trim() } })
    renameOpen.value = false
  }
  catch (e) {
    renameError.value = messageOf(e, 'Could not rename the organization.')
  }
  finally {
    renaming.value = false
  }
}

// ---- suspend, reactivate, deactivate an admin ---------------------------------------------------

const confirmOpen = ref(false)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)
const pending = ref<{ kind: 'status' } | { kind: 'admin', admin: OrganizationAdminItem } | null>(null)

const confirm = computed(() => {
  const p = pending.value
  if (p?.kind === 'admin') {
    return p.admin.status === 'active'
      ? { title: `Deactivate ${p.admin.name}?`, message: 'They will be signed out and can no longer log in. The organization keeps at least one active admin.', label: 'Deactivate', danger: true }
      : { title: `Reactivate ${p.admin.name}?`, message: 'They can log in again.', label: 'Reactivate', danger: false }
  }
  return organization.value?.status === 'active'
    ? { title: `Suspend ${organization.value.name}?`, message: 'Nobody in it can sign in, and the desktop apps are told to sign out, until you reactivate it. Nothing is deleted.', label: 'Suspend', danger: true }
    : { title: `Reactivate ${organization.value?.name ?? ''}?`, message: 'Its people can sign in again.', label: 'Reactivate', danger: false }
})

function askStatus() {
  pending.value = { kind: 'status' }
  confirmError.value = null
  confirmOpen.value = true
}

function askAdmin(admin: OrganizationAdminItem) {
  pending.value = { kind: 'admin', admin }
  confirmError.value = null
  confirmOpen.value = true
}

async function runConfirmed() {
  const p = pending.value
  if (!p)
    return
  confirmBusy.value = true
  confirmError.value = null
  try {
    if (p.kind === 'admin')
      await api(`/platform/organizations/${id.value}/admins/${p.admin.id}`, { method: 'PATCH', body: { status: p.admin.status === 'active' ? 'inactive' : 'active' } })
    else
      await api(`/platform/organizations/${id.value}`, { method: 'PATCH', body: { status: organization.value?.status === 'active' ? 'suspended' : 'active' } })
    confirmOpen.value = false
    await load()
    await loadAdmins()
  }
  catch (e) {
    confirmError.value = messageOf(e, 'Could not do that.')
  }
  finally {
    confirmBusy.value = false
  }
}
</script>
