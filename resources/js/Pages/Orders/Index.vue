<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Заказы</h1>
                </template>
                <template #actions>
                    <span v-if="feedUpdated" class="text-xs text-green-600 dark:text-green-400 whitespace-nowrap">
                        Обновлено
                    </span>
                    <span v-if="!isCallCenter && trackingLabel" class="text-sm whitespace-nowrap" :class="trackingLabelClass">
                        {{ trackingLabel }}
                    </span>
                    <button
                        v-if="!isCallCenter"
                        type="button"
                        class="btn-secondary text-sm"
                        :class="{ 'text-red-500': isManualRunning && !cancellingTracking }"
                        :disabled="trackingButtonDisabled"
                        @click="onTrackingButtonClick"
                    >
                        {{ trackingButtonLabel }}
                    </button>
                    <span class="text-sm text-muted whitespace-nowrap">Всего: {{ orders.total }}</span>
                    <Link v-if="!readOnly && !isCallCenter" href="/orders/import" class="btn-secondary text-sm">
                        Импорт CSV
                    </Link>
                    <Link v-if="!readOnly && !isCallCenter" href="/orders/create" class="btn-primary text-sm">
                        + Новый заказ
                    </Link>
                </template>
            </PageHeader>
        </template>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-if="isCallCenter && connectedStores.length">
                    <label class="label mb-1">Магазин</label>
                    <AppScrollSelect
                        v-model="filters.store_id"
                        :options="storeFilterOptions"
                        placeholder="Все магазины"
                        :empty-option="{ value: '', label: 'Все магазины' }"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <label class="label mb-1">Поиск</label>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Имя, телефон, ID…"
                        class="w-full"
                        @input="applyFilters"
                    />
                </div>
                <div>
                    <label class="label mb-1">Статус</label>
                    <AppScrollSelect
                        v-model="filters.status"
                        :options="statuses"
                        placeholder="Все статусы"
                        :empty-option="{ value: '', label: 'Все статусы' }"
                        @change="applyFilters"
                    />
                </div>
                <div>
                    <label class="label mb-1">Дата от</label>
                    <DateInput v-model="filters.date_from" @change="applyFilters" />
                </div>
                <div>
                    <label class="label mb-1">Дата до</label>
                    <DateInput v-model="filters.date_to" @change="applyFilters" />
                </div>
            </div>
            <div v-if="hasActiveFilters" class="mt-3 flex justify-end">
                <button class="btn-secondary btn-sm" @click="resetFilters">Сбросить фильтры</button>
            </div>
        </div>

        <!-- Bulk result message -->
        <div
            v-if="bulkResultMessage"
            class="mb-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 rounded-md px-4 py-3 text-sm"
        >
            {{ bulkResultMessage }}
        </div>

        <!-- Bulk status panel -->
        <div
            v-if="selectedCount > 0"
            class="card mb-4 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4"
        >
            <span class="text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                Выбрано: {{ selectedCount }}
            </span>
            <AppScrollSelect
                v-model="bulkStatus"
                :options="statuses"
                placeholder="Новый статус"
                class="sm:max-w-xs w-full"
                :disabled="readOnly || bulkApplying"
            />
            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <button
                    type="button"
                    class="btn-primary btn-sm"
                    :disabled="!bulkStatus || readOnly || bulkApplying"
                    @click="onBulkApplyClick"
                >
                    {{ bulkApplying ? 'Применяю…' : 'Применить' }}
                </button>
                <button
                    type="button"
                    class="btn-secondary btn-sm"
                    :disabled="bulkApplying"
                    @click="clearSelection"
                >
                    Снять выбор
                </button>
            </div>
        </div>

        <ResponsiveList>
            <!-- Desktop table -->
            <template #table>
                <div class="card p-0 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id"
                                    class="table-head">
                                    <th
                                        v-for="header in headerGroup.headers"
                                        :key="header.id"
                                        class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap"
                                    >
                                        <FlexRender
                                            v-if="!header.isPlaceholder"
                                            :render="header.column.columnDef.header"
                                            :props="header.getContext()"
                                        />
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="table-divide">
                                <tr v-if="orders.data.length === 0">
                                    <td :colspan="columns.length" class="px-4 py-12 text-center text-gray-400 dark:text-gray-500 text-sm">
                                        <div class="space-y-2">
                                            <template v-if="isEmptyUnfiltered && isCallCenter">
                                                <p>Подключите магазин — передайте код из Настроек</p>
                                                <Link href="/settings" class="btn-secondary btn-sm">
                                                    Открыть настройки
                                                </Link>
                                            </template>
                                            <template v-else-if="isEmptyUnfiltered">
                                                <p>Пока нет заказов</p>
                                                <Link
                                                    v-if="!readOnly"
                                                    href="/orders/create"
                                                    class="btn-primary btn-sm"
                                                >
                                                    + Новый заказ
                                                </Link>
                                                <p v-if="onboardingVisible" class="text-xs text-muted">
                                                    Дальше — по чеклисту «Первые шаги» в шапке.
                                                </p>
                                            </template>
                                            <template v-else>
                                                Заказы не найдены
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in table.getRowModel().rows"
                                    :key="row.id"
                                    class="table-row-hover cursor-pointer transition-colors"
                                    @click="goToOrder(row.original.id)"
                                >
                                    <td
                                        v-for="cell in row.getVisibleCells()"
                                        :key="cell.id"
                                        class="px-4 py-3"
                                    >
                                        <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <div class="text-xs text-muted">
                            Показано {{ orders.from }}–{{ orders.to }} из {{ orders.total }}
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                v-for="link in paginationLinks"
                                :key="link.label"
                                :disabled="!link.url"
                                class="px-3 py-1.5 text-xs rounded border transition-colors"
                                    :class="link.active
                                    ? 'bg-indigo-600 text-white border-indigo-600'
                                    : link.url
                                        ? 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
                                        : 'bg-white dark:bg-gray-800 text-gray-300 dark:text-gray-600 border-gray-200 dark:border-gray-700 cursor-not-allowed'"
                                @click="link.url && goToPage(link.url)"
                                v-html="link.label"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- Mobile cards -->
            <template #cards>
                <div v-if="orders.data.length === 0" class="card text-center py-12 text-gray-400 dark:text-gray-500 text-sm space-y-2">
                    <template v-if="isEmptyUnfiltered && isCallCenter">
                        <p>Подключите магазин — передайте код из Настроек</p>
                        <Link href="/settings" class="btn-secondary btn-sm">
                            Открыть настройки
                        </Link>
                    </template>
                    <template v-else-if="isEmptyUnfiltered">
                        <p>Пока нет заказов</p>
                        <Link
                            v-if="!readOnly"
                            href="/orders/create"
                            class="btn-primary btn-sm"
                        >
                            + Новый заказ
                        </Link>
                        <p v-if="onboardingVisible" class="text-xs text-muted">
                            Дальше — по чеклисту «Первые шаги» в шапке.
                        </p>
                    </template>
                    <template v-else>
                        Заказы не найдены
                    </template>
                </div>

                <ListCard
                    v-for="order in orders.data"
                    :key="order.id"
                    clickable
                    @click="goToOrder(order.id)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-start gap-2 min-w-0">
                            <input
                                type="checkbox"
                                class="mt-1 shrink-0"
                                :checked="isSelected(order.id)"
                                :disabled="readOnly"
                                @click.stop
                                @change="toggleSelect(order.id)"
                            />
                            <span class="text-gray-400 dark:text-gray-500 font-mono text-xs pt-1">#{{ order.id }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <OrderStatusSelect
                                :order-id="order.id"
                                :status="order.status"
                                :statuses="statuses"
                                :disabled="readOnly"
                            />
                            <button
                                v-if="canDeleteOrder(order)"
                                type="button"
                                class="touch-target text-red-500 hover:text-red-700 p-1 -mr-1 shrink-0"
                                title="Удалить заказ"
                                @click.stop="openDeleteModal(order)"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ order.full_name }}</p>

                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400 flex flex-wrap items-center gap-x-1">
                        <a v-if="order.phone" :href="`tel:${order.phone}`" class="text-indigo-600 dark:text-indigo-400" @click.stop>
                            {{ order.phone }}
                        </a>
                        <Link
                            v-if="order.is_phone_duplicate && order.duplicate_of_order_id"
                            :href="`/orders/${order.duplicate_of_order_id}`"
                            class="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded-full"
                            @click.stop
                        >
                            Дубль
                        </Link>
                        <span
                            v-else-if="order.is_phone_duplicate"
                            class="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded-full"
                        >
                            Дубль
                        </span>
                        <span v-if="order.city"> · {{ order.city }}</span>
                    </p>

                    <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300 truncate">
                        Товары: {{ formatGoods(order.goods, order.quantities) }}
                    </p>

                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 flex flex-wrap items-center gap-x-1">
                        <span>{{ formatDate(order.created_at) }}</span>
                        <span v-if="order.delivery_type">· {{ deliveryTypes[order.delivery_type] ?? order.delivery_type }}</span>
                        <span v-if="order.track_number" class="font-mono text-indigo-600 dark:text-indigo-400">· {{ order.track_number }}</span>
                    </p>
                </ListCard>

                <!-- Compact pagination -->
                <div v-if="orders.last_page > 1" class="flex items-center justify-between pt-1">
                    <button
                        class="btn-secondary btn-sm touch-target"
                        :disabled="!orders.prev_page_url"
                        @click="orders.prev_page_url && goToPage(orders.prev_page_url)"
                    >
                        ← Пред.
                    </button>
                    <span class="text-xs text-muted">стр. {{ orders.current_page }} из {{ orders.last_page }}</span>
                    <button
                        class="btn-secondary btn-sm touch-target"
                        :disabled="!orders.next_page_url"
                        @click="orders.next_page_url && goToPage(orders.next_page_url)"
                    >
                        След. →
                    </button>
                </div>
            </template>
        </ResponsiveList>

        <DeleteOrderModal
            :open="deleteModalOpen"
            :order="orderToDelete"
            :deleting="deletingOrder"
            @cancel="closeDeleteModal"
            @confirm="confirmDeleteOrder"
        />

        <BulkStatusConfirmModal
            :open="confirmModalOpen"
            :count="selectedCount"
            :status="bulkStatus"
            :warning="bulkStatusWarningText"
            :applying="bulkApplying"
            @cancel="confirmModalOpen = false"
            @confirm="applyBulkStatus"
        />
    </AppLayout>
