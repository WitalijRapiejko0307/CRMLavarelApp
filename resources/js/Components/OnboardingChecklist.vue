<template>
    <div
        v-if="visible"
        class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4"
    >
        <div class="rounded-md px-4 py-2 text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/30 text-slate-800 dark:text-slate-200">
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    class="flex items-center gap-2 min-w-0 text-left hover:text-slate-950 dark:hover:text-white"
                    @click="expanded = !expanded"
                >
                    <span class="font-medium whitespace-nowrap">Первые шаги</span>
                    <span class="text-muted whitespace-nowrap">{{ completedCount }} из {{ total }}</span>
                    <span class="text-muted" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
                </button>
                <button
                    type="button"
                    class="text-slate-400 hover:text-slate-700 dark:text-slate-500 dark:hover:text-slate-200 flex-shrink-0"
                    title="Скрыть"
                    @click="dismiss"
                >
                    ✕
                </button>
            </div>
            <ul v-if="expanded" class="mt-2 space-y-1">
                <li v-for="step in steps" :key="step.id">
                    <Link
                        :href="step.href"
                        class="flex items-center gap-2 py-0.5 hover:text-indigo-700 dark:hover:text-indigo-300"
                        :class="step.id === currentStep && !step.done ? 'font-medium' : ''"
                    >
                        <span class="w-4 text-center flex-shrink-0" aria-hidden="true">{{ stepMark(step) }}</span>
                        <span>{{ step.title }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'
import { useOnboarding } from '@/composables/useOnboarding'

const { onboarding, visible, currentStep } = useOnboarding()
const expanded = ref(false)

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
</script>
