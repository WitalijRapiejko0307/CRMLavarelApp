<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Клиенты</h1>
                </template>
            </PageHeader>
        </template>

        <div class="card mb-4">
            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                <input
                    v-model="search"
                    type="search"
                    class="w-full sm:max-w-sm"
                    placeholder="Поиск по ФИО или телефону"
                    @input="onSearch"
                />
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input v-model="onlyProblem" type="checkbox" @change="apply" />
                    Только с возвратами / отказами / спамом
                </label>
            </div>
        </div>

        <div class="card p-0 overflow-hidden">
            <p v-if="customers.data.length === 0" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">
                Клиентов пока нет. Они появляются автоматически из заявок.
            </p>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50 text-left text-xs text-muted">
                        <tr>
                            <th class="px-4 py-2 font-medium">Клиент</th>
                            <th class="px-4 py-2 font-medium">Телефон</th>
                            <th class="px-4 py-2 font-medium hidden md:table-cell">Город</th>
                            <th class="px-4 py-2 font-medium hidden md:table-cell">Доставка</th>
                            <th class="px-4 py-2 font-medium text-right">Заказов</th>
                            <th class="px-4 py-2 font-medium hidden sm:table-cell">Последний</th>
                            <th class="px-4 py-2 font-medium">Риски</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr
                            v-for="c in customers.data"
                            :key="c.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
                            @click="open(c)"
                        >
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-gray-100">
                                {{ c.full_name || '—' }}
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ formatPhone(c.phone) || c.phone || '—' }}</td>
                            <td class="px-4 py-2 hidden md:table-cell">{{ c.city || '—' }}</td>
                            <td class="px-4 py-2 hidden md:table-cell">{{ deliveryTypes[c.delivery_type] ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ c.orders_count }}</td>
                            <td class="px-4 py-2 hidden sm:table-cell whitespace-nowrap text-muted">{{ formatDate(c.last_order_at) }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-if="c.blacklisted"
                                        class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900 dark:text-red-200"
                                    >чёрный список</span>
                                    <span
                                        v-for="(count, status) in c.problems"
                                        :key="status"
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="statusColorClass(status)"
                                    >{{ status }} · {{ count }}</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="customers.data.length"
                class="flex items-center justify-between px-4 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50"
            >
                <div class="text-xs text-muted">
                    Показано {{ customers.from }}–{{ customers.to }} из {{ customers.total }}
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
                        @click="link.url && Inertia.visit(link.url, { preserveState: true })"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import { statusColorClass } from '@/utils/orderStatusColors'
import { formatPhone } from '@/utils/phone'

const props = defineProps({
    customers:     Object,
    filters:       { type: Object, default: () => ({ q: '', problem: '0' }) },
    deliveryTypes: { type: Object, default: () => ({}) },
})

const search = ref(props.filters.q ?? '')
const onlyProblem = ref(props.filters.problem === '1')

let timer = null
function onSearch() {
    clearTimeout(timer)
    timer = setTimeout(apply, 350)
}

function apply() {
    Inertia.get('/customers', {
        q: search.value || undefined,
        problem: onlyProblem.value ? '1' : undefined,
    }, { preserveState: true, replace: true })
}

function open(c) {
    Inertia.visit(`/customers/${c.id}`)
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString('ru-RU') : '—'
}

const paginationLinks = computed(() =>
    (props.customers.links ?? []).map(link => ({
        ...link,
        label: link.label.replace('&laquo; Previous', '←').replace('Next &raquo;', '→'),
    }))
)
</script>
