<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Аналитика</h1>
                </template>
            </PageHeader>
        </template>

        <!-- Tabs -->
        <div class="flex gap-2 mb-4">
            <button
                type="button"
                class="px-4 py-2 text-sm rounded-md border transition-colors"
                :class="activeTab === 'managers'
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                @click="switchTab('managers')"
            >
                Менеджеры
            </button>
            <button
                type="button"
                class="px-4 py-2 text-sm rounded-md border transition-colors"
                :class="activeTab === 'stores'
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'"
                @click="switchTab('stores')"
            >
                Магазины
            </button>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="label mb-1">Дата от</label>
                    <DateInput v-model="localFilters.date_from" @change="applyFilters" />
                </div>
                <div>
                    <label class="label mb-1">Дата до</label>
                    <DateInput v-model="localFilters.date_to" @change="applyFilters" />
                </div>
                <div v-if="canFilterTeam && activeTab === 'managers'">
                    <label class="label mb-1">Менеджер</label>
                    <AppScrollSelect
                        v-model="localFilters.user_id"
                        :options="teamOptions"
                        placeholder="Все менеджеры"
                        :empty-option="{ value: '', label: 'Все менеджеры' }"
                        @change="applyFilters"
                    />
                </div>
                <div v-if="canFilterTeam && activeTab === 'stores'">
                    <label class="label mb-1">Магазин</label>
                    <AppScrollSelect
                        v-model="localFilters.store_id"
                        :options="storeOptions"
                        placeholder="Все магазины"
                        :empty-option="{ value: '', label: 'Все магазины' }"
                        @change="applyFilters"
                    />
                </div>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div v-for="card in summaryCards" :key="card.key" class="card py-4">
                <p class="stat-label">{{ card.label }}</p>
                <p class="stat-value mt-1">{{ card.value }}</p>
            </div>
        </div>

        <!-- Table -->
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                        <th
                            v-for="col in tableColumns"
                            :key="col.key"
                            class="pb-3 font-medium text-muted whitespace-nowrap"
                            :class="col.align === 'right' ? 'text-right' : ''"
                        >
                            {{ col.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="rows.length === 0">
                        <td :colspan="tableColumns.length" class="py-8 text-center text-gray-400 dark:text-gray-500">
                            Нет данных за выбранный период
                        </td>
                    </tr>
                    <tr v-for="(row, idx) in rows" :key="idx" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td
                            v-for="col in tableColumns"
                            :key="col.key"
                            class="py-3"
                            :class="col.align === 'right' ? 'text-right text-gray-700 dark:text-gray-300' : 'text-gray-800 dark:text-gray-200'"
                        >
                            {{ formatCell(row, col.key) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import DateInput from '@/Components/DateInput.vue'
import AppScrollSelect from '@/Components/AppScrollSelect.vue'
import { formatDateDMY, isValidDateDMY, parseDateDMY } from '@/utils/date'

const props = defineProps({
    summary:         { type: Object, default: () => ({}) },
    rows:            { type: Array, default: () => [] },
    filters:         { type: Object, default: () => ({}) },
    canFilterTeam:   { type: Boolean, default: false },
    connectedStores: { type: Array, default: () => [] },
    teamMembers:     { type: Array, default: () => [] },
})

const activeTab = computed(() => props.filters?.tab ?? 'managers')

function toDisplayDate(value) {
    return formatDateDMY(value) || value || ''
}

const localFilters = ref({
    date_from: toDisplayDate(props.filters?.date_from),
    date_to:   toDisplayDate(props.filters?.date_to),
    user_id:   props.filters?.user_id ? String(props.filters.user_id) : '',
    store_id:  props.filters?.store_id ? String(props.filters.store_id) : '',
})

const teamOptions = computed(() =>
    props.teamMembers.map(m => ({ value: String(m.id), label: m.name }))
)

const storeOptions = computed(() =>
    props.connectedStores.map(s => ({ value: String(s.id), label: s.name }))
)

const managerColumns = [
    { key: 'name', label: 'Менеджер' },
    { key: 'touches', label: 'Касаний', align: 'right' },
    { key: 'confirmed', label: 'Подтверждений', align: 'right' },
    { key: 'refusals', label: 'Отказов', align: 'right' },
    { key: 'spam', label: 'Спам', align: 'right' },
    { key: 'no_answer', label: 'Недозвонов', align: 'right' },
    { key: 'unique_orders', label: 'Заказов', align: 'right' },
]

const storeColumns = [
    { key: 'name', label: 'Магазин' },
    { key: 'leads', label: 'Лидов', align: 'right' },
    { key: 'confirmed', label: 'Подтверждено', align: 'right' },
    { key: 'refusals', label: 'Отказов', align: 'right' },
    { key: 'spam', label: 'Спам', align: 'right' },
    { key: 'no_answer', label: 'Недозвонов', align: 'right' },
    { key: 'conversion', label: 'Конверсия %', align: 'right' },
]

const tableColumns = computed(() =>
    activeTab.value === 'stores' ? storeColumns : managerColumns
)

const summaryCards = computed(() => {
    if (activeTab.value === 'stores') {
        return [
            { key: 'leads', label: 'Лидов', value: props.summary.leads ?? 0 },
            { key: 'confirmed', label: 'Подтверждено', value: props.summary.confirmed ?? 0 },
            { key: 'refusals', label: 'Отказов', value: props.summary.refusals ?? 0 },
            { key: 'spam', label: 'Спам', value: props.summary.spam ?? 0 },
            { key: 'no_answer', label: 'Недозвонов', value: props.summary.no_answer ?? 0 },
            { key: 'conversion', label: 'Конверсия', value: `${props.summary.conversion ?? 0}%` },
        ]
    }

    return [
        { key: 'touches', label: 'Касаний', value: props.summary.touches ?? 0 },
        { key: 'confirmed', label: 'Подтверждений', value: props.summary.confirmed ?? 0 },
        { key: 'refusals', label: 'Отказов', value: props.summary.refusals ?? 0 },
        { key: 'spam', label: 'Спам', value: props.summary.spam ?? 0 },
        { key: 'no_answer', label: 'Недозвонов', value: props.summary.no_answer ?? 0 },
        { key: 'unique_orders', label: 'Заказов', value: props.summary.unique_orders ?? 0 },
    ]
})

function formatCell(row, key) {
    if (key === 'conversion') {
        return `${row.conversion ?? 0}%`
    }
    return row[key] ?? '—'
}

function buildQuery(tab = activeTab.value) {
    const query = {
        tab,
        date_from: isValidDateDMY(localFilters.value.date_from) ? parseDateDMY(localFilters.value.date_from) : '',
        date_to:   isValidDateDMY(localFilters.value.date_to)   ? parseDateDMY(localFilters.value.date_to)   : '',
    }

    if (props.canFilterTeam) {
        if (tab === 'managers' && localFilters.value.user_id) {
            query.user_id = localFilters.value.user_id
        }
        if (tab === 'stores' && localFilters.value.store_id) {
            query.store_id = localFilters.value.store_id
        }
    }

    return query
}

function applyFilters() {
    Inertia.get('/analytics', buildQuery(), { preserveState: true, replace: true })
}

function switchTab(tab) {
    Inertia.get('/analytics', buildQuery(tab), { preserveState: true, replace: true })
}
</script>
