<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Европочта — Оформление бланков</h1>
                    <p class="text-sm text-muted mt-0.5">
                        Заявок «Отправить»: <strong>{{ orderQueue.length }}</strong>
                    </p>
                </template>
                <template #actions>
                    <div class="flex items-center gap-2">
                        <label class="label whitespace-nowrap">Кто платит:</label>
                        <select v-model="whoPays" class="text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                            <option value="Покупатель">Покупатель</option>
                            <option value="Продавец">Продавец</option>
                        </select>
                    </div>
                    <button
                        class="btn-primary"
                        :disabled="processing || orderQueue.length === 0 || readOnly"
                        @click="processAll"
                    >
                        {{ processing
                            ? `Обрабатываю ${processingIndex + 1}/${pendingQueue.length}…`
                            : 'Оформить все бланки' }}
                    </button>
                </template>
            </PageHeader>
        </template>

        <PackingChecklist
            :orders="eligibleOrders"
            :screen="'europochta'"
            :print-url="'/europochta/packing.pdf'"
        />

        <!-- Empty state -->
        <div v-if="orderQueue.length === 0" class="card text-center py-16 text-gray-400 dark:text-gray-500">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-sm">Нет заявок со статусом «Отправить» и доставкой «Европочта»</p>
        </div>

        <!-- Orders table -->
        <div v-else class="card">
            <div class="overflow-x-auto -mx-4 px-4 md:mx-0 md:px-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted w-8">#</th>
                            <th class="pb-3 font-medium text-muted">Клиент</th>
                            <th class="pb-3 font-medium text-muted">Адрес отделения</th>
                            <th class="pb-3 font-medium text-muted">Товары</th>
                            <th class="pb-3 font-medium text-muted w-40">Результат</th>
                            <th class="pb-3 w-24"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(order, idx) in orderQueue" :key="order.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="py-3 text-gray-400 dark:text-gray-500 text-xs">{{ idx + 1 }}</td>

                            <td class="py-3">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ order.full_name }}</div>
                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ formatPhone(order.phone) }}</div>
                            </td>

                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400">
                                <div>{{ [order.city, order.street, order.building].filter(Boolean).join(', ') }}</div>
                                <div v-if="order.ops_id" class="text-gray-400 dark:text-gray-500">ОПС №{{ order.ops_id }}</div>
                                <div v-if="!order.europochta_store_id" class="text-amber-600 dark:text-amber-400">
                                    ⚠ Отделение не выбрано: будет подобрано по адресу
                                </div>
                                <div v-if="order.europochta_store_start_label || senderOps.label" class="text-gray-400 dark:text-gray-500">
                                    Отправка: {{ order.europochta_store_start_label || senderOps.label }}
                                </div>
                            </td>

                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400">
                                <span v-for="(good, i) in (order.goods || [])" :key="i" class="block">
                                    {{ goodLabel(order, i) }} × {{ order.quantities?.[i] ?? 1 }}
                                    <span v-if="order.prices?.[i]" class="text-gray-400 dark:text-gray-500">({{ order.prices[i] }} р.)</span>
                                </span>
                            </td>

                            <td class="py-3">
                                <!-- Spinner -->
                                <span v-if="processingIndex >= 0 && pendingQueue[processingIndex]?.id === order.id && processing"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                                    <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    Обработка…
                                </span>

                                <!-- Result -->
                                <template v-else-if="results[order.id]">
                                    <span v-if="results[order.id].success" class="text-xs text-green-600 dark:text-green-400 font-mono font-medium">
                                        ✓ {{ results[order.id].track_number }}
                                    </span>
                                    <span v-else class="text-xs text-red-500 leading-tight">
                                        {{ errorLabel(results[order.id].error) }}
                                        <span v-if="results[order.id].error_message" class="block text-gray-400 dark:text-gray-500 truncate max-w-[160px]" :title="results[order.id].error_message">
                                            {{ results[order.id].error_message }}
                                        </span>
                                    </span>
                                </template>
                            </td>

                            <td class="py-3 text-right">
                                <button
                                    v-if="!results[order.id]?.success"
                                    class="btn-secondary btn-xs"
                                    :disabled="processing"
                                    @click="registerOne(order)"
                                >
                                    {{ results[order.id] ? 'Повторить' : 'Оформить' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Summary bar -->
            <div v-if="hasResults" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex items-center gap-6 text-sm">
                <span class="text-green-600 dark:text-green-400 font-medium">✓ Успешно: {{ successCount }}</span>
                <span v-if="errorCount > 0" class="text-red-500">✕ Ошибок: {{ errorCount }}</span>
            </div>
        </div>

        <!-- Registered orders: labels -->
        <div v-if="epApiVersion !== 'legacy'" class="card mt-4">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div>
                    <h2 class="section-title">Оформлены</h2>
                    <p class="text-xs text-muted mt-0.5">Выберите заявки и скачайте ярлыки. Показаны заявки в статусе «Оформлен».</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="labelFormatValue" class="text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                        <option value="a5">Обычный ярлык (A5)</option>
                        <option value="thermal">Термоярлык 10×10</option>
                    </select>
                    <button
                        class="btn-primary"
                        :disabled="downloadingLabels || selectedIds.length === 0 || readOnly"
                        @click="downloadLabels"
                    >
                        {{ downloadingLabels ? 'Готовлю ярлыки…' : `Скачать ярлыки (${selectedIds.length})` }}
                    </button>
                </div>
            </div>

            <p v-if="labelError" class="text-sm text-red-500 mb-2">{{ labelError }}</p>
            <p v-if="labelNotice" class="text-sm text-amber-600 dark:text-amber-400 mb-2">{{ labelNotice }}</p>

            <div v-if="registeredOrders.length === 0" class="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">
                Нет оформленных заявок
            </div>
            <div v-else class="max-h-[28rem] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-gray-800">
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-2 w-8">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" class="h-4 w-4 rounded border-gray-300" />
                            </th>
                            <th class="pb-2 font-medium text-muted">Клиент</th>
                            <th class="pb-2 font-medium text-muted">Отделение</th>
                            <th class="pb-2 font-medium text-muted">Трек</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="o in registeredOrders" :key="o.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-2">
                                <input type="checkbox" :value="o.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300" />
                            </td>
                            <td class="py-2">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ o.full_name }}</div>
                                <div class="text-xs text-gray-400 dark:text-gray-500">{{ formatPhone(o.phone) }}</div>
                            </td>
                            <td class="py-2 text-xs text-gray-600 dark:text-gray-400">
                                {{ [o.city, o.street, o.building].filter(Boolean).join(', ') }}
                                <span v-if="o.ops_id" class="text-gray-400 dark:text-gray-500"> · ОПС №{{ o.ops_id }}</span>
                            </td>
                            <td class="py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ o.track_number }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import PackingChecklist from '@/Components/PackingChecklist.vue'
