<template>
    <div class="rounded-md border border-gray-200 dark:border-gray-700 p-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="label mb-0">Состояние подключения</p>
                <p class="text-sm font-medium" :class="statusClass">{{ statusText }}</p>
                <p v-if="health.state === 'ok' && checkedAt" class="text-xs text-muted">
                    Проверено: {{ formatDate(checkedAt) }}
                </p>
                <p v-if="health.state !== 'ok'" class="text-xs text-muted mt-0.5">{{ health.message }}</p>
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2">
                <button type="button" class="btn-secondary btn-sm" :disabled="checking" @click="check">
                    {{ checking ? 'Проверяем…' : 'Проверить подключение' }}
                </button>
                <button
                    v-if="requestText"
                    type="button"
                    class="btn-secondary btn-sm"
                    @click="modalOpen = true"
                >
                    Запросить права у колл-центра
                </button>
            </div>
        </div>

        <p class="text-xs text-muted">
            Сначала сохраните токен и Company ID, затем нажмите «Проверить подключение». Проверка ничего не меняет в
            SalesRender. Отправка изменений заказов включается только после успешной проверки.
        </p>

        <ul v-if="checks.length" class="space-y-1 text-sm">
            <li v-for="c in checks" :key="c.key" class="flex gap-2">
                <span :class="c.status === 'ok' ? 'text-green-600' : c.status === 'warn' ? 'text-amber-600' : 'text-red-600'">
                    {{ c.status === 'ok' ? '✓' : c.status === 'warn' ? '!' : '✗' }}
                </span>
                <span><span class="font-medium">{{ c.label }}:</span> {{ c.message }}</span>
            </li>
        </ul>

        <div v-if="undelivered > 0" class="flex flex-wrap items-center gap-2 text-sm">
            <span>Недоставленных изменений: <strong>{{ undelivered }}</strong></span>
            <button
                v-if="canEdit && health.state === 'ok'"
                type="button"
                class="btn-secondary btn-sm"
                :disabled="retrying"
                @click="retryBlocked"
            >
                Отправить повторно
            </button>
            <Link href="/orders?sr=problem" class="text-sm underline">Показать заявки</Link>
        </div>
        <p v-if="notice" class="text-sm text-green-700 dark:text-green-300">{{ notice }}</p>

        <SrRequestRightsModal :open="modalOpen" :text="requestText || ''" @close="modalOpen = false" />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'
import SrRequestRightsModal from '@/Components/SrRequestRightsModal.vue'
import { apiFetch } from '@/utils/api'

const props = defineProps({
    panel:   { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
})

const health = ref({ ...props.panel.health })
const checkedAt = ref(props.panel.checked_at)
const requestText = ref(props.panel.request_text)
const undelivered = ref(props.panel.undelivered ?? 0)
const checks = ref([])
const checking = ref(false)
const retrying = ref(false)
const modalOpen = ref(false)
const notice = ref('')

const statusText = computed(() => {
    switch (health.value.state) {
        case 'ok': return checkedAt.value ? 'Подключено, права подтверждены' : 'Не проверено'
        case 'permission_denied': return 'Нет прав у токена'
        case 'auth_failed': return 'Токен не принят'
        case 'config_error': return 'Ошибка настроек'
        default: return ''
    }
})

const statusClass = computed(() => {
    if (health.value.state !== 'ok') return 'text-red-600 dark:text-red-400'
    return checkedAt.value ? 'text-green-600 dark:text-green-400' : 'text-gray-500'
})

function formatDate(iso) {
    const d = new Date(iso)
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('ru-RU')
}

async function check() {
    checking.value = true
    notice.value = ''
    try {
        const resp = await apiFetch('/settings/salesrender/check', 'POST')
        const data = await resp.json().catch(() => ({}))
        if (!resp.ok) {
            checks.value = [{ key: 'http', label: 'Проверка', status: 'fail', message: data.message || 'Не удалось выполнить проверку.' }]
            return
        }
        checks.value = data.checks ?? []
        health.value = data.health ?? health.value
        requestText.value = data.request_text ?? null
        checkedAt.value = data.ok ? new Date().toISOString() : null
    } finally {
        checking.value = false
    }
}

async function retryBlocked() {
    retrying.value = true
    try {
        const resp = await apiFetch('/settings/salesrender/retry-blocked', 'POST')
        const data = await resp.json().catch(() => ({}))
        if (resp.ok) {
            notice.value = `Поставлено в очередь отправки: ${data.queued ?? 0}. Данные сверяются с SalesRender перед отправкой.`
            undelivered.value = 0
        }
    } finally {
        retrying.value = false
    }
}
</script>
