<template>
  <div>
    <UiPageHeader
      title="Organizations"
      description="The offices that use Time Tracker. Open one to see its people and what it tracks, if your permissions allow it."
    >
      <template
        v-if="canPlatform('organizations.create')"
        #actions
      >
        <FormButton @click="openAdd">
          Create an organization
        </FormButton>
      </template>
    </UiPageHeader>

    <UiAlert
      v-if="!canPlatform('organizations.view')"
      variant="warning"
    >
      Your account has no permission to list organizations. Ask the platform owner for it.
    </UiAlert>

    <UiTable
      v-else
      :columns="COLUMNS"
      :rows="organizations"
      :loading="!loaded"
      :error="loaded ? null : error"
      :row-link="(row) => `/platform/organizations/${row.id}`"
      empty-title="No organizations yet"
      empty-description="Create the first one, then add its admin."
    >
      <template #cell-name="{ row }">
        <UiLink :to="`/platform/organizations/${row.id}`">
          {{ row.name }}
        </UiLink>
      </template>
      <template #cell-status="{ row }">
        <UiBadge
          dot
          :variant="row.status === 'active' ? 'success' : 'danger'"
        >
          {{ row.status === 'active' ? 'Active' : 'Suspended' }}
        </UiBadge>
      </template>
      <template #cell-storageBytes="{ row }">
        {{ formatBytes(row.storageBytes) }}
      </template>
    </UiTable>

    <UiModal
      v-model="addOpen"
      title="Create an organization"
      :persistent="adding"
    >
      <form
        class="space-y-4"
        novalidate
        @submit.prevent="submitAdd"
      >
        <p class="text-sm/6 text-gray-500 dark:text-gray-400">
          It starts with nothing but its admin role. Add its admin on the next page: they then make their own roles and people.
        </p>
        <FormInput
          v-model="form.name"
          label="Name"
          autocomplete="off"
          :errors="v$.name.$errors"
          @blur="v$.name.$touch()"
        />
        <FormSelect
          v-model="form.timezone"
          label="Timezone"
          :options="timezoneOptions"
          hint="What counts as a day for this organization. Its admin can change it."
          :errors="v$.timezone.$errors"
          @blur="v$.timezone.$touch()"
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
            {{ adding ? 'Creating…' : 'Create' }}
          </FormButton>
        </div>
      </form>
    </UiModal>
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, maxLength, required } from '@vuelidate/validators'
import type { OrganizationItem } from 'shared'

definePageMeta({
  layout: 'user',
})

// The platform's home (docs/DEVELOPMENT_PLAN.md §9.4): every organization with numbers only. Which buttons show follows the
// superadmin's platform permissions; the API checks them again.
const { api } = useApi()
const { canPlatform } = useAccess()
const { formatBytes } = useFormat()

const COLUMNS = [
  { key: 'name', label: 'Organization' },
  { key: 'status', label: 'Status' },
  { key: 'peopleCount', label: 'People', align: 'right' as const },
  { key: 'adminCount', label: 'Admins', align: 'right' as const },
  { key: 'storageBytes', label: 'Screenshot storage', align: 'right' as const },
]

const organizations = ref<OrganizationItem[]>([])
const loaded = ref(false)
const error = ref<string | null>(null)

async function load() {
  if (!canPlatform('organizations.view')) {
    loaded.value = true
    return
  }
  try {
    organizations.value = await api<OrganizationItem[]>('/platform/organizations')
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the organizations.')
  }
  finally {
    loaded.value = true
  }
}

onMounted(load)

// ---- create -----------------------------------------------------------------------------------

const addOpen = ref(false)
const adding = ref(false)
const addError = ref<string | null>(null)
const form = reactive({ name: '', timezone: 'Asia/Manila' })
const v$ = useVuelidate({
  name: {
    required: helpers.withMessage('Enter the name of the organization.', required),
    maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)),
  },
  timezone: { required: helpers.withMessage('Choose a timezone.', required) },
}, form)

const timezoneOptions = computed(() => {
  const zones = new Set<string>(['UTC', ...Intl.supportedValuesOf('timeZone')])
  return [...zones].sort().map(zone => ({ value: zone, label: zone.replaceAll('_', ' ') }))
})

function openAdd() {
  form.name = ''
  form.timezone = 'Asia/Manila'
  addError.value = null
  v$.value.$reset()
  addOpen.value = true
}

async function submitAdd() {
  if (!(await v$.value.$validate()))
    return
  adding.value = true
  addError.value = null
  try {
    const created = await api<OrganizationItem>('/platform/organizations', { method: 'POST', body: { name: form.name.trim(), timezone: form.timezone } })
    addOpen.value = false
    await navigateTo(`/platform/organizations/${created.id}`)
  }
  catch (e) {
    addError.value = messageOf(e, 'Could not create the organization.')
  }
  finally {
    adding.value = false
  }
}
</script>
