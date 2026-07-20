<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="page-title">Белпочта — Партии</h1>
                <span class="text-sm text-muted">
                    Заявок «Отправить»: <strong>{{ eligibleOrders.length }}</strong>
                </span>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ── Left column: create batch + history ── -->
            <div class="space-y-6">

                <!-- Create batch card -->
                <div class="card">
                    <h2 class="card-title">Создать новую партию</h2>
                    <div class="space-y-3">
                        <div>
                            <label class="label block mb-1">Тип отправления</label>
                            <select v-model="newBatchType" class="w-full" @change="onTypeChange">
                                <option v-for="(label, code) in deliveryTypes" :key="code" :value="code">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="label block mb-1">Кто платит за доставку</label>
                            <select
                                v-model="newBatchWhoPays"
                                class="w-full"
                                :disabled="isSellerOnlyType || !!activeBatch"
                            >
                                <option value="Покупатель">Покупатель</option>
                                <option value="Продавец">Продавец</option>
                            </select>
                            <p v-if="isSellerOnlyType" class="text-xs text-amber-600 mt-1">
                                Для этого типа доставку оплачивает только Продавец.
                            </p>
                            <p v-else-if="activeBatch" class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                Выберите «Создать партию» без активной партии или дождитесь завершения текущей.
                            </p>
                        </div>
                        <button
                            class="btn-primary w-full justify-center"
                            :disabled="creating || !newBatchType"
                            @click="createBatch"
                        >
                            {{ creating ? 'Создаю…' : 'Создать партию на Белпочте' }}
                        </button>
                        <p v-if="createError" class="text-xs text-red-600">{{ createError }}</p>
                    </div>
                </div>

                <!-- Batches history -->
                <div class="card">
                    <h2 class="card-title">История партий</h2>
                    <div v-if="batchList.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic">Нет партий</div>
                    <ul class="space-y-2">
                        <li
                            v-for="b in batchList"
                            :key="b.id"
                            :class="[
                                'border rounded-lg px-3 py-2 cursor-pointer transition-colors text-sm',
                                activeBatch?.id === b.id ? 'border-indigo-400 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-900/40' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600',
                            ]"
                            @click="selectBatch(b)"
                        >
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-gray-700 dark:text-gray-300">{{ b.batch_id }}</span>
                                <span :class="badgeClass(b.status)" class="inline-flex items-center rounded-full font-medium px-2 py-0.5 text-xs">{{ badgeLabel(b.status) }}</span>
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                {{ deliveryTypes[b.type] ?? b.type }} · {{ formatDate(b.created_at) }}
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- ── Right columns: active batch workspace ── -->
            <div class="lg:col-span-2 space-y-6">

                <!-- No batch selected -->
                <div v-if="!activeBatch" class="card text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
                    Выберите партию слева или создайте новую
                </div>

                <template v-else>
                    <!-- Active batch header -->
                    <div class="card">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div>
                                <h2 class="section-title">
                                    Партия <span class="font-mono">{{ activeBatch.batch_id }}</span>
                                </h2>
                                <div class="flex items-center flex-wrap gap-2 mt-1">
                                    <p class="text-sm text-muted">
                                        {{ deliveryTypes[activeBatch.type] ?? activeBatch.type }} ·
                                        {{ formatDate(activeBatch.created_at) }}
                                    </p>
                                    <span
                                        v-if="activeBatch.who_pays"
                                        class="inline-flex items-center rounded-full font-medium px-2 py-0.5 text-xs bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                                    >
                                        Оплата: {{ activeBatch.who_pays }}
                                    </span>
                                </div>
                                <p v-if="activeBatch.id_to_download" class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                    ID для скачивания: <span class="font-mono text-gray-600 dark:text-gray-400">{{ activeBatch.id_to_download }}</span>
                                </p>
                            </div>
                            <span :class="badgeClass(activeBatch.status)" class="inline-flex items-center rounded-full font-medium px-3 py-1 text-sm">{{ badgeLabel(activeBatch.status) }}</span>
                        </div>
                    </div>

                    <!-- Draft: payer is fixed at creation -->
                    <div v-if="activeBatch.status === 'draft'" class="card bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800">
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            Плательщик за доставку зафиксирован при создании партии
                            (<strong>{{ activeBatch.who_pays ?? '—' }}</strong>) и не меняется.
                        </p>
                    </div>

                    <!-- Orders to process (only when draft) -->
                    <div v-if="activeBatch.status === 'draft'" class="card">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="card-title">Заявки для оформления</h2>
                            <button
                                class="btn-primary btn-sm"
                                :disabled="processing || eligibleOrders.length === 0"
                                @click="processAll"
                            >
                                {{ processing ? `Обрабатываю ${processingIndex + 1}/${orderQueue.length}…` : 'Оформить все бланки' }}
                            </button>
                        </div>

                        <div v-if="eligibleOrders.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic py-4 text-center">
                            Нет заявок со статусом «Отправить» и доставкой «Белпочта»
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                        <th class="pb-2 font-medium text-muted w-8">#</th>
                                        <th class="pb-2 font-medium text-muted">Клиент</th>
                                        <th class="pb-2 font-medium text-muted">Адрес</th>
                                        <th class="pb-2 font-medium text-muted w-32">Результат</th>
                                        <th class="pb-2 w-28"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="(order, idx) in orderQueue" :key="order.id">
                                        <td class="py-2 text-gray-400 dark:text-gray-500 text-xs">{{ idx + 1 }}</td>
                                        <td class="py-2">
                                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ order.full_name }}</div>
                                            <div class="text-xs text-gray-400 dark:text-gray-500">{{ order.phone }}</div>
                                        </td>
                                        <td class="py-2 text-xs text-gray-600 dark:text-gray-400">
                                            {{ formatAddress(order) }}
                                        </td>
                                        <td class="py-2">
                                            <!-- Processing spinner -->
                                            <span v-if="processingIndex === idx && processing" class="text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
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
                                                    <span
                                                        v-if="results[order.id].error_message"
                                                        class="block text-gray-400 dark:text-gray-500 truncate max-w-[160px]"
                                                        :title="results[order.id].error_message"
                                                    >
                                                        {{ results[order.id].error_message }}
                                                    </span>
                                                </span>
                                            </template>
                                        </td>
                                        <td class="py-2 text-right">
                                            <!-- Fix address button for unresolved -->
                                            <button
                                                v-if="results[order.id]?.error === 'address_not_found'"
                                                class="btn-secondary btn-xs"
                                                @click="openAddressModal(order)"
                                            >
                                                Исправить адрес
                                            </button>
                                            <!-- Retry for api errors -->
                                            <button
                                                v-else-if="results[order.id] && !results[order.id].success"
                                                class="btn-secondary btn-xs"
                                                @click="retrySingle(order)"
                                            >
                                                Повторить
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Processed forms (any batch status) -->
                    <div class="card">
                        <h2 class="card-title mb-4">Оформленные бланки</h2>

                        <div v-if="activeBatchOrders.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic py-4 text-center">
                            В этой партии пока нет оформленных бланков
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                        <th class="pb-2 font-medium text-muted w-8">#</th>
                                        <th class="pb-2 font-medium text-muted">ФИО / телефон</th>
                                        <th class="pb-2 font-medium text-muted">Адрес</th>
                                        <th class="pb-2 font-medium text-muted">Трек</th>
                                        <th class="pb-2 font-medium text-muted">Дата оформления</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr
                                        v-for="(order, idx) in activeBatchOrders"
                                        :key="order.id"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
                                        @click="goToOrder(order.id)"
                                    >
                                        <td class="py-2 text-gray-400 dark:text-gray-500 text-xs">{{ idx + 1 }}</td>
                                        <td class="py-2">
                                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ order.full_name }}</div>
                                            <div class="text-xs text-gray-400 dark:text-gray-500">{{ order.phone }}</div>
                                        </td>
                                        <td class="py-2 text-xs text-gray-600 dark:text-gray-400">
                                            {{ formatAddress(order) }}
                                        </td>
                                        <td class="py-2 text-xs text-green-600 dark:text-green-400 font-mono font-medium">
                                            {{ order.track_number ?? '—' }}
                                        </td>
                                        <td class="py-2 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                            {{ formatDate(order.status_changed_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Commit section (draft only) -->
                    <div v-if="activeBatch.status === 'draft'" class="card">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="card-title">Зафиксировать партию</h2>
                                <p class="text-sm text-muted mt-1">
                                    После фиксации Белпочта сформирует PDF с бланками.
                                </p>
                            </div>
                            <button
                                class="btn-primary"
                                :disabled="committing"
                                @click="commitBatch"
                            >
                                {{ committing ? 'Фиксирую…' : 'Commit → PDF' }}
                            </button>
                        </div>
                        <p v-if="commitError" class="text-xs text-red-600 mt-2">{{ commitError }}</p>
                    </div>

                    <!-- PDF status section (committed / downloading / ready / failed) -->
                    <div v-if="['committed', 'downloading', 'ready', 'failed'].includes(activeBatch.status)" class="card">
                        <h2 class="card-title mb-4">PDF бланки</h2>

                        <!-- Polling -->
                        <div v-if="['committed', 'downloading'].includes(activeBatch.status)" class="space-y-3">
                            <div class="flex items-center gap-3 text-sm text-muted">
                                <svg class="w-5 h-5 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                <span>
                                    {{ activeBatch.status === 'committed' ? 'Белпочта формирует PDF…' : 'Скачиваю архив…' }}
                                    Обновляю статус каждые 10 с.
                                </span>
                            </div>
                            <div v-if="showRetryButton" class="flex items-center gap-3">
                                <button
                                    class="btn-secondary btn-sm"
                                    :disabled="retrying"
                                    @click="retryDownload"
                                >
                                    {{ retrying ? 'Запускаю…' : 'Повторить скачивание' }}
                                </button>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    Подождите 30–60 сек, если PDF ещё формируется на стороне Белпочты.
                                </p>
                            </div>
                        </div>

                        <!-- Ready -->
                        <div v-else-if="activeBatch.status === 'ready'" class="flex items-center gap-4">
                            <svg class="w-8 h-8 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">PDF готов к скачиванию</p>
                                <a
                                    :href="`/belpost/batches/${activeBatch.id}/pdf`"
                                    class="inline-flex items-center gap-1.5 mt-2 btn-primary btn-sm"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                    Скачать ZIP (бланки)
                                </a>
                            </div>
                        </div>

                        <!-- Failed -->
                        <div v-else-if="activeBatch.status === 'failed'" class="text-sm space-y-3">
                            <div>
                                <p class="text-red-600 font-medium">Ошибка при скачивании PDF</p>
                                <p class="text-muted mt-1 text-xs">{{ activeBatch.error_message }}</p>
                            </div>
                            <div class="flex items-center gap-3 flex-wrap">
                                <button
                                    class="btn-secondary btn-sm"
                                    :disabled="retrying"
                                    @click="retryDownload"
                                >
                                    {{ retrying ? 'Запускаю…' : 'Повторить скачивание' }}
                                </button>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    Подождите 30–60 сек, если PDF ещё формируется на стороне Белпочты.
                                </p>
                            </div>
                            <p v-if="retryError" class="text-xs text-red-600">{{ retryError }}</p>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Address search modal -->
        <AddressSearchModal
            :open="modalOpen"
            :hint="modalHint"
            :initial-query="modalInitialQuery"
            :initial-building="modalInitialBuilding"
            @close="modalOpen = false"
            @selected="onAddressSelected"
        />
    </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AddressSearchModal from '@/Components/AddressSearchModal.vue'
