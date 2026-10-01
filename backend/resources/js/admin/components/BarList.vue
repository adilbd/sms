<template>
  <ul class="space-y-3" :aria-label="label">
    <li v-for="item in items" :key="item.key" class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-sm">
      <span class="truncate text-gray-700" :title="item.label">{{ item.label }}</span>
      <!-- The bar only repeats the number printed beside it, so assistive tech reads the text. -->
      <div
        class="h-3 overflow-hidden rounded bg-gray-100"
        role="progressbar"
        :aria-label="item.label"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="barWidth(item)"
        :aria-valuetext="item.text"
      >
        <div class="h-full rounded bg-primary-600" :style="{ width: `${barWidth(item)}%` }"></div>
      </div>
      <span class="whitespace-nowrap text-right font-medium text-gray-900">{{ item.text }}</span>
    </li>
  </ul>
</template>

<script setup>
// A list of horizontal bars, one hue, each with its value as text beside it. `value` is a
// share from 0 to 100 (null leaves the bar empty, for "not marked yet"), `text` what to print.
defineProps({
  items: { type: Array, required: true }, // [{ key, label, value, text }]
  label: { type: String, default: undefined },
})

const barWidth = (item) => Math.min(100, Math.max(0, Number(item.value) || 0))
</script>
