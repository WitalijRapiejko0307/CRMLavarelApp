<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Отчёты</h1>
                </template>
            </PageHeader>
        </template>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="label mb-1">Дата от</label>
                    <DateInput v-model="localFilters.date_from" @change="applyFilters" />
                </div>
                <div>
                    <label class="label mb-1">Дата до</label>
                    <DateInput v-model="localFilters.date_to" @change="applyFilters" />
                </div>
                <div v-if="showUtmFilter">
                    <label class="label mb-1">Кампания UTM</label>
                    <AppScrollSelect
                        v-model="localFilters.utm_campaign"
                        :options="campaignOptions"
                        placeholder="Все кампании"
                        :empty-option="{ value: '', label: 'Все кампании' }"
                        @change="applyFilters"
                    />
                </div>
            </div>
        </div>

        <!-- Funnel -->
        <div class="card mb-6">
            <h2 class="section-title mb-4">Воронка</h2>
            <p v-if="isEmptyFunnel" class="text-sm text-gray-400 dark:text-gray-500 mb-4">
                Нет заявок за выбранный период
            </p>
            <div class="space-y-4">
                <div v-for="step in funnel" :key="step.key">
                    <div class="flex items-baseline justify-between gap-3 mb-1.5">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ step.label }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">
                            {{ step.count ?? 0 }}
                            <span class="text-muted">{{ formatRate(step.rate) }}</span>
                        </span>
                    </div>
                    <div class="h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-indigo-600 rounded-full"
                            :style="{ width: barWidth(step) }"
                        />
                    </div>
                </div>
            </div>
            <p v-if="!isEmptyFunnel && leftoverCount > 0" class="text-xs text-muted mt-4">
                В других статусах: {{ leftoverCount }}
            </p>
        </div>

        <!-- Live slice -->
        <h2 class="section-title mb-4">Сейчас</h2>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Link
                v-for="item in live"
                :key="item.key"
                :href="item.href"
                class="card py-4 block hover:border-indigo-300 dark:hover:border-indigo-500 hover:shadow-md transition-shadow"
            >
                <p class="stat-label">{{ item.label }}</p>
                <p class="stat-value mt-1">{{ item.count ?? 0 }}</p>
            </Link>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { Link } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import DateInput from '@/Components/DateInput.vue'
import AppScrollSelect from '@/Components/AppScrollSelect.vue'
import { formatDateDMY, isValidDateDMY, parseDateDMY } from '@/utils/date'

const props = defineProps({
    funnel:         { type: Array, default: () => [] },
    live:           { type: Array, default: () => [] },
    filters:        { type: Object, default: () => ({}) },
    utm_campaigns:  { type: Array, default: () => [] },
})

function toDisplayDate(value) {
    return formatDateDMY(value) || value || ''
}

const localFilters = ref({
    date_from:     toDisplayDate(props.filters?.date_from),
    date_to:       toDisplayDate(props.filters?.date_to),
    utm_campaign:  props.filters?.utm_campaign ? String(props.filters.utm_campaign) : '',
})

const campaignOptions = computed(() =>
    props.utm_campaigns.map(c => ({ value: String(c), label: String(c) }))
)

const showUtmFilter = computed(() =>
    props.utm_campaigns.length > 0 || Boolean(props.filters?.utm_campaign)
)

const isEmptyFunnel = computed(() => {
    const leads = props.funnel.find(step => step.key === 'leads')
    return (leads?.count ?? 0) === 0
})

const leftoverCount = computed(() => {
    const leads = props.funnel.find(step => step.key === 'leads')?.count ?? 0
    const pipeline = props.funnel
        .filter(step => step.key !== 'leads')
        .reduce((sum, step) => sum + (Number(step.count) || 0), 0)

    return Math.max(0, leads - pipeline)
})

function formatRate(rate) {
    const n = Number(rate)
    if (!Number.isFinite(n)) return '0%'
    return `${n}%`
}

function barWidth(step) {
    const leads = props.funnel.find(row => row.key === 'leads')?.count ?? 0
    if (leads <= 0) return '0%'
    if (step?.key === 'leads') return '100%'
    const n = Math.min(100, Math.max(0, (Number(step.count) || 0) / leads * 100))
    return `${n}%`
}

function buildQuery() {
    const query = {
        date_from: isValidDateDMY(localFilters.value.date_from) ? parseDateDMY(localFilters.value.date_from) : '',
        date_to:   isValidDateDMY(localFilters.value.date_to)   ? parseDateDMY(localFilters.value.date_to)   : '',
    }

    if (localFilters.value.utm_campaign) {
        query.utm_campaign = localFilters.value.utm_campaign
    }

    return query
}

function applyFilters() {
    Inertia.get('/reports', buildQuery(), { preserveState: true, replace: true })
}
</script>
