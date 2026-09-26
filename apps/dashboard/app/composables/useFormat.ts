// Display helpers. Every time on the dashboard is shown in the office timezone
// (docs/DEVELOPMENT_PLAN.md §12 Phase 6), not in the browser's own.

/** 26520 -> "7h 22m", 18120 -> "5h 02m", 1980 -> "33m", 45 -> "45s". */
export function formatDuration(totalSeconds: number): string {
  const s = Math.max(0, Math.round(totalSeconds))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (h > 0)
    return `${h}h ${String(m).padStart(2, '0')}m`
  if (m > 0)
    return `${m}m`
  return `${s}s`
}

/** "2026-09-25" for "now" in the given timezone. */
export function dayIn(timezone: string, moment: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(moment)
}

/** "2026-09-25" moved by `days` (calendar arithmetic, no timezone involved). */
export function shiftDay(day: string, days: number): string {
  const [y, m, d] = day.split('-').map(Number) as [number, number, number]
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10)
}

/** 1536 -> "1.5 KB", 7340032 -> "7.0 MB", 2147483648 -> "2.0 GB". */
export function formatBytes(bytes: number): string {
  if (bytes < 1024)
    return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(1)} ${units[unit]}`
}

export function useFormat() {
  const { me } = useAuth()
  const timezone = computed(() => me.value?.officeSettings.timezone ?? 'UTC')

  /** "14:05" */
  function formatTime(iso: string | null): string {
    if (!iso)
      return '—'
    return new Intl.DateTimeFormat('en-GB', { timeZone: timezone.value, hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(iso))
  }

  /** "25 Sep, 14:05" */
  function formatDateTime(iso: string | null): string {
    if (!iso)
      return '—'
    return new Intl.DateTimeFormat('en-GB', { timeZone: timezone.value, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(iso))
  }

  /** "Fri 25 Sep 2026" for a YYYY-MM-DD day. */
  function formatDay(day: string): string {
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${day}T00:00:00Z`))
  }

  const today = () => dayIn(timezone.value)

  return { timezone, formatBytes, formatDuration, formatTime, formatDateTime, formatDay, today, shiftDay }
}