</template>

<script setup>
import { ref, computed, h, onMounted, onUnmounted, watch } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { Link, usePage } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import ResponsiveList from '@/Components/ResponsiveList.vue'
import ListCard from '@/Components/ListCard.vue'
import AppScrollSelect from '@/Components/AppScrollSelect.vue'
import DateInput from '@/Components/DateInput.vue'
import OrderStatusSelect from '@/Components/OrderStatusSelect.vue'
import DeleteOrderModal from '@/Components/DeleteOrderModal.vue'
import BulkStatusConfirmModal from '@/Components/BulkStatusConfirmModal.vue'
import { useSubscription } from '@/composables/useSubscription'
import { useOnboarding } from '@/composables/useOnboarding'
import { useOrderFeed } from '@/composables/useOrderFeed'
import { apiFetch } from '@/utils/api'
import { bulkStatusWarning } from '@/utils/bulkStatusWarnings'
import { formatDateDMY, isValidDateDMY, parseDateDMY } from '@/utils/date'
import {
    useVueTable,
    createColumnHelper,
    getCoreRowModel,
    FlexRender,
} from '@tanstack/vue-table'

const props = defineProps({
    orders:              Object,
    filters:             Object,
    statuses:            Array,
    bulkConfirmStatuses: { type: Array, default: () => [] },
    deliveryTypes:       Object,
    isCallCenter:        { type: Boolean, default: false },
    connectedStores:     { type: Array, default: () => [] },
    orderHandlers:       { type: Object, default: () => ({}) },
})

