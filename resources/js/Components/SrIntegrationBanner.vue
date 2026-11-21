<template>
    <div v-if="visible" class="page-shell mt-4">
        <div
            class="rounded-md px-4 py-3 text-sm flex items-start justify-between gap-3 border"
            :class="critical
                ? 'bg-red-50 dark:bg-red-900/30 border-red-200 dark:border-red-800 text-red-900 dark:text-red-100'
                : 'bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800 text-amber-900 dark:text-amber-100'"
        >
            <div class="min-w-0 space-y-1">
                <p class="font-medium">{{ title }}</p>
                <p v-if="critical" class="text-xs opacity-90">{{ health.message }}</p>
                <p v-if="problemText" class="text-xs opacity-90">{{ problemText }}</p>

                <div v-if="isAdmin" class="flex flex-wrap gap-2 pt-1">
                    <button
                        v-if="canRequestRights"
                        type="button"
                        class="btn-secondary btn-sm"
                        @click="modalOpen = true"
                    >
                        Запросить права у колл-центра
                    </button>
                    <Link v-if="critical" href="/settings#settings-salesrender" class="btn-secondary btn-sm">
                        Настройки и проверка
                    </Link>
                    <Link
                        v-if="problem.total > 0"
                        href="/orders?sr=problem"
                        class="btn-secondary btn-sm"
                    >
                        Показать заявки ({{ problem.total }})
                    </Link>
                </div>
            </div>
            <button
                v-if="!critical"
                type="button"
                class="opacity-60 hover:opacity-100 flex-shrink-0"
                title="Скрыть до обновления страницы"
                @click="dismissed = true"
            >
                ✕
            </button>
        </div>

        <SrRequestRightsModal
            :open="modalOpen"
            :text="sr.request_text || ''"
            @close="modalOpen = false"
        />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/inertia-vue3'
import SrRequestRightsModal from '@/Components/SrRequestRightsModal.vue'

const page = usePage()
const dismissed = ref(false)
const modalOpen = ref(false)

const sr = computed(() => page.props.value.sr_integration ?? null)
const health = computed(() => sr.value?.health ?? { state: 'ok', message: '', missing: [] })
const problem = computed(() => sr.value?.problem_orders ?? { failed: 0, blocked: 0, conflict: 0, total: 0 })
const isAdmin = computed(() => ['admin', 'super_admin'].includes(page.props.value.auth?.user?.role))

// Token / permission / settings problems stay on screen until they are fixed.
const critical = computed(() => health.value.state !== 'ok')
const canRequestRights = computed(() =>
    ['permission_denied', 'auth_failed'].includes(health.value.state) && !!sr.value?.request_text
)
const visible = computed(() => !!sr.value && (critical.value || (problem.value.total > 0 && !dismissed.value)))

const title = computed(() => {
    switch (health.value.state) {
        case 'permission_denied': return 'SalesRender: у токена нет нужных прав'
        case 'auth_failed': return 'SalesRender: токен не принят'
        case 'config_error': return 'SalesRender: ошибка настроек интеграции'
        default: return 'Часть изменений не доставлена в SalesRender'
    }
})

const problemText = computed(() => {
    const p = problem.value
    if (p.total === 0) return ''
    const parts = []
    if (p.blocked) parts.push(`нет доступа: ${p.blocked}`)
    if (p.failed) parts.push(`ошибки: ${p.failed}`)
    if (p.conflict) parts.push(`конфликты: ${p.conflict}`)
    return `Заявки с недоставленными изменениями — ${parts.join(', ')}. Изменения сохранены в CRM.`
})
</script>
