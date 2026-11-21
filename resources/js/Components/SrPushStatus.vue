<template>
    <div v-if="info && info.state" class="card mb-4 border-l-4" :class="borderClass">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-medium" :class="textClass">{{ headline }}</p>
                <p v-if="info.error && showError" class="text-sm text-muted mt-0.5">{{ info.error }}</p>
                <p v-if="info.state === 'synced' && info.synced_at" class="text-xs text-muted mt-0.5">
                    {{ formatDate(info.synced_at) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-if="canRetry"
                    type="button"
                    class="btn-secondary btn-sm"
                    :disabled="busy"
                    @click="retry"
                >
                    Повторить отправку
                </button>
                <button
                    v-if="info.state === 'blocked' && isAdmin && requestText"
                    type="button"
                    class="btn-secondary btn-sm"
                    @click="modalOpen = true"
                >
                    Запросить права у колл-центра
                </button>
            </div>
        </div>

        <!-- Undelivered edits -->
        <ul v-if="info.pending.length && info.state !== 'conflict'" class="mt-3 space-y-1 text-sm">
            <li v-for="p in info.pending" :key="p.field">
                <span class="font-medium">{{ p.label }}:</span>
                <span class="text-muted line-through ml-1">{{ p.from }}</span>
                <span class="ml-1">→ {{ p.to }}</span>
            </li>
        </ul>

        <!-- Conflict: the call center changed the same data -->
        <div v-if="info.state === 'conflict'" class="mt-3 space-y-3">
            <p class="text-sm">
                Эти данные изменили и вы, и колл-центр. Автоматически ничего не перезаписано — выберите, что оставить.
            </p>
            <div v-for="p in conflicts" :key="p.field" class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                <div class="rounded border border-gray-200 dark:border-gray-700 p-2">
                    <p class="text-xs text-muted">{{ p.label }} — в CRM (ваше)</p>
                    <p class="break-words">{{ p.to }}</p>
                </div>
                <div class="rounded border border-gray-200 dark:border-gray-700 p-2">
                    <p class="text-xs text-muted">{{ p.label }} — в SalesRender</p>
                    <p class="break-words">{{ p.sr }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn-primary btn-sm" :disabled="busy" @click="resolve('keep_local')">
                    Оставить моё и отправить
                </button>
                <button type="button" class="btn-secondary btn-sm" :disabled="busy" @click="resolve('accept_sr')">
                    Принять из SalesRender
                </button>
            </div>
        </div>

        <p v-if="message" class="text-sm text-red-600 dark:text-red-400 mt-2">{{ message }}</p>

        <details v-if="info.log.length" class="mt-3 text-xs text-muted">
            <summary class="cursor-pointer">Последние попытки отправки</summary>
            <ul class="mt-1 space-y-0.5">
                <li v-for="(l, i) in info.log" :key="i">
                    {{ formatDate(l.created_at) }} — {{ logLabel(l.status) }}<span v-if="l.message">: {{ l.message }}</span>
                </li>
            </ul>
        </details>

        <SrRequestRightsModal :open="modalOpen" :text="requestText || ''" @close="modalOpen = false" />
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { usePage } from '@inertiajs/inertia-vue3'
import SrRequestRightsModal from '@/Components/SrRequestRightsModal.vue'
import { apiFetch } from '@/utils/api'

const props = defineProps({
    orderId: { type: Number, required: true },
    info:    { type: Object, default: null },
})

const page = usePage()
const busy = ref(false)
const message = ref('')
const modalOpen = ref(false)
let timer = null

const isAdmin = computed(() => ['admin', 'super_admin'].includes(page.props.value.auth?.user?.role))
const requestText = computed(() => page.props.value.sr_integration?.request_text ?? null)
const conflicts = computed(() => (props.info?.pending ?? []).filter(p => p.conflict))
const canRetry = computed(() => ['failed', 'blocked', 'retrying'].includes(props.info?.state))
const showError = computed(() => ['failed', 'blocked', 'retrying'].includes(props.info?.state))

const headline = computed(() => {
    switch (props.info?.state) {
        case 'synced': return '✓ Данные заявки переданы в SalesRender'
        case 'pending': return '⏳ Изменения отправляются в SalesRender…'
        case 'retrying': return `⟳ SalesRender недоступен, повторяем отправку (попытка ${props.info.attempts})`
        case 'failed': return '⚠ Изменения не отправлены в SalesRender'
        case 'blocked': return '🔒 Изменения не отправлены: нет доступа к SalesRender'
        case 'conflict': return '⚡ Конфликт с SalesRender'
        default: return ''
    }
})

const borderClass = computed(() => ({
    synced: 'border-green-500',
    pending: 'border-blue-400',
    retrying: 'border-amber-400',
    failed: 'border-red-500',
    blocked: 'border-red-500',
    conflict: 'border-amber-500',
}[props.info?.state] ?? 'border-gray-300'))

const textClass = computed(() => ['failed', 'blocked'].includes(props.info?.state)
    ? 'text-red-700 dark:text-red-300'
    : '')

function formatDate(iso) {
    if (!iso) return ''
    const d = new Date(iso)
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('ru-RU')
}

function logLabel(status) {
    return { success: 'отправлено', failed: 'ошибка', skipped: 'без изменений', conflict: 'конфликт' }[status] ?? status
}

function reload() {
    Inertia.reload({ only: ['order', 'srPush'], preserveScroll: true })
}

async function act(url, body) {
    busy.value = true
    message.value = ''
    try {
        const resp = await apiFetch(url, 'POST', body)
        const data = await resp.json().catch(() => ({}))
        if (!resp.ok) {
            message.value = data.message || 'Не удалось выполнить действие.'
            return
        }
        reload()
    } finally {
        busy.value = false
    }
}

const retry = () => act(`/api/orders/${props.orderId}/sr-push/retry`)
const resolve = choice => act(`/api/orders/${props.orderId}/sr-push/resolve`, { choice })

// While a push is in flight the result must appear without a manual refresh.
onMounted(() => {
    timer = setInterval(() => {
        if (['pending', 'retrying'].includes(props.info?.state)) reload()
    }, 6000)
})
onUnmounted(() => clearInterval(timer))
</script>
