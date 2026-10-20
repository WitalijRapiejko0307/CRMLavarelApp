<template>
    <div
        v-if="visible"
        class="page-shell mt-4"
    >
        <div
            :class="[
                'rounded-md px-4 py-3 text-sm border flex flex-wrap items-center justify-between gap-2',
                bannerClass,
            ]"
        >
            <span>{{ message }}</span>
            <button
                v-if="showDismiss"
                type="button"
                class="shrink-0 underline underline-offset-2 hover:no-underline"
                @click="dismissBanner"
            >
                Скрыть
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { useSubscription } from '@/composables/useSubscription'

const {
    readOnly,
    accessDaysLeft,
    accessEndsAt,
    isTrial,
    bannerDismissed,
} = useSubscription()

function formatAccessDate(iso) {
    if (!iso) return ''
    const date = new Date(iso)
    return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

const visible = computed(() => {
    if (readOnly.value) {
        return true
    }

    const days = accessDaysLeft.value
    if (days === null) {
        return false
    }

    if (days > 3) {
        return false
    }

    if (bannerDismissed.value) {
        return false
    }

    return true
})

const message = computed(() => {
    if (readOnly.value) {
        return 'Доступен только просмотр.'
    }

    const date = formatAccessDate(accessEndsAt.value)

    if (isTrial.value) {
        return `Пробный период закончится ${date}.`
    }

    return `Подписка закончится ${date}.`
})

const bannerClass = computed(() => {
    if (readOnly.value) {
        return 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-800 dark:text-red-200'
    }

    return 'bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-100'
})

const showDismiss = computed(() => !readOnly.value)

function dismissBanner() {
    Inertia.post('/subscription/dismiss-banner')
}
</script>