import { useSubscription } from '@/composables/useSubscription'
import { Inertia } from '@inertiajs/inertia'
import { apiFetch, apiDownload } from '@/utils/api'
import { goodLabel } from '@/utils/goodLabel'

const { readOnly } = useSubscription()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    eligibleOrders:   { type: Array, default: () => [] },
    registeredOrders: { type: Array, default: () => [] },
    labelFormat:      { type: String, default: 'a5' },
    epApiVersion:     { type: String, default: 'new' },
    senderOps:        { type: Object, default: () => ({ id: null, label: '' }) },
})

// ── State ─────────────────────────────────────────────────────────────────────
const orderQueue      = ref([...props.eligibleOrders])
const processing      = ref(false)
const processingIndex = ref(-1)
const results         = ref({})   // { [orderId]: { success, track_number, error, error_message } }
const whoPays         = ref('Покупатель')

// Labels
const LABELS_CHUNK      = 50
const selectedIds       = ref([])
const labelFormatValue  = ref(props.labelFormat)
const downloadingLabels = ref(false)
const labelError        = ref('')
const labelNotice       = ref('')

// ── Computed ──────────────────────────────────────────────────────────────────
const pendingQueue = computed(() =>
    orderQueue.value.filter(o => !results.value[o.id]?.success)
)

