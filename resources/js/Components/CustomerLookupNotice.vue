<template>
    <div v-if="hasContent" class="space-y-3">
        <!-- Problem history: spam / refusals / returns -->
        <div
            v-if="info && (info.problems.length || info.blacklisted)"
            class="rounded-lg border px-4 py-3 text-sm"
            :class="severityClass"
        >
            <div class="font-semibold mb-1">
                <template v-if="info.blacklisted">Клиент в чёрном списке. </template>
                <template v-if="info.problems.length">{{ problemSummary }}</template>
            </div>
            <ul v-if="info.problems.length" class="space-y-1">
                <li
                    v-for="row in info.problems"
                    :key="row.id"
                    class="flex items-center justify-between gap-3"
                >
                    <a :href="`/orders/${row.id}`" class="hover:underline truncate">
                        #{{ row.id }} {{ row.full_name }}
                    </a>
                    <span class="flex items-center gap-2 text-xs shrink-0">
                        <OrderStatusBadge :status="row.status" />
                        <span>{{ formatDate(row.created_at) }}</span>
                    </span>
                </li>
            </ul>
        </div>

        <!-- Customer found in the base -->
        <div
            v-if="info && info.found && info.has_profile"
            class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-100"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <span class="font-semibold">Клиент найден в базе</span>
                    <span v-if="info.customer.orders_count"> · заказов: {{ info.customer.orders_count }}</span>
                    <div v-if="info.customer.full_name" class="mt-0.5">{{ info.customer.full_name }}</div>
                    <div v-if="addressLine" class="text-xs opacity-80">{{ addressLine }}</div>
                    <div v-if="info.customer.delivery_type" class="text-xs opacity-80">
                        Доставка: {{ deliveryTypes[info.customer.delivery_type] ?? info.customer.delivery_type }}
                    </div>
                    <div v-if="info.last_order && info.last_order.goods.length" class="text-xs opacity-80 mt-1">
                        Раньше заказывал (в заявку не переносится): {{ info.last_order.goods.join(', ') }}
                        <a :href="`/orders/${info.last_order.id}`" class="underline ml-1">#{{ info.last_order.id }}</a>
                    </div>
                </div>
                <button
                    v-if="applied.length === 0 && canApply"
                    type="button"
                    class="btn-secondary btn-sm"
                    @click="$emit('apply')"
                >
                    Подставить данные
                </button>
                <span v-else-if="applied.length" class="text-xs font-medium text-emerald-700 dark:text-emerald-300">
                    ✓ Данные подставлены
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import OrderStatusBadge from '@/Components/OrderStatusBadge.vue'

const props = defineProps({
    info:          { type: Object, default: null },
    deliveryTypes: { type: Object, default: () => ({}) },
    applied:       { type: Array, default: () => [] },
    canApply:      { type: Boolean, default: true },
})

defineEmits(['apply'])

const hasContent = computed(() => {
    const i = props.info
    return !!i && ((i.found && i.has_profile) || i.problems.length || i.blacklisted)
})

const hasReturn = computed(() => props.info?.problems.some(p => p.status.startsWith('Возврат')))
const hasRefusal = computed(() => props.info?.problems.some(p => p.status.startsWith('Отказ')))

const severityClass = computed(() => {
    if (props.info?.blacklisted || hasReturn.value) {
        return 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100'
    }
    if (hasRefusal.value) {
        return 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100'
    }
    return 'border-stone-300 bg-stone-50 text-stone-800 dark:border-stone-700 dark:bg-stone-800/50 dark:text-stone-100'
})

const problemSummary = computed(() => {
    const counts = {}
    props.info.problems.forEach(p => {
        const label = p.status.startsWith('Возврат') ? 'возвратов'
            : p.status.startsWith('Отказ') ? 'отказов'
            : 'спам'
        counts[label] = (counts[label] || 0) + 1
    })
    return 'В истории по этому телефону: ' + Object.entries(counts).map(([k, v]) => `${v} ${k}`).join(', ') + '.'
})

const addressLine = computed(() => {
    const c = props.info?.customer
    if (!c) return ''
    if (c.poste_restante) return [c.city, 'До востребования'].filter(Boolean).join(', ')
    return [c.city, c.street, c.building, c.housing ? 'корп. ' + c.housing : '', c.apartment ? 'кв. ' + c.apartment : '']
        .filter(Boolean).join(', ')
})

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString('ru-RU') : ''
}
</script>