import { Inertia } from '@inertiajs/inertia'

const SELLER_ONLY_TYPES = ['ecommerce_light', 'ecommerce_optima']

// ── Props from controller ──────────────────────────────────────────────────
const props = defineProps({
    batches:         { type: Array,  default: () => [] },
    eligibleOrders:  { type: Array,  default: () => [] },
    deliveryTypes:   { type: Object, default: () => ({}) },
    batchOrders:     { type: Object, default: () => ({}) },
    selectedBatchId: { type: Number, default: null },
})

// ── Reactive state ─────────────────────────────────────────────────────────
const batchList       = ref([...props.batches])
const batchOrdersLocal = ref(normalizeBatchOrders(props.batchOrders))
const newBatchType    = ref(Object.keys(props.deliveryTypes)[0] ?? '')
const newBatchWhoPays = ref('Покупатель')
const creating        = ref(false)
const createError     = ref('')

// ── Computed ───────────────────────────────────────────────────────────────
const isSellerOnlyType = computed(() => SELLER_ONLY_TYPES.includes(newBatchType.value))

const activeBatch   = ref(null)
const pollingSince  = ref(null)
const pollTick      = ref(0)

const showRetryButton = computed(() => {
    void pollTick.value
    if (!activeBatch.value) return false
    if (activeBatch.value.status === 'failed') return true
    if (!['committed', 'downloading'].includes(activeBatch.value.status)) return false
    if (!pollingSince.value) return false
    return Date.now() - pollingSince.value >= 120_000
})