const hasResults = computed(() => Object.keys(results.value).length > 0)

const successCount = computed(() =>
    Object.values(results.value).filter(r => r.success).length
)

const errorCount = computed(() =>
    Object.values(results.value).filter(r => !r.success).length
)

const allSelected = computed(() =>
    props.registeredOrders.length > 0 && selectedIds.value.length === props.registeredOrders.length
)

// ── Methods ───────────────────────────────────────────────────────────────────

function toggleAll(e) {
    selectedIds.value = e.target.checked ? props.registeredOrders.map(o => o.id) : []
}

function refreshRegistered() {
    Inertia.reload({ only: ['registeredOrders'], preserveScroll: true, preserveState: true })
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
}

function parseHeaderJson(value) {
    try {
        return value ? JSON.parse(decodeURIComponent(value)) : []
    } catch {
        return []
    }
}

async function downloadLabels() {
    if (readOnly.value || downloadingLabels.value || selectedIds.value.length === 0) return
    downloadingLabels.value = true
    labelError.value  = ''
    labelNotice.value = ''

    const ids    = [...selectedIds.value]
    const notes  = []
    const failed = []

    try {
        for (let i = 0; i < ids.length; i += LABELS_CHUNK) {
            const chunk = ids.slice(i, i + LABELS_CHUNK)
            const res = await apiDownload(
                '/europochta/labels',
                'POST',
                { order_ids: chunk, format: labelFormatValue.value },
                ['application/pdf', 'application/zip'],
                'europost-labels.pdf'
            )

            if (!res.ok) {
                labelError.value = res.message || 'Не удалось скачать ярлыки'
                continue
            }

            saveBlob(res.blob, res.filename)

            if (res.headers?.['x-label-merge'] === 'failed') {
                notes.push('Ярлыки скачаны отдельными файлами в архиве.')
            }
            failed.push(...parseHeaderJson(res.headers?.['x-label-errors']))
        }
    } finally {
        downloadingLabels.value = false
    }

    if (failed.length) {
        notes.push('Не удалось получить: ' + failed.map(f => `${f.track} (${f.message})`).join('; '))
    }
    labelNotice.value = [...new Set(notes)].join(' ')
    refreshRegistered()
}

async function processAll() {
    if (readOnly.value) return
    if (processing.value) return
    processing.value = true
    processingIndex.value = 0

    const queue = pendingQueue.value

    for (let i = 0; i < queue.length; i++) {
        processingIndex.value = i
        await registerOne(queue[i])
    }

    processing.value      = false
    processingIndex.value = -1
}

async function registerOne(order) {
    if (readOnly.value) return
    try {
        const resp = await apiFetch(`/europochta/orders/${order.id}/register`, 'POST', {
            who_pays: whoPays.value,
        })
        const data = await resp.json()
        results.value = { ...results.value, [order.id]: data }
        if (data?.success) refreshRegistered()
    } catch (e) {
        results.value = {
            ...results.value,
            [order.id]: { success: false, error: 'exception', error_message: e.message },
        }
    }
}

function formatPhone(phone) {
    if (!phone) return ''
    const p = String(phone).replace(/\D/g, '')
    return p.length >= 9 ? '+375 ' + p.slice(-9, -7) + ' ' + p.slice(-7, -4) + '-' + p.slice(-4, -2) + '-' + p.slice(-2) : phone
}

function errorLabel(code) {
    const map = {
        config_error:             '⚠ Не настроены API-ключи',
        auth_error:               '✕ Ошибка авторизации',
        office_not_found:         '⚠ Отделение не найдено',
        weight_category_not_found: '⚠ Категория веса не найдена',
        api_error:                '✕ Ошибка API',
        exception:                '✕ Исключение',
    }
    return map[code] ?? code
}
</script>

<style scoped>
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
