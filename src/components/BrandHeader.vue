<script setup>
defineProps({
  label: {
    type: String,
    default: '',
  },
})
</script>

<template>
  <header class="album-shell pt-5 sm:pt-10">
    <div class="flex items-center justify-between gap-4 border-b border-hairline pb-4 sm:pb-5">
      <h1 class="text-2xl font-medium tracking-tight text-ink sm:text-3xl">InstanFoto</h1>
      <p v-if="label" class="text-right text-sm leading-snug text-muted">{{ label }}</p>
    </div>
  </header>
</template>
