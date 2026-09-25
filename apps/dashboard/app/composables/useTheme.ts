export type Theme = 'light' | 'dark'

// Light or dark. Until the person picks one it follows their system; once they do, the choice is
// remembered in this browser. The `dark` class on <html> is what Tailwind's `dark:` styles read
// (the head script in nuxt.config.ts sets it before the first paint).
export function useTheme() {
  const theme = useState<Theme>('theme', () => 'light')

  function apply(next: Theme) {
    theme.value = next
    document.documentElement.classList.toggle('dark', next === 'dark')
  }

  /** Reads what the head script decided, so the toggle shows the right icon. */
  function init() {
    theme.value = document.documentElement.classList.contains('dark') ? 'dark' : 'light'
  }

  function toggle() {
    const next: Theme = theme.value === 'dark' ? 'light' : 'dark'
    apply(next)
    try {
      localStorage.setItem('theme', next)
    }
    catch {
      // storage is blocked (private window): the choice lasts until the page is closed
    }
  }

  return { theme, init, toggle }
}
