<template>
    <div
        v-if="visible"
        class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
    >
        <div
            :class="[
                'rounded-md px-4 py-3 text-sm border',
                bannerClass,
            ]"
        >
            {{ message }}
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useSubscription } from '@/composables/useSubscription'

const { readOnly, trialDaysLeft, isTrial } = useSubscription()

const visible = computed(() => readOnly.value || (isTrial.value && trialDaysLeft.value !== null))

const message = computed(() => {
    if (readOnly.value) {
        return 'Пробный период закончился. Доступен только просмотр.'
    }

    const days = trialDaysLeft.value ?? 0
    const suffix = days === 1 ? 'день' : days >= 2 && days <= 4 ? 'дня' : 'дней'

    return `Осталось ${days} ${suffix} пробного периода.`
})

const bannerClass = computed(() => {
    if (readOnly.value) {
        return 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200'
    }

    if ((trialDaysLeft.value ?? 0) <= 3) {
        return 'bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-100'
    }

    return 'bg-slate-50 dark:bg-slate-900/30 border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-200'
})
</script>
