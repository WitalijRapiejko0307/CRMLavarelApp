<template>
    <div
        v-if="open"
        class="modal-overlay p-4"
        @click.self="dismiss"
    >
        <div
            class="modal-box max-w-lg"
            role="dialog"
            aria-modal="true"
            aria-labelledby="onboarding-welcome-title"
        >
            <h2 id="onboarding-welcome-title" class="section-title mb-2">
                Добро пожаловать
            </h2>
            <p class="text-sm text-body mb-3">
                Магазин уже создан. Чтобы принимать и отправлять заказы, закройте три шага — начнём с Белпочты на этой странице.
            </p>
            <ol class="space-y-3 mb-4">
                <li
                    v-for="(step, index) in steps"
                    :key="step.id"
                    class="flex gap-3"
                >
                    <span
                        class="flex-shrink-0 w-6 h-6 rounded-full bg-indigo-100 dark:bg-indigo-900/50 text-indigo-700 dark:text-indigo-200 text-xs font-semibold flex items-center justify-center mt-0.5"
                    >
                        {{ index + 1 }}
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ step.title }}</p>
                        <p v-if="step.hint" class="text-xs text-muted mt-0.5">{{ step.hint }}</p>
                    </div>
                </li>
            </ol>
            <p class="text-xs text-muted mb-5">
                Чеклист «Первые шаги» останется в шапке. Заявки с сайта и остальные сервисы — позже, в свёрнутых блоках.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button type="button" class="btn-secondary justify-center" @click="dismiss">
                    Понятно
                </button>
                <button type="button" class="btn-primary justify-center" @click="start">
                    {{ ctaLabel }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { useOnboarding } from '@/composables/useOnboarding'

const { onboarding, showWelcome, currentStep } = useOnboarding()
const open = ref(false)
const submitted = ref(false)

const steps = computed(() => onboarding.value?.steps ?? [])
const current = computed(() =>
    steps.value.find(step => step.id === currentStep.value) ?? steps.value[0] ?? null
)

const ctaLabel = computed(() => {
    switch (current.value?.id) {
        case 'settings': return 'Заполнить Белпочту'
        case 'products': return 'Перейти к товарам'
        case 'belpost':  return 'Перейти к партиям'
        case 'other':    return 'Открыть настройки'
        default:         return 'Начать'
    }
})

watch(showWelcome, (value) => {
    if (value && !submitted.value) {
        open.value = true
    }
}, { immediate: true })

function markSeen() {
    if (submitted.value) return
    submitted.value = true
    Inertia.post('/onboarding/welcome-seen', {}, { preserveScroll: true })
}

function dismiss() {
    open.value = false
    markSeen()
}

function scrollToBelpost() {
    document.getElementById('settings-belpost')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    })
}

function start() {
    const href = current.value?.href || '/settings'
    const stepId = current.value?.id
    open.value = false
    markSeen()

    nextTick(() => {
        if (stepId === 'settings' || href === '/settings') {
            if (window.location.pathname.startsWith('/settings')) {
                scrollToBelpost()
            } else {
                Inertia.visit('/settings', {
                    onSuccess: () => {
                        requestAnimationFrame(scrollToBelpost)
                    },
                })
            }
            return
        }
        Inertia.visit(href)
    })
}
</script>
