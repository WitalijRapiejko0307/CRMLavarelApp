<template>
    <div
        v-if="visible"
        class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
    >
        <div class="bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-200 dark:border-indigo-800 text-indigo-900 dark:text-indigo-100 rounded-md px-4 py-3 text-sm">
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="flex items-center gap-2 min-w-0 text-left hover:text-indigo-950 dark:hover:text-white"
                    @click="expanded = !expanded"
                >
                    <span class="font-medium whitespace-nowrap">Первые шаги</span>
                    <span class="text-indigo-700/80 dark:text-indigo-300 whitespace-nowrap">{{ completedCount }} из {{ total }}</span>
                    <span class="text-indigo-700/70 dark:text-indigo-400" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
                </button>
                <button
                    type="button"
                    class="text-indigo-500 hover:text-indigo-700 dark:text-indigo-300 dark:hover:text-indigo-100 flex-shrink-0"
                    title="Скрыть"
                    @click="dismiss"
                >
                    ✕
                </button>
            </div>
            <ul v-if="expanded" class="mt-3 space-y-2">
                <li v-for="step in steps" :key="step.id">
                    <Link
                        :href="step.href"
                        class="flex items-start gap-2 py-0.5 hover:text-indigo-700 dark:hover:text-indigo-300"
                        :class="step.id === currentStep && !step.done ? 'font-medium' : ''"
                    >
                        <span class="w-4 text-center flex-shrink-0 mt-0.5" aria-hidden="true">{{ stepMark(step) }}</span>
                        <span class="min-w-0">
                            <span class="block">{{ step.title }}</span>
                            <span
                                v-if="step.id === currentStep && !step.done && step.hint"
                                class="block text-xs font-normal text-indigo-800/80 dark:text-indigo-200/80 mt-0.5"
                            >
                                {{ step.hint }}
                            </span>
                        </span>
                    </Link>
                </li>
            </ul>
            <div v-if="expanded && canSkipOptional" class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-indigo-800/80 dark:text-indigo-200/80">Остальные сервисы можно подключить позже.</p>
                <button
                    type="button"
                    class="btn-secondary btn-sm"
                    @click="skipOptional"
                >
                    Пока не нужно
                </button>
            </div>
        </div>
    </div>
    <div
        v-else-if="dismissed && currentStep"
        class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
    >
        <div class="rounded-md px-4 py-2 text-sm border border-indigo-200 dark:border-indigo-800 bg-indigo-50/70 dark:bg-indigo-900/20 text-indigo-800 dark:text-indigo-200 flex items-center justify-between gap-3">
            <span>Первые шаги скрыты</span>
            <button
                type="button"
                class="font-medium underline hover:no-underline flex-shrink-0"
                @click="restore"
            >
                Показать
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'
import { useOnboarding } from '@/composables/useOnboarding'

const { onboarding, visible, dismissed, currentStep, canSkipOptional } = useOnboarding()
const expanded = ref(true)

const steps = computed(() => onboarding.value?.steps ?? [])
const completedCount = computed(() => onboarding.value?.completed_count ?? 0)
const total = computed(() => onboarding.value?.total ?? 0)

function stepMark(step) {
    if (step.done) return '✓'
    if (step.id === currentStep.value) return '→'
    return '○'
}

function dismiss() {
    Inertia.post('/onboarding/dismiss', {}, { preserveScroll: true })
}

function restore() {
    Inertia.post('/onboarding/restore', {}, { preserveScroll: true })
}

function skipOptional() {
    Inertia.post('/onboarding/skip-optional', {}, { preserveScroll: true })
}
</script>
