<template>
    <div class="card mb-6">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="section-title mb-0">
                {{ rows.length > 0 ? `${rows.length} посылок · что упаковать` : 'Нет посылок для сборки' }}
            </h2>
            <a :href="printUrl" class="btn-secondary btn-sm shrink-0">Печать</a>
        </div>

        <div v-if="rows.length === 0" class="text-sm text-muted py-4 text-center">
            Нет посылок для сборки
        </div>

        <div v-else>
            <div class="overflow-x-auto -mx-4 px-4 md:mx-0 md:px-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted w-16">№</th>
                            <th class="pb-3 font-medium text-muted">ФИО</th>
                            <th class="pb-3 font-medium text-muted">Товары</th>
                            <th class="pb-3 font-medium text-muted text-right w-28">Сумма</th>
                            <th class="pb-3 font-medium text-muted w-28"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                        >
                            <td class="py-3 text-gray-400 dark:text-gray-500 text-xs font-mono">{{ row.id }}</td>
                            <td class="py-3 font-medium text-gray-800 dark:text-gray-200">{{ row.full_name }}</td>
                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400">{{ row.items_label }}</td>
                            <td class="py-3 text-right whitespace-nowrap">{{ formatByn(row.sum) }}</td>
                            <td class="py-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap cursor-pointer">
                                    <input
                                        type="checkbox"
                                        :checked="!!packed[row.id]"
                                        @change="onToggle(row.id, $event)"
                                    >
                                    собрано
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex items-center justify-end text-sm font-medium text-gray-800 dark:text-gray-200">
                Итого: {{ formatByn(total) }}
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, watch } from 'vue'
import { usePage } from '@inertiajs/inertia-vue3'

const props = defineProps({
    orders:   { type: Array, default: () => [] },
    screen:   { type: String, required: true },
    printUrl: { type: String, required: true },
})

const page = usePage()
const tenantId = computed(() => page.props.value.tenant?.id ?? 0)
const packed = reactive({})

const rows = computed(() => (props.orders || []).map(orderToRow))

const total = computed(() =>
    rows.value.reduce((acc, row) => acc + row.sum, 0)
)

function orderToRow(order) {
    const goods  = order.goods || []
    const qtys   = order.quantities || []
    const prices = order.prices || []
    const parts  = []
    let sum = 0

    goods.forEach((name, i) => {
        const label = String(name || '').trim()
        if (!label) return
        const qty = Number(qtys[i] ?? 1) || 1
        const price = Number(prices[i] ?? 0) || 0
        parts.push(`${label} × ${qty}`)
        sum += price * qty
    })

    return {
        id: order.id,
        full_name: order.full_name,
        items_label: parts.join(' · '),
        sum,
    }
}

function formatByn(value) {
    return `${Number(value || 0).toFixed(2)} BYN`
}

function storageKey(orderId) {
    return `packing:${tenantId.value}:${props.screen}:${orderId}`
}

function isPackedValue(raw) {
    return raw === '1' || raw === 'true'
}

function readPacked(orderId) {
    try {
        return isPackedValue(localStorage.getItem(storageKey(orderId)))
    } catch (e) {
        return false
    }
}

function writePacked(orderId, packedFlag) {
    try {
        localStorage.setItem(storageKey(orderId), packedFlag ? '1' : '0')
    } catch (e) {
        // ignore quota / private mode
    }
}

function hydratePacked() {
    ;(props.orders || []).forEach((order) => {
        packed[order.id] = readPacked(order.id)
    })
}

function onToggle(orderId, event) {
    const value = !!event.target.checked
    packed[orderId] = value
    writePacked(orderId, value)
}

onMounted(hydratePacked)
watch(() => [tenantId.value, props.screen, props.orders], hydratePacked, { deep: true })
</script>
