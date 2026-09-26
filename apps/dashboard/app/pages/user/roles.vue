<template>
  <div>
    <UiPageHeader
      title="Roles"
      description="A role is a set of permissions and how far it reaches. Give people the role that fits their work."
    >
      <template
        v-if="!readOnly"
        #actions
      >
        <FormButton @click="openAdd">
          Make a role
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
      :rows="roles"
      :loading="!loaded"
      :error="loaded ? null : error"
      empty-title="No roles yet"
      empty-description="Make the first one, then give it to the people you add."
    >
      <template #cell-name="{ row }">
        <span class="font-medium text-gray-900 dark:text-white">{{ row.name }}</span>
        <UiBadge
          v-if="row.isSystem"
          class="ml-2"
          variant="primary"
        >
          Built in
        </UiBadge>
        <span
          v-if="row.description"
          class="block text-xs text-gray-500 dark:text-gray-400"
        >{{ row.description }}</span>
      </template>
      <template #cell-scope="{ row }">
        {{ SCOPE_LABEL[row.scope as Scope] }}
      </template>
      <template #cell-permissions="{ row }">
        {{ row.isSystem ? 'All of them' : row.permissions.length }}
      </template>
      <template #cell-actions="{ row }">
        <div
          v-if="canEdit(row as RoleItem)"
          class="flex justify-end gap-x-2"
        >
          <FormButton
            variant="link"
            @click="openEdit(row as RoleItem)"
          >
            {{ row.isSystem ? 'Rename' : 'Edit' }}
          </FormButton>
          <FormButton
            v-if="!row.isSystem"
            variant="link"
            class="text-red-600 hover:text-red-500 dark:text-red-400"
            @click="ask(row as RoleItem)"
          >
            Delete
          </FormButton>
        </div>
      </template>
    </UiTable>

    <UiDrawer
      v-model="formOpen"
      :title="editing ? `${editing.isSystem ? 'Rename' : 'Edit'} ${editing.name}` : 'Make a role'"
      :persistent="saving"
    >
      <form
        class="space-y-5"
        novalidate
        @submit.prevent="submit"
      >
        <FormInput
          v-model="form.name"
          label="Name"
          autocomplete="off"
          hint="What people in this organization call it, for example Team Leader or Records Officer."
          :errors="v$.name.$errors"
          @blur="v$.name.$touch()"
        />
        <FormInput
          v-model="form.description"
          label="Description (optional)"
          autocomplete="off"
          :errors="v$.description.$errors"
          @blur="v$.description.$touch()"
        />
        <UiAlert v-if="editing?.isSystem">
          The built-in admin role keeps every permission and reaches the whole organization. Only its name and description can change.
        </UiAlert>
        <template v-else>
          <FormRadioGroup
            v-model="form.scope"
            label="How far the role reaches"
            name="role-scope"
            :options="scopeOptions"
            :errors="v$.scope.$errors"
          />
          <FormPermissionPicker
            v-model="form.permissions"
            label="What the role may do"
            :permissions="catalog"
            :available="permissions"
            :errors="v$.permissions.$errors"
          />
          <p class="text-sm/6 text-gray-500 dark:text-gray-400">
            You can only give permissions you have yourself, and a reach no wider than yours.
          </p>
        </template>
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
            {{ saving ? 'Saving…' : 'Save role' }}
          </FormButton>
        </div>
      </form>
    </UiDrawer>

    <UiConfirmDialog
      v-model="confirmOpen"
      :title="`Delete ${deleting?.name ?? ''}?`"
      message="This cannot be undone. A role that people hold cannot be deleted: give them another role first."
      confirm-label="Delete"
      danger
      :loading="confirmBusy"
      :error="confirmError"
      @confirm="runDelete"
    />
  </div>
</template>

<script setup lang="ts">
import { useVuelidate } from '@vuelidate/core'
import { helpers, maxLength, required } from '@vuelidate/validators'
import { roleProblem, SCOPE_RANK, SCOPE_LABEL, type Permission, type PermissionCatalog, type RoleItem, type Scope } from 'shared'

definePageMeta({
  layout: 'user',
  permission: 'roles.manage',
  alias: ['/platform/organizations/:orgId/office/roles'],
})

// Each organization makes its own roles from the platform's fixed list of permissions (docs/DEVELOPMENT_PLAN.md §9.1).
// A role that only reaches the person cannot hold permissions about others, settings, the audit log and roles need
// the whole organization, and nobody gives a permission or a reach they do not have: this page says so while the form is
// filled in, and the API checks every rule again.
const { api } = useApi()
const { me } = useAuth()
const { permissions, scope: myScope, readOnly } = useAccess()

