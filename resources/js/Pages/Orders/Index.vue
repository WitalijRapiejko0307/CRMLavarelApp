<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="page-title">Заказы</h1>
                <div class="flex items-center gap-3 flex-wrap justify-end">
                    <span v-if="trackingLabel" class="text-sm text-indigo-600 dark:text-indigo-400 whitespace-nowrap">
                        {{ trackingLabel }}
                    </span>
                    <button
                        type="button"
                        class="btn-secondary text-sm"
                        :disabled="trackingRunning"
                        @click="startRefreshTracking"
                    >
                        {{ trackingRunning ? 'Обновление…' : 'Обновить статусы' }}
                    </button>
                    <span class="text-sm text-muted">Всего: {{ orders.total }}</span>
                    <Link href="/orders/import" class="btn-secondary text-sm">
                        Импорт CSV
                    </Link>
                    <Link href="/orders/create" class="btn-primary text-sm">
                        + Новый заказ
                    </Link>
                </div>
            </div>
        </template>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
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
                    <select v-model="filters.status" class="w-full" @change="applyFilters">
                        <option value="">Все статусы</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div>
                    <label class="label mb-1">Дата от</label>
                    <input v-model="filters.date_from" type="date" class="w-full" @change="applyFilters" />
                </div>
                <div>
                    <label class="label mb-1">Дата до</label>
                    <input v-model="filters.date_to" type="date" class="w-full" @change="applyFilters" />
                </div>
            </div>
            <div v-if="hasActiveFilters" class="mt-3 flex justify-end">
                <button class="btn-secondary btn-sm" @click="resetFilters">Сбросить фильтры</button>
            </div>
        </div>

        <!-- Table -->
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
                                Заказы не найдены
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
    </AppLayout>
</template>

<script setup>
import { ref, computed, h, onMounted, onUnmounted } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { Link } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrderStatusBadge from '@/Components/OrderStatusBadge.vue'
import { apiFetch } from '@/utils/api'
import {
    useVueTable,
    createColumnHelper,
    getCoreRowModel,
    FlexRender,
} from '@tanstack/vue-table'

const props = defineProps({
    orders:        Object,
    filters:       Object,
    statuses:      Array,
    deliveryTypes: Object,
})

// --- Tracking refresh ---
const trackingStatus = ref({
    status:      'idle',
    checked:     0,
    total:       0,
    errors:      0,
    source:      null,
    finished_at: null,
})
const trackingRunning = computed(() => trackingStatus.value.status === 'running')
const trackingLabel   = computed(() => {
    const { status, checked, total } = trackingStatus.value
    if (status === 'running') {
        return `Проверка: ${checked} из ${total}…`
    }
    if (status === 'done' && total > 0) {
        return `Проверено ${checked} из ${total}`
    }
    return null
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

async function startRefreshTracking() {
    if (trackingRunning.value) return

    try {
        const resp = await apiFetch('/orders/refresh-tracking', 'POST')
        const data = await resp.json()

        if (resp.status === 202) {
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
    pollTrackingStatus().then(() => {
        if (trackingStatus.value.status === 'running') {
            startPolling()
        }
    })
})

onUnmounted(stopPolling)

// --- Filters ---
const filters = ref({
    search:    props.filters?.search    ?? '',
    status:    props.filters?.status    ?? '',
    date_from: props.filters?.date_from ?? '',
    date_to:   props.filters?.date_to   ?? '',
})

const hasActiveFilters = computed(() =>
    Object.values(filters.value).some(v => v !== '')
)

let filterTimer = null
function applyFilters() {
    clearTimeout(filterTimer)
    filterTimer = setTimeout(() => {
        Inertia.get('/orders', filters.value, {
            preserveState: true,
            replace: true,
        })
    }, 350)
}

function resetFilters() {
    filters.value = { search: '', status: '', date_from: '', date_to: '' }
    applyFilters()
}

// --- Navigation ---
function goToOrder(id) {
    Inertia.get(`/orders/${id}`)
}

function goToPage(url) {
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

const columns = [
    columnHelper.accessor('id', {
        header: '#',
        cell:   info => h('span', { class: 'text-gray-400 dark:text-gray-500 font-mono text-xs' }, '#' + info.getValue()),
    }),
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
        cell:   info => h(OrderStatusBadge, { status: info.getValue() }),
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
        cell:   info => h('span', { class: 'text-gray-600 dark:text-gray-400 whitespace-nowrap' }, info.getValue() ?? '—'),
    }),
    columnHelper.accessor('city', {
        header: 'Город',
        cell:   info => h('span', { class: 'text-gray-600 dark:text-gray-400' }, info.getValue() ?? '—'),
    }),
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
]

const table = useVueTable({
    get data() { return props.orders.data },
    columns,
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
