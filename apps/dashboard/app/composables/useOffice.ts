import type { OrganizationItem } from 'shared'

// "Opening an office" (docs/DEVELOPMENT_PLAN.md §9.4): a superadmin looks at, or works in, an organization through
// the very same pages its own people use, at /platform/organizations/{id}/office/... instead of /user/... . This
// composable knows whether the current page is one of those and turns organization paths into the right ones:
// the links between pages (`to`) and the API calls (see useApi, which prefixes them).
export function useOffice() {
  const route = useRoute()
  /** the organization being opened, loaded by the layout (its name for the banner, its timezone for the times) */
  const organization = useState<OrganizationItem | null>('office:organization', () => null)

  const id = computed(() => OFFICE_PATH.exec(route.path)?.[1] ?? null)
  const inOffice = computed(() => id.value !== null)
  const prefix = computed(() => (id.value ? `/platform/organizations/${id.value}/office` : ''))

  /** '/user/people' is '/platform/organizations/5/office/people' when an office is open, and itself otherwise. */
  function to(path: string): string {
    if (!inOffice.value)
      return path
    return path === '/user' ? prefix.value : prefix.value + path.slice('/user'.length)
  }

  return { id, inOffice, prefix, organization, to }
}