const COLUMNS = [
  { key: 'name', label: 'Role' },
  { key: 'scope', label: 'Reaches' },
  { key: 'permissions', label: 'Permissions', align: 'right' as const },
  { key: 'memberCount', label: 'People', align: 'right' as const },
  { key: 'actions', label: '', align: 'right' as const },
]

const roles = ref<RoleItem[]>([])
const catalogData = ref<PermissionCatalog | null>(null)
const loaded = ref(false)
const error = ref<string | null>(null)
const notice = ref<{ variant: 'success' | 'danger', text: string } | null>(null)

const catalog = computed(() => catalogData.value?.permissions ?? [])

async function load() {
  try {
    const [list, permissionCatalog] = await Promise.all([api<RoleItem[]>('/roles'), api<PermissionCatalog>('/permissions')])
    roles.value = list
    catalogData.value = permissionCatalog
    error.value = null
  }
  catch (e) {
    error.value = messageOf(e, 'Could not load the roles.')
  }
  finally {
    loaded.value = true
  }
}

onMounted(load)

// you can change a role you could give yourself, and never the one you hold
function canEdit(role: RoleItem): boolean {
  return !readOnly.value && role.assignable && role.id !== me.value?.role?.id
}

// only reaches no wider than the person's own
const scopeOptions = computed(() => (catalogData.value?.scopes ?? [])
  .filter(s => SCOPE_RANK[s.key] <= SCOPE_RANK[myScope.value])
  .map(s => ({ value: s.key, label: s.label, description: s.description })))

// ---- make or edit -----------------------------------------------------------------------------

const formOpen = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)
const editing = ref<RoleItem | null>(null)
const form = reactive({ name: '', description: '', scope: 'self' as Scope, permissions: [] as string[] })

const rules = {
  name: {
    required: helpers.withMessage('Enter a name for the role.', required),
    maxLength: helpers.withMessage('Use at most 100 characters.', maxLength(100)),
  },
  description: { maxLength: helpers.withMessage('Use at most 255 characters.', maxLength(255)) },
  scope: { required: helpers.withMessage('Choose how far the role reaches.', required) },
  // the same rule the server applies to the pair
  permissions: { pairing: helpers.withMessage(() => roleProblem(form.scope, form.permissions as Permission[]) ?? '', () => roleProblem(form.scope, form.permissions as Permission[]) === null) },
}
const v$ = useVuelidate(rules, form)

function openAdd() {
  editing.value = null
  form.name = ''
  form.description = ''
  form.scope = 'self'
  form.permissions = []
  formError.value = null
  v$.value.$reset()
  formOpen.value = true
}

function openEdit(role: RoleItem) {
  editing.value = role
  form.name = role.name
  form.description = role.description ?? ''
  form.scope = role.scope
  form.permissions = [...role.permissions]
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
    name: form.name.trim(),
    description: form.description.trim() || null,
    ...(editing.value?.isSystem ? {} : { scope: form.scope, permissions: form.permissions }),
  }
  try {
    if (editing.value)
      await api(`/roles/${editing.value.id}`, { method: 'PATCH', body })
    else
      await api('/roles', { method: 'POST', body })
    notice.value = { variant: 'success', text: `The role ${body.name} was saved.` }
    formOpen.value = false
    await load()
  }
  catch (e) {
    formError.value = messageOf(e, 'Could not save the role.')
  }
  finally {
    saving.value = false
  }
}

// ---- delete -----------------------------------------------------------------------------------

const confirmOpen = ref(false)
const confirmBusy = ref(false)
const confirmError = ref<string | null>(null)
const deleting = ref<RoleItem | null>(null)

function ask(role: RoleItem) {
  deleting.value = role
  confirmError.value = null
  confirmOpen.value = true
}

async function runDelete() {
  if (!deleting.value)
    return
  confirmBusy.value = true
  confirmError.value = null
  try {
    await api(`/roles/${deleting.value.id}`, { method: 'DELETE' })
    notice.value = { variant: 'success', text: `The role ${deleting.value.name} was deleted.` }
    confirmOpen.value = false
    await load()
  }
  catch (e) {
    confirmError.value = messageOf(e, 'Could not delete the role.')
  }
  finally {
    confirmBusy.value = false
  }
}
</script>