const activeBatchOrders = computed(() => {
    if (!activeBatch.value) return []
    const id = activeBatch.value.id
    return batchOrdersLocal.value[id] ?? batchOrdersLocal.value[String(id)] ?? []
})

// Order processing
const orderQueue    = ref([...props.eligibleOrders])
const processing    = ref(false)
const processingIndex = ref(-1)
const results       = ref({})        // { [orderId]: { success, track_number, error, error_message } }

// Commit
const committing    = ref(false)
const commitError   = ref('')

// PDF retry
const retrying      = ref(false)
const retryError    = ref('')

// Polling
let pollTimer = null

// Address modal
const modalOpen            = ref(false)
const modalHint            = ref('')
const modalInitialQuery    = ref('')
const modalInitialBuilding = ref('')
const pendingOrderForModal = ref(null)

// ── Methods ────────────────────────────────────────────────────────────────

function onTypeChange() {
    if (SELLER_ONLY_TYPES.includes(newBatchType.value)) {
        newBatchWhoPays.value = 'Продавец'
    }
}

function selectBatch(b) {
    activeBatch.value = b
    stopPolling()
    pollingSince.value = null
    retryError.value = ''
    if (['committed', 'downloading'].includes(b.status)) {
        pollingSince.value = Date.now()
        startPolling()
    }
}

