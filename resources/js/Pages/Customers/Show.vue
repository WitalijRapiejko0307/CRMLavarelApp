<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <div class="flex items-center gap-3">
                        <button class="btn-secondary btn-sm shrink-0" @click="Inertia.get('/customers')">← Клиенты</button>
                        <h1 class="page-title">{{ customer.full_name || 'Клиент без ФИО' }}</h1>
                    </div>
                </template>
            </PageHeader>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="card space-y-3">
                <h2 class="section-title">Профиль</h2>
                <dl class="text-sm space-y-2">
                    <div>
                        <dt class="label">Телефон</dt>
                        <dd>{{ formatPhone(customer.phone) || customer.phone || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="label">Адрес</dt>
                        <dd>{{ addressLine || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="label">Доставка</dt>
                        <dd>{{ deliveryTypes[customer.delivery_type] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="label">Заказов</dt>
                        <dd>{{ customer.orders_count }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-muted">
                    Профиль обновляется по последней успешной заявке; отказы, спам и возвраты в него не попадают.
                </p>
            </div>

            <div class="card lg:col-span-2">
                <h2 class="section-title mb-4">История заявок</h2>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                    <li
                        v-for="o in orders"
                        :key="o.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                        :class="o.problem ? 'bg-red-50/60 dark:bg-red-900/10 -mx-2 px-2 rounded' : ''"
                    >
                        <div class="min-w-0">
                            <a :href="`/orders/${o.id}`" class="text-indigo-600 hover:underline dark:text-indigo-400">
                                #{{ o.id }}
                            </a>
                            <span class="ml-2 text-gray-800 dark:text-gray-200">{{ o.goods.join(', ') || '—' }}</span>
                            <span v-if="o.blacklisted" class="ml-2 text-xs text-red-600">чёрный список</span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-muted shrink-0">
                            <span>{{ o.total }} р.</span>
                            <OrderStatusBadge :status="o.status" />
                            <span>{{ formatDate(o.created_at) }}</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import OrderStatusBadge from '@/Components/OrderStatusBadge.vue'
import { formatPhone } from '@/utils/phone'

const props = defineProps({
    customer:      Object,
    orders:        { type: Array, default: () => [] },
    deliveryTypes: { type: Object, default: () => ({}) },
})

const addressLine = computed(() => {
    const c = props.customer
    if (c.poste_restante) return [c.city, 'До востребования'].filter(Boolean).join(', ')
    return [c.city, c.street, c.building, c.housing ? 'корп. ' + c.housing : '', c.apartment ? 'кв. ' + c.apartment : '']
        .filter(Boolean).join(', ')
})

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString('ru-RU') : '—'
}
</script>
