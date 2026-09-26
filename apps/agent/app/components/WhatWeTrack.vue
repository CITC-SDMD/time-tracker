<template>
  <UiCard class="text-[13px] leading-snug">
    <template v-if="page === 1">
      <h2 class="mb-1 text-sm font-semibold">
        What is tracked
      </h2>
      <ul class="list-disc space-y-1 pl-5">
        <li>When you start, pause, resume and stop tracking.</li>
        <li>Which app is in front, and for how long.</li>
        <li v-if="titlesTracked">
          The window title of that app.
        </li>
        <li v-else>
          Window titles are turned off by your organization: only app names are kept.
        </li>
        <li>When you are idle (no mouse or keyboard use), and which app was on screen then.</li>
        <li>Whether this computer is a virtual machine or a remote session, read from the computer's model and not from anything you do. Only your organization's admins see it, and it can be switched off for you.</li>
        <li v-if="screenshotMinutes > 0">
          A screenshot of your main screen about every {{ screenshotMinutes }} minutes<span v-if="screenshotRandom">, at a random moment in each block</span>,
          including while you are idle. It is kept permanently on your organization's server. The people your organization allows (usually your manager and the people above them) can see it, and so can you
          (see "My screenshots").
        </li>
      </ul>

      <h2 class="mb-1 mt-3 text-sm font-semibold">
        What is not tracked
      </h2>
      <p>
        Keystrokes, typed text, mouse movements, webcam, microphone, file contents and websites.
        <span v-if="screenshotMinutes === 0">Screenshots are not taken; if your organization turns them on, you are asked to accept a new notice first.</span>
        <span v-else>Only your main screen is captured, and only in the way described above.</span>
      </p>
    </template>

    <template v-else>
      <h2 class="mb-1 text-sm font-semibold">
        When
      </h2>
      <p>
        Only while tracking is on. Nothing is tracked while you are paused, not tracking,
        or your PC is locked or asleep.
      </p>

      <h2 class="mb-1 mt-3 text-sm font-semibold">
        Who can see it
      </h2>
      <p>
        The people your organization allows (usually your manager and whoever is above them). You can always see your own data.
        It is kept permanently on your organization's server.
      </p>
    </template>
  </UiCard>
</template>

<script setup lang="ts">
// Wording follows docs/DEVELOPMENT_PLAN.md §16. It is two short pages so it fits the window without
// scrolling; shown on the consent screen and, read-only, from Settings.
defineProps<{ page: 1 | 2 }>()

const { me } = useAuth()

const titlesTracked = computed(() => me.value?.settings.windowTitleMode !== 'app_only')
const screenshotMinutes = computed(() => me.value?.settings.screenshotIntervalMinutes ?? 0)
const screenshotRandom = computed(() => me.value?.settings.screenshotRandom ?? false)
</script>