// Create batch
async function createBatch() {
    creating.value    = true
    createError.value = ''

    const csrf = getCsrf()

    try {
        const resp = await fetch('/belpost/batches', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body:    JSON.stringify({ type: newBatchType.value, who_pays: newBatchWhoPays.value }),
        })
        const data = await resp.json()

        if (data.success) {
            batchList.value.unshift(data.batch)
            selectBatch(data.batch)
        } else {
            createError.value = data.message ?? 'Ошибка'
        }
    } catch (e) {
        createError.value = e.message
    } finally {
        creating.value = false
    }
}

// Process all eligible orders sequentially
async function processAll() {
    if (processing.value) return
    processing.value  = true
    processingIndex.value = 0

    const queue = orderQueue.value.filter(o => !results.value[o.id]?.success)

    for (let i = 0; i < queue.length; i++) {
        processingIndex.value = i
        const order = queue[i]

        // Skip if already successfully processed
        if (results.value[order.id]?.success) continue

        await processOne(order, null)

        // If address not found stop loop — user must fix manually
        if (results.value[order.id]?.error === 'address_not_found') {
            openAddressModal(order)
            break
        }
    }

    processing.value      = false
    processingIndex.value = -1
}

async function processOne(order, belpostAddressId) {
    const csrf = getCsrf()

    try {
        const resp = await fetch(`/belpost/batches/${activeBatch.value.id}/items`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body:    JSON.stringify({
                order_id:           order.id,
                belpost_address_id: belpostAddressId ?? undefined,
            }),
        })
        const data = await resp.json()
        results.value[order.id] = data

        if (data.success) {
            appendToBatchOrders(order, data.track_number)
            Inertia.reload({ only: ['batchOrders', 'eligibleOrders'], preserveScroll: true })
        }
    } catch (e) {
        results.value[order.id] = { success: false, error: 'exception', error_message: e.message }
    }
}

async function retrySingle(order) {
    await processOne(order, null)
}

// Commit
async function commitBatch() {
    if (committing.value) return
    committing.value = true
    commitError.value = ''

    const csrf = getCsrf()

    try {
        const resp = await fetch(`/belpost/batches/${activeBatch.value.id}/commit`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        })
        const data = await resp.json()

        if (data.success) {
            activeBatch.value.status        = 'committed'
            activeBatch.value.id_to_download = data.id_to_download ?? null
            pollingSince.value = Date.now()
            syncBatchInList(activeBatch.value)
            startPolling()
        } else {
            commitError.value = data.message ?? 'Ошибка'
        }
    } catch (e) {
        commitError.value = e.message
    } finally {
        committing.value = false
    }
}

async function retryDownload() {
    if (retrying.value || !activeBatch.value) return
    retrying.value  = true
    retryError.value = ''

    const csrf = getCsrf()

    try {
        const resp = await fetch(`/belpost/batches/${activeBatch.value.id}/retry-download`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        })
        const data = await resp.json()

        if (data.success) {
            activeBatch.value.status        = 'committed'
            activeBatch.value.error_message = null
            pollingSince.value = Date.now()
            syncBatchInList(activeBatch.value)
            startPolling()
        } else {
            retryError.value = data.message ?? 'Ошибка'
        }
    } catch (e) {
        retryError.value = e.message
    } finally {
        retrying.value = false
    }
}

