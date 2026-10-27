<template>
    <p class="mb-6 rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200">
        {{ trialDays }} {{ daysWord }} без оплаты. Карта не нужна.
    </p>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    trialDays: { type: Number, required: true },
})

const daysWord = computed(() => {
    const n = Math.abs(props.trialDays)
    const mod10 = n % 10
    const mod100 = n % 100
    if (mod10 === 1 && mod100 !== 11) return 'день'
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'дня'
    return 'дней'
})
</script>