const { readOnly } = useSubscription()
const { visible: onboardingVisible } = useOnboarding()
const page = usePage()

const { feedUpdated } = useOrderFeed()

const isAdmin = computed(() => page.props.value.auth?.user?.role === 'admin')
const blockedStatuses = computed(() => page.props.value.order_delete?.blocked_statuses ?? [])

function canDeleteOrder(order) {
    return isAdmin.value && !readOnly.value && !blockedStatuses.value.includes(order.status)
}

// --- Order delete ---
const deleteModalOpen = ref(false)
const orderToDelete   = ref(null)
const deletingOrder   = ref(false)

function openDeleteModal(order) {
    orderToDelete.value = order
    deleteModalOpen.value = true
}

function closeDeleteModal() {
    deleteModalOpen.value = false
    orderToDelete.value = null
}

async function confirmDeleteOrder() {
    if (!orderToDelete.value || deletingOrder.value) return

    deletingOrder.value = true
    try {
        const resp = await apiFetch(`/orders/${orderToDelete.value.id}`, 'DELETE')
        if (resp.ok) {
            closeDeleteModal()
            Inertia.reload({ only: ['orders'] })
        }
    } finally {
        deletingOrder.value = false
    }
}

// --- Bulk status ---
const selectedIds       = ref(new Set())
const bulkStatus        = ref('')
const bulkApplying      = ref(false)
const confirmModalOpen  = ref(false)
const bulkResultMessage = ref('')

