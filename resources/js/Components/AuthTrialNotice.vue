<template>
    <p class="-mx-8 -mt-8 mb-6 rounded-t-xl bg-indigo-50 px-8 py-3 text-center text-sm font-medium uppercase tracking-wide text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200">
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
