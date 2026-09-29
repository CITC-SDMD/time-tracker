<template>
  <div>
    <TransitionRoot
      as="template"
      :show="sidebarOpen"
    >
      <Dialog
        class="relative z-50 lg:hidden"
        @close="sidebarOpen = false"
      >
        <TransitionChild
          as="template"
          enter="transition-opacity ease-linear duration-300"
          enter-from="opacity-0"
          enter-to=""
          leave="transition-opacity ease-linear duration-300"
          leave-from=""
          leave-to="opacity-0"
        >
          <div class="fixed inset-0 bg-gray-900/80" />
        </TransitionChild>

        <div class="fixed inset-0 flex">
          <TransitionChild
            as="template"
            enter="transition ease-in-out duration-300 transform"
            enter-from="-translate-x-full"
            enter-to="translate-x-0"
            leave="transition ease-in-out duration-300 transform"
            leave-from="translate-x-0"
            leave-to="-translate-x-full"
          >
            <DialogPanel class="relative mr-16 flex w-full max-w-xs flex-1">
              <TransitionChild
                as="template"
                enter="ease-in-out duration-300"
                enter-from="opacity-0"
                enter-to=""
                leave="ease-in-out duration-300"
                leave-from=""
                leave-to="opacity-0"
              >
                <div class="absolute top-0 left-full flex w-16 justify-center pt-5">
                  <FormButton
                    variant="plain"
                    class="-m-2.5 p-2.5"
                    @click="sidebarOpen = false"
                  >
                    <span class="sr-only">Close sidebar</span>
                    <XMarkIcon
                      class="size-6 text-white"
                      aria-hidden="true"
                    />
                  </FormButton>
                </div>
              </TransitionChild>

              <div class="relative flex grow flex-col bg-white ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                <AppSidebarNav
                  :brand="brand"
                  :subtitle="subtitle"
                  :items="navigation"
                  @navigate="sidebarOpen = false"
                />
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </Dialog>
    </TransitionRoot>

    <!-- Static sidebar for desktop -->
    <div class="hidden bg-white ring-1 ring-gray-950/5 lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col dark:bg-gray-950 dark:ring-white/10">
      <AppSidebarNav
        :brand="brand"
        :subtitle="subtitle"
        :items="navigation"
      />
    </div>

    <div class="lg:pl-72">
      <AppOfficeBanner v-if="office.inOffice.value" />
      <div
        class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 bg-white px-4 shadow-xs ring-1 ring-gray-950/5 sm:gap-x-6 sm:px-6 lg:px-8 dark:bg-gray-900 dark:ring-white/10"
      >
        <FormButton
          variant="plain"
          class="-m-2.5 p-2.5 text-gray-700 hover:text-gray-900 lg:hidden dark:text-gray-400 dark:hover:text-white"
          @click="sidebarOpen = true"
        >
          <span class="sr-only">Open sidebar</span>
          <Bars3Icon
            class="size-6"
            aria-hidden="true"
          />
        </FormButton>

        <!-- Separator -->
        <div
          class="h-6 w-px bg-gray-900/10 lg:hidden dark:bg-white/10"
          aria-hidden="true"
        />

        <div class="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
          <div class="flex-1" />
          <div class="flex items-center gap-x-4 lg:gap-x-6">
            <UiThemeToggle />
            <!-- Profile dropdown -->
            <Menu
              as="div"
              class="relative"
            >
              <MenuButton class="relative flex items-center">
                <span class="absolute -inset-1.5" />
                <span class="sr-only">Open user menu</span>
                <span
                  class="flex size-8 items-center justify-center rounded-full bg-primary-500 text-sm/6 font-semibold text-gray-950"
                  aria-hidden="true"
                >{{ initials }}</span>
                <span class="hidden lg:flex lg:items-center">
                  <span
                    class="ml-4 text-sm/6 font-semibold text-gray-900 dark:text-white"
                    aria-hidden="true"
                  >{{ me?.name }}</span>
                  <ChevronDownIcon
                    class="ml-2 size-5 text-gray-400 dark:text-gray-500"
                    aria-hidden="true"
                  />
                </span>
              </MenuButton>
              <transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95"
                enter-to-class="transform scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="transform scale-100"
                leave-to-class="transform opacity-0 scale-95"
              >
                <MenuItems
                  class="absolute right-0 z-10 mt-2.5 w-44 origin-top-right rounded-md bg-white py-2 shadow-lg outline outline-gray-900/5 dark:bg-gray-800 dark:shadow-none dark:-outline-offset-1 dark:outline-white/10"
                >
                  <MenuItem
                    v-for="item in userNavigation"
                    :key="item.name"
                    v-slot="{ active }"
                  >
                    <a
                      :href="item.href"
                      :class="[active ? 'bg-gray-50 outline-hidden dark:bg-white/5' : '', 'block px-3 py-1 text-sm/6 text-gray-900 dark:text-white']"
                      @click="item.action && ($event.preventDefault(), item.action())"
                    >{{
                      item.name }}</a>
                  </MenuItem>
                </MenuItems>
              </transition>
            </Menu>
          </div>
        </div>
      </div>

      <main class="py-10">
        <div class="px-4 sm:px-6 lg:px-8">
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import {
  Dialog,
  DialogPanel,
  Menu,
  MenuButton,
  MenuItem,
  MenuItems,
  TransitionChild,
  TransitionRoot,
} from '@headlessui/vue'
import {
  Bars3Icon,
  BuildingOffice2Icon,
  ChartPieIcon,
  CheckCircleIcon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  HomeIcon,
  KeyIcon,
  ShieldCheckIcon,
  UsersIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import { ChevronDownIcon } from '@heroicons/vue/20/solid'

// ends the session on the server, clears the signed-in person and goes back to the sign-in page
const { me, isSuperadmin, logout } = useAuth()
const { can, canPlatform } = useAccess()
const office = useOffice()
const { api } = useApi()

const ICONS = {
  overview: HomeIcon,
  people: UsersIcon,
  reports: ChartPieIcon,
  tasks: CheckCircleIcon,
  roles: ShieldCheckIcon,
  settings: Cog6ToothIcon,
  audit: ClipboardDocumentListIcon,
  organizations: BuildingOffice2Icon,
  superadmins: KeyIcon,
}

// what the sidebar shows follows the permissions: an organization's own pages (also inside an opened office), or the platform pages
const navigation = computed(() => {
  if (!me.value)
    return []
  const list = isSuperadmin.value && !office.inOffice.value ? PLATFORM_NAV : ORGANIZATION_NAV
  return list
    .filter(item => (!item.permission || can(item.permission)) && (!item.platformPermission || canPlatform(item.platformPermission)))
    .map(item => ({ ...item, to: office.to(item.to), icon: ICONS[item.icon] }))
})

// an opened office shows its own name, so a superadmin never mistakes it for the platform
watch(() => office.id.value, async (id) => {
  if (!id) {
    office.organization.value = null
    return
  }
  try {
    office.organization.value = await api(`/platform/organizations/${id}`)
  }
  catch {
    office.organization.value = null
  }
}, { immediate: true })

const brand = computed(() => {
  if (office.inOffice.value)
    return office.organization.value?.name ?? 'Organization'
  return me.value?.organization?.name ?? 'Time Tracker'
})
const subtitle = computed(() => (office.inOffice.value || me.value?.organization ? 'Time Tracker' : 'Platform'))

const initials = computed(() => (me.value?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0].toUpperCase()).join(''))

const userNavigation = [
  { name: 'Your profile', href: '#', action: () => navigateTo('/user/profile') },
  { name: 'Sign out', href: '#', action: logout },
]

const sidebarOpen = ref(false)
</script>