const selectedCount = computed(() => selectedIds.value.size)

const pageOrderIds = computed(() => props.orders.data.map(o => o.id))

const allPageSelected = computed(() =>
    pageOrderIds.value.length > 0
    && pageOrderIds.value.every(id => selectedIds.value.has(id))
)

const somePageSelected = computed(() =>
    pageOrderIds.value.some(id => selectedIds.value.has(id))
)

const bulkStatusWarningText = computed(() =>
    bulkStatusWarning(bulkStatus.value, props.bulkConfirmStatuses)
)

function isSelected(id) {
    return selectedIds.value.has(id)
}

function toggleSelect(id) {
    const next = new Set(selectedIds.value)
    if (next.has(id)) {
        next.delete(id)
    } else {
        next.add(id)
    }
    selectedIds.value = next
    bulkResultMessage.value = ''
}

function toggleSelectAllOnPage() {
    const next = new Set(selectedIds.value)
    if (allPageSelected.value) {
        pageOrderIds.value.forEach(id => next.delete(id))
    } else {
        pageOrderIds.value.forEach(id => next.add(id))
    }
    selectedIds.value = next
    bulkResultMessage.value = ''
}

function clearSelection() {
    selectedIds.value = new Set()
    bulkStatus.value = ''
    bulkResultMessage.value = ''
}

function onBulkApplyClick() {
    if (!bulkStatus.value || selectedCount.value === 0 || readOnly.value) return

    if (props.bulkConfirmStatuses.includes(bulkStatus.value)) {
        confirmModalOpen.value = true
        return
    }

    applyBulkStatus()
}

async function applyBulkStatus() {
    if (!bulkStatus.value || selectedCount.value === 0 || bulkApplying.value) return

    bulkApplying.value = true
    bulkResultMessage.value = ''

    try {
        const resp = await apiFetch('/orders/bulk-status', 'PATCH', {
            order_ids: [...selectedIds.value],
            status:    bulkStatus.value,
        })
        const data = await resp.json()

        if (!resp.ok) {
            bulkResultMessage.value = 'Не удалось обновить статус.'
            return
        }

        const total = selectedCount.value
        const updated = data.updated ?? 0
        const failed = data.failed?.length ?? 0

        confirmModalOpen.value = false
        selectedIds.value = new Set()
        bulkStatus.value = ''

        bulkResultMessage.value = failed > 0
            ? `Обновлено ${updated} из ${total}.`
            : `Обновлено ${updated} заказов.`

        Inertia.reload({ only: ['orders'], preserveScroll: true })
    } finally {
        bulkApplying.value = false
    }
}

watch(() => props.orders.current_page, () => {
    clearSelection()
})

