<template>
  <div>
    <UiPageHeader
      title="Superadmins"
      description="The people who run the platform. Each has their own permissions, and a superadmin only sees and does what theirs allow."
    >
      <template #actions>
        <FormButton @click="openAdd">
          Add a superadmin
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

    <UiTable
      :columns="COLUMNS"
      :rows="people"
      :loading="!loaded"
      :error="loaded ? null : error"
      empty-title="No superadmins"
    >
      <template #cell-name="{ row }">
        {{ row.name }}
        <UiBadge
          v-if="row.isOwner"
          class="ml-2"
          variant="primary"
        >
          Owner
        </UiBadge>
        <span
          v-if="row.id === me?.id"
          class="ml-1 text-xs text-gray-500 dark:text-gray-400"
        >(you)</span>
        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ row.email }}</span>
      </template>
      <template #cell-permissions="{ row }">
        {{ row.isOwner ? 'All of them' : `${row.permissions.length} of ${catalog.length}` }}
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
        <div
          v-if="!row.isOwner && row.id !== me?.id"
          class="flex flex-wrap justify-end gap-x-2"
        >
          <FormButton
            variant="link"
            @click="openEdit(row as SuperadminItem)"
          >
            Permissions
          </FormButton>
          <FormButton
            variant="link"
            @click="askStatus(row as SuperadminItem)"
          >
            {{ row.status === 'active' ? 'Deactivate' : 'Reactivate' }}
          </FormButton>
        </div>
      </template>
    </UiTable>

    <UiModal
      v-model="formOpen"
      :title="editing ? `Permissions of ${editing.name}` : 'Add a superadmin'"
      :persistent="saving"
      wide
    >
      <form
        class="space-y-5"
        novalidate
        @submit.prevent="submit"
      >
        <template v-if="!editing">
          <FormInput
            v-model="form.name"
            label="Full name"
            autocomplete="off"
            :errors="v$.name.$errors"
            @blur="v$.name.$touch()"
          />
          <FormInput
            v-model="form.email"
            label="Email"
            type="email"
            autocomplete="off"
            :errors="v$.email.$errors"
            @blur="v$.email.$touch()"
          />
        </template>
        <FormPermissionPicker
          v-model="form.permissions"
          label="What this superadmin may do"
          :permissions="catalog"
          :available="myPermissions"
          :errors="v$.permissions.$errors"
        />
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          You can only give permissions you have yourself. Anything a superadmin has no permission for is hidden from them.
        </p>
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
            {{ saving ? 'Saving…' : editing ? 'Save permissions' : 'Add and email link' }}
          </FormButton>
        </div>
      </form>
    </UiModal>

    <UiConfirmDialog
      v-model="confirmOpen"
      :title="pending?.status === 'active' ? `Deactivate ${pending.name}?` : `Reactivate ${pending?.name ?? ''}?`"
      :message="pending?.status === 'active' ? 'They will be signed out and can no longer log in.' : 'They can log in again.'"
      :confirm-label="pending?.status === 'active' ? 'Deactivate' : 'Reactivate'"
      :danger="pending?.status === 'active'"
      :loading="confirmBusy"
      :error="confirmError"
      @confirm="runStatus"
    />
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { email as emailRule, helpers, maxLength, required } from '@vuelidate/validators'
import type { PlatformPermissionCatalog, SuperadminItem, UserStatus } from 'shared'

definePageMeta({
  layout: 'user',
  platformPermission: 'platform.staff.manage',
})

// The platform's own people (docs/DEVELOPMENT_PLAN.md §9.4): there is one role, superadmin, and each account carries its own
// permissions from a fixed list. The owner is always complete and cannot be changed here; nobody gives a permission they
// do not hold, or changes their own.
const { api } = useApi()
const { me } = useAuth()

const COLUMNS = [
  { key: 'name', label: 'Superadmin' },
  { key: 'permissions', label: 'Permissions', align: 'right' as const },
  { key: 'status', label: 'Account' },
  { key: 'actions', label: '', align: 'right' as const },
]

const people = ref<SuperadminItem[]>([])
const catalog = ref<PlatformPermissionCatalog['permissions']>([])
const loaded = ref(false)
const error = ref<string | null>(null)
const notice = ref<{ variant: 'success' | 'danger', text: string, link?: string } | null>(null)

const myPermissions = computed(() => me.value?.platformPermissions ?? [])

async function load() {
  try {
    const [list, permissionCatalog] = await Promise.all([
      api<SuperadminItem[]>('/platform/superadmins'),
      api<PlatformPermissionCatalog>('/platform/permissions'),
    ])
    people.value = list
    catalog.value = permissionCatalog.permissions
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the superadmins.')
  }
  finally {
    loaded.value = true
  }
}

onMounted(load)

// ---- add or change permissions ------------------------------------------------------------------

const formOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const editing = ref<SuperadminItem | null>(null)
const form = reactive({ name: '', email: '', permissions: [] as string[] })

const v$ = useVuelidate(computed(() => ({
  name: editing.value
    ? {}
    : {
        required: helpers.withMessage('Enter their full name.', required),
        maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
      },
  email: editing.value
    ? {}
    : {
        required: helpers.withMessage('Enter their email address.', required),
        email: helpers.withMessage('Enter a valid email address.', emailRule),
        maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
      },
  permissions: {},
})), form)

function openAdd() {
  editing.value = null
  form.name = ''
  form.email = ''
  form.permissions = []
  formError.value = null
  v$.value.$reset()
  formOpen.value = true
}

function openEdit(person: SuperadminItem) {
  editing.value = person
  form.permissions = [...person.permissions]
  formError.value = null
  v$.value.$reset()
  formOpen.value = true
}

async function submit() {
  if (!(await v$.value.$validate()))
    return
  saving.value = true
  formError.value = null
  try {
    if (editing.value) {
      await api(`/platform/superadmins/${editing.value.id}`, { method: 'PATCH', body: { permissions: form.permissions } })
      notice.value = { variant: 'success', text: `The permissions of ${editing.value.name} were saved.` }
    }
    else {
      const created = await api<SuperadminItem>('/platform/superadmins', {
        method: 'POST',
        body: { name: form.name.trim(), email: form.email.trim(), permissions: form.permissions },
      })
      notice.value = created.emailSent
        ? { variant: 'success', text: `We emailed a set-password link to ${created.email}. It works for 3 days.` }
        : { variant: 'danger', text: `${created.name} was added, but the email could not be sent. Pass this link on yourself:`, link: created.setPasswordUrl }
    }
    formOpen.value = false
    await load()
  }
  catch (e) {
    formError.value = messageOf(e, 'Could not save.')
  }
  finally {
    saving.value = false
  }
}

// ---- deactivate, reactivate ---------------------------------------------------------------------

const confirmOpen = ref(false)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)
const pending = ref<SuperadminItem | null>(null)

function askStatus(person: SuperadminItem) {
  pending.value = person
  confirmError.value = null
  confirmOpen.value = true
}

async function runStatus() {
  if (!pending.value)
    return
  confirmBusy.value = true
  confirmError.value = null
  try {
    await api(`/platform/superadmins/${pending.value.id}`, { method: 'PATCH', body: { status: pending.value.status === 'active' ? 'inactive' : 'active' } })
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