// Address modal
function openAddressModal(order) {
    pendingOrderForModal.value = order
    modalHint.value            = `Заказ #${order.id} — ${order.full_name}`
    modalInitialQuery.value    = [order.city, order.street].filter(Boolean).join(' ')
    modalInitialBuilding.value = order.building ?? ''
    modalOpen.value            = true
}

async function onAddressSelected({ id, building, city, street }) {
    modalOpen.value = false
    const order = pendingOrderForModal.value
    if (!order) return

    // Persist resolved address + belpost_address_id on the order so
    // BelpostService can reuse it without autoResolve on retry
    const csrf = getCsrf()
    try {
        await fetch(`/orders/${order.id}`, {
            method:  'PUT',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body:    JSON.stringify({
                city,
                street,
                building,
                belpost_address_id: String(id),
            }),
        })
        // Update local order reference so retries have correct data
        order.city     = city
        order.street   = street
        order.building = building
    } catch {
        // Non-fatal: processOne will still pass the id explicitly
    }

    await processOne(order, String(id))
}

// ── Polling ────────────────────────────────────────────────────────────────
function startPolling() {
    stopPolling()
    pollTimer = setInterval(pollStatus, 10_000)
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

async function pollStatus() {
    if (!activeBatch.value) return
    pollTick.value++

    try {
        const resp = await fetch(`/api/belpost/batches/${activeBatch.value.id}/status`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const data = await resp.json()

        activeBatch.value.status         = data.status
        activeBatch.value.error_message  = data.error_message
        if (data.id_to_download) activeBatch.value.id_to_download = data.id_to_download
        if (data.who_pays) activeBatch.value.who_pays = data.who_pays

        syncBatchInList(activeBatch.value)

        if (!['committed', 'downloading'].includes(data.status)) {
            stopPolling()
        }
    } catch {
        // silent — will retry on next tick
    }
}

function syncBatchInList(updated) {
    const idx = batchList.value.findIndex(b => b.id === updated.id)
    if (idx !== -1) {
        batchList.value[idx] = { ...batchList.value[idx], ...updated }
    }
}

watch(() => props.batchOrders, (val) => {
    batchOrdersLocal.value = normalizeBatchOrders(val)
}, { deep: true })

onUnmounted(stopPolling)

onMounted(() => {
    if (props.selectedBatchId) {
        const b = batchList.value.find(b => b.id === props.selectedBatchId)
        if (b) selectBatch(b)
    }
})

// ── Helpers ────────────────────────────────────────────────────────────────
function normalizeBatchOrders(raw) {
    const out = {}
    for (const [key, orders] of Object.entries(raw ?? {})) {
        out[key] = Array.isArray(orders) ? [...orders] : Object.values(orders)
    }
    return out
}

function appendToBatchOrders(order, trackNumber) {
    if (!activeBatch.value) return
    const batchId = activeBatch.value.id
    const key = batchOrdersLocal.value[batchId] ? batchId : String(batchId)
    const entry = {
        id: order.id,
        mail_batch_id: batchId,
        full_name: order.full_name,
        phone: order.phone,
        city: order.city,
        street: order.street,
        building: order.building,
        track_number: trackNumber,
        status_changed_at: new Date().toISOString(),
    }
    const existing = batchOrdersLocal.value[key] ?? []
    if (existing.some(o => o.id === order.id)) return
    batchOrdersLocal.value = {
        ...batchOrdersLocal.value,
        [key]: [...existing, entry],
    }
}

function goToOrder(id) {
    Inertia.visit(`/orders/${id}`)
}
function formatDate(value) {
    if (!value) return '—'
    const d = new Date(value)
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
}

function formatAddress(order) {
    const parts = [order.city, order.street, order.building].filter(Boolean)
    return parts.join(', ') || '—'
}

function errorLabel(code) {
    const map = {
        address_not_found: '⚠ Адрес не найден',
        address_mismatch:  '⚠ Адрес не совпал',
        api_error:         '✕ Ошибка API',
        invalid_response:  '✕ Неверный ответ',
        exception:         '✕ Исключение',
    }
    return map[code] ?? code
}

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

function badgeClass(status) {
    return {
        'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300':     status === 'draft',
        'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200': status === 'committed',
        'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200':     status === 'downloading',
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200':   status === 'ready',
        'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200':       status === 'failed',
    }
}

function badgeLabel(status) {
    const labels = {
        draft:       'Черновик',
        committed:   'Ожидание PDF',
        downloading: 'Загрузка',
        ready:       'PDF готов',
        failed:      'Ошибка',
    }
    return labels[status] ?? status
}
</script>

<style scoped>
.card-title {
    @apply section-title mb-3;
}
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