// --- Order delete ---
const cancellingTracking = ref(false)
const trackingStatus = ref({
    status:      'idle',
    checked:     0,
    total:       0,
    errors:      0,
    source:      null,
    finished_at: null,
})
const trackingRunning = computed(() => trackingStatus.value.status === 'running')
const isManualRunning = computed(() =>
    trackingStatus.value.status === 'running' && trackingStatus.value.source === 'manual'
)
const trackingButtonDisabled = computed(() =>
    readOnly.value
    || cancellingTracking.value
    || (trackingRunning.value && !isManualRunning.value)
)
const trackingButtonLabel = computed(() => {
    if (cancellingTracking.value) return 'Останавливаем…'
    if (isManualRunning.value) return 'Остановить'
    if (trackingRunning.value) return 'Обновление…'
    return 'Обновить статусы'
})
const trackingLabel   = computed(() => {
    const { status, checked, total } = trackingStatus.value
    if (cancellingTracking.value && total > 0) {
        return `Останавливаем: ${checked} из ${total}…`
    }
    if (status === 'running') {
        return `Проверка: ${checked} из ${total}…`
    }
    if (status === 'cancelled' && total > 0) {
        return `Остановлено: ${checked} из ${total}`
    }
    if (status === 'done' && total > 0) {
        return `Проверено ${checked} из ${total}`
    }
    return null
})
const trackingLabelClass = computed(() => {
    if (cancellingTracking.value) {
        return 'text-amber-600 dark:text-amber-400'
    }
    return 'text-indigo-600 dark:text-indigo-400'
})

let pollTimer = null

