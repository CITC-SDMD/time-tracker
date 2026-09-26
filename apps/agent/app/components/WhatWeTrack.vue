<template>
  <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm">
    <h2 class="mb-1 font-medium">
      What is tracked
    </h2>
    <ul class="list-disc space-y-1 pl-5">
      <li>When you start, pause, resume and stop tracking.</li>
      <li>Which app is in front, and for how long.</li>
      <li v-if="titlesTracked">
        The window title of that app.
      </li>
      <li v-else>
        Window titles are turned off by your office: only app names are kept.
      </li>
      <li>When you are idle (no mouse or keyboard use), and which app was on screen then.</li>
      <li v-if="screenshotMinutes > 0">
        A screenshot of your main screen about every {{ screenshotMinutes }} minutes<span v-if="screenshotRandom">, at a random moment in each block</span>,
        including while you are idle. It is kept permanently on the office server. Your manager and the people above them can see it, and so can you
        (see "My screenshots").
      </li>
    </ul>

    <h2 class="mb-1 mt-4 font-medium">
      What is not tracked
    </h2>
    <p>
      Keystrokes, typed text, mouse movements, webcam, microphone, file contents and websites.
      <span v-if="screenshotMinutes === 0">Screenshots are not taken; if your office turns them on, you are asked to accept a new notice first.</span>
      <span v-else>Only your main screen is captured, and only in the way described above.</span>
    </p>

    <h2 class="mb-1 mt-4 font-medium">
      When
    </h2>
    <p>
      Only while tracking is on. Nothing is tracked while you are paused, not tracking,
      or your PC is locked or asleep.
    </p>

    <h2 class="mb-1 mt-4 font-medium">
      Who can see it
    </h2>
    <p>
      Your manager and whoever is above them in the hierarchy. You can always see your own data.
      It is kept permanently on the office server.
    </p>
  </section>
</template>

<script setup lang="ts">
// Wording follows docs/DEVELOPMENT_PLAN.md §16. Shown on the consent screen and in Settings.
const { me } = useAuth()

const titlesTracked = computed(() => me.value?.officeSettings.windowTitleMode !== 'app_only')
const screenshotMinutes = computed(() => me.value?.officeSettings.screenshotIntervalMinutes ?? 0)
const screenshotRandom = computed(() => me.value?.officeSettings.screenshotRandom ?? false)
</script>