function startPolling() {
    stopPolling()
    pollTimer = setInterval(pollTrackingStatus, 2000)
    pollTrackingStatus()
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

async function pollTrackingStatus() {
    try {
        const resp = await fetch('/api/orders/tracking-status', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const data = await resp.json()
        const prevStatus = trackingStatus.value.status
        trackingStatus.value = data

        if (data.status === 'cancelled' || data.status === 'done' || data.status === 'failed') {
            cancellingTracking.value = false
        }

        if (data.status !== 'running') {
            stopPolling()
            if (prevStatus === 'running' && data.status === 'done') {
                Inertia.reload({ only: ['orders'] })
            }
        }
    } catch {
        // silent — will retry on next tick
    }
}

function onTrackingButtonClick() {
    if (isManualRunning.value) {
        cancelTracking()
    } else {
        startRefreshTracking()
    }
}

async function cancelTracking() {
    if (readOnly.value || !isManualRunning.value || cancellingTracking.value) return

    try {
        const resp = await apiFetch('/orders/cancel-tracking', 'POST')
        if (resp.status === 204) {
            cancellingTracking.value = true
            startPolling()
        }
    } catch {
        // silent
    }
}

async function startRefreshTracking() {
    if (trackingRunning.value || readOnly.value) return

    try {
        const resp = await apiFetch('/orders/refresh-tracking', 'POST')
        const data = await resp.json()

        if (resp.status === 202) {
            cancellingTracking.value = false
            trackingStatus.value = {
                status:      'running',
                checked:     0,
                total:       data.total ?? 0,
                errors:      0,
                source:      'manual',
                finished_at: null,
            }
            startPolling()
        } else if (resp.status === 409 && data.progress) {
            trackingStatus.value = data.progress
            if (data.progress.status === 'running') {
                startPolling()
            }
        }
    } catch {
        // silent
    }
}

onMounted(() => {
    if (props.isCallCenter) return
    pollTrackingStatus().then(() => {
        if (trackingStatus.value.status === 'running') {
            startPolling()
        }
    })
})

onUnmounted(stopPolling)

// --- Filters ---
function toDisplayDate(value) {
    return formatDateDMY(value) || value || ''
}

const filters = ref({
    search:    props.filters?.search    ?? '',
    status:    props.filters?.status    ?? '',
    date_from: toDisplayDate(props.filters?.date_from),
    date_to:   toDisplayDate(props.filters?.date_to),
    store_id:  props.filters?.store_id  ?? '',
})

const storeFilterOptions = computed(() =>
    props.connectedStores.map(s => ({ value: String(s.id), label: s.name }))
)

const hasActiveFilters = computed(() =>
    Object.values(filters.value).some(v => v !== '')
)

const isEmptyUnfiltered = computed(() =>
    props.orders.data.length === 0 && !hasActiveFilters.value
)

let filterTimer = null
function buildFilterQuery() {
    const query = {
        search: filters.value.search,
        status: filters.value.status,
        date_from: isValidDateDMY(filters.value.date_from) ? parseDateDMY(filters.value.date_from) : '',
        date_to:   isValidDateDMY(filters.value.date_to)   ? parseDateDMY(filters.value.date_to)   : '',
    }
    if (props.isCallCenter && filters.value.store_id) {
        query.store_id = filters.value.store_id
    }
    return query
}

function applyFilters() {
    clearTimeout(filterTimer)
    filterTimer = setTimeout(() => {
        Inertia.get('/orders', buildFilterQuery(), {
            preserveState: true,
            replace: true,
        })
    }, 350)
}

function resetFilters() {
    filters.value = { search: '', status: '', date_from: '', date_to: '', store_id: '' }
    applyFilters()
}

// --- Navigation ---
function goToOrder(id) {
    Inertia.get(`/orders/${id}`)
}

function goToPage(url) {
    clearSelection()
    Inertia.get(url, {}, { preserveState: true })
}

// --- Helpers ---
function formatDate(value) {
    if (!value) return '—'
    const d = new Date(value)
    return d.toLocaleDateString('ru-RU', {
        day: '2-digit', month: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit',
    })
}

function formatGoods(goods, quantities) {
    if (!goods || goods.length === 0) return '—'
    if (goods.length === 1) return goods[0]
    return `${goods[0]} +${goods.length - 1}`
}

// --- TanStack Table ---
const columnHelper = createColumnHelper()

const columns = computed(() => {
    void selectedIds.value.size

    const cols = [
        columnHelper.display({
            id: 'select',
            header: () => h('input', {
                type: 'checkbox',
                checked: allPageSelected.value,
                disabled: readOnly.value,
                ref: (el) => {
                    if (el) {
                        el.indeterminate = somePageSelected.value && !allPageSelected.value
                    }
                },
                onClick: (e) => e.stopPropagation(),
                onChange: toggleSelectAllOnPage,
            }),
            cell: info => h('input', {
                type: 'checkbox',
                checked: selectedIds.value.has(info.row.original.id),
                disabled: readOnly.value,
                onClick: (e) => e.stopPropagation(),
                onChange: () => toggleSelect(info.row.original.id),
            }),
        }),
        columnHelper.accessor('id', {
            header: '#',
            cell:   info => h('span', { class: 'text-gray-400 dark:text-gray-500 font-mono text-xs' }, '#' + info.getValue()),
        }),
    ]

    if (props.isCallCenter) {
        cols.push(columnHelper.display({
            id: 'store',
            header: 'Магазин',
            cell: info => h('span', { class: 'text-gray-600 dark:text-gray-400 text-xs' },
                info.row.original.tenant?.name ?? '—'
            ),
        }))
        cols.push(columnHelper.display({
            id: 'handlers',
            header: 'Обработали',
            cell: info => {
                const handlers = props.orderHandlers[info.row.original.id] ?? []
                if (!handlers.length) {
                    return h('span', { class: 'text-gray-400 dark:text-gray-500 text-xs' }, '—')
                }
                const label = handlers.map(hnd => hnd.name).join(', ')
                const title = handlers
                    .map(hnd => `${hnd.name}: ${hnd.last_status} (${formatDate(hnd.last_at)})`)
                    .join('\n')
                return h('span', {
                    class: 'text-gray-600 dark:text-gray-400 text-xs truncate max-w-[10rem] block',
                    title,
                }, label)
            },
        }))
    }

    cols.push(
        columnHelper.accessor('created_at', {
            header: 'Дата',
            cell:   info => h('span', { class: 'whitespace-nowrap text-gray-600 dark:text-gray-400' }, formatDate(info.getValue())),
        }),
        columnHelper.accessor('full_name', {
            header: 'ФИО',
            cell:   info => h('span', { class: 'font-medium text-gray-900 dark:text-gray-100' }, info.getValue()),
        }),
        columnHelper.accessor('status', {
            header: 'Статус',
            cell:   info => {
                const row = info.row.original
                return h(OrderStatusSelect, {
                    orderId:  row.id,
                    status:   row.status,
                    statuses: props.statuses,
                    disabled: readOnly.value,
                })
            },
        }),
        columnHelper.display({
            id: 'goods',
            header: 'Товары',
            cell: info => {
                const row = info.row.original
                return h('span', { class: 'text-gray-700 dark:text-gray-300 truncate max-w-xs block' },
                    formatGoods(row.goods, row.quantities)
                )
            },
        }),
        columnHelper.accessor('phone', {
            header: 'Телефон',
            cell:   info => {
                const row = info.row.original
                const phone = info.getValue()
                const children = []

                if (phone) {
                    children.push(h('span', { class: 'text-gray-600 dark:text-gray-400 whitespace-nowrap' }, phone))
                } else {
                    children.push(h('span', { class: 'text-gray-400 dark:text-gray-500' }, '—'))
                }

                if (row.is_phone_duplicate) {
                    const badgeClass = 'inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 rounded-full ml-1'
                    if (row.duplicate_of_order_id) {
                        children.push(h(Link, {
                            href: `/orders/${row.duplicate_of_order_id}`,
                            class: badgeClass + ' hover:underline',
                            onClick: (e) => e.stopPropagation(),
                        }, () => 'Дубль'))
                    } else {
                        children.push(h('span', { class: badgeClass }, 'Дубль'))
                    }
                }

                return h('div', { class: 'flex items-center flex-wrap gap-1' }, children)
            },
        }),
        columnHelper.accessor('city', {
            header: 'Город',
            cell:   info => h('span', { class: 'text-gray-600 dark:text-gray-400' }, info.getValue() ?? '—'),
        }),
    )

    if (!props.isCallCenter) {
        cols.push(
            columnHelper.accessor('track_number', {
                header: 'Трек',
                cell:   info => h('span', {
                    class: info.getValue() ? 'text-indigo-600 dark:text-indigo-400 font-mono text-xs' : 'text-gray-400 dark:text-gray-500',
                }, info.getValue() ?? '—'),
            }),
            columnHelper.accessor('delivery_type', {
                header: 'Доставка',
                cell:   info => h('span', { class: 'text-gray-600 dark:text-gray-400 text-xs' },
                    props.deliveryTypes[info.getValue()] ?? '—'
                ),
            }),
            columnHelper.display({
                id: 'batch',
                header: 'Партия',
                cell: info => {
                    const row = info.row.original
                    const batch = row.mail_batch
                    if (!batch?.batch_id) return h('span', { class: 'text-gray-400 dark:text-gray-500' }, '—')
                    return h(Link, {
                        href: `/belpost?batch=${batch.id}`,
                        class: 'text-indigo-600 dark:text-indigo-400 font-mono text-xs hover:underline',
                        onClick: (e) => e.stopPropagation(),
                    }, () => batch.batch_id)
                },
            }),
        )
    }

    cols.push(columnHelper.display({
        id: 'actions',
        header: '',
        cell: info => {
            const row = info.row.original
            if (!canDeleteOrder(row)) return null

            return h('button', {
                type: 'button',
                class: 'text-red-500 hover:text-red-700 p-1',
                title: 'Удалить заказ',
                onClick: (e) => {
                    e.stopPropagation()
                    openDeleteModal(row)
                },
            }, [
                h('svg', {
                    class: 'w-4 h-4',
                    fill: 'none',
                    stroke: 'currentColor',
                    viewBox: '0 0 24 24',
                }, [
                    h('path', {
                        'stroke-linecap': 'round',
                        'stroke-linejoin': 'round',
                        'stroke-width': '2',
                        d: 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
                    }),
                ]),
            ])
        },
    }))

    return cols
})

const table = useVueTable({
    get data() { return props.orders.data },
    get columns() { return columns.value },
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    pageCount: props.orders.last_page ?? 1,
})

// Pagination links (filter prev/next text to use arrows)
const paginationLinks = computed(() => {
    return (props.orders.links ?? []).map(link => ({
        ...link,
        label: link.label
            .replace('&laquo; Previous', '←')
            .replace('Next &raquo;', '→'),
    }))
})
</script>
