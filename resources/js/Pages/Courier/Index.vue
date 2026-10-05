<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Курьер</h1>
                    <p class="text-sm text-muted mt-0.5">
                        Заявок «Отправить»: <strong>{{ orderQueue.length }}</strong>
                    </p>
                </template>
                <template #actions>
                    <button
                        class="btn-primary"
                        :disabled="processing || orderQueue.length === 0 || readOnly"
                        @click="sendAll"
                    >
                        {{ processing ? 'Отправляю…' : 'В Telegram все' }}
                    </button>
                </template>
            </PageHeader>
        </template>

        <PackingChecklist
            :orders="eligibleOrders"
            :screen="'courier'"
            :print-url="'/courier/packing.pdf'"
        />

        <div v-if="orderQueue.length === 0" class="card text-center py-16 text-gray-400 dark:text-gray-500">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-sm">Нет заявок со статусом «Отправить» и доставкой «Курьер»</p>
        </div>

        <div v-else class="card">
            <div class="overflow-x-auto -mx-4 px-4 md:mx-0 md:px-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted w-8">#</th>
                            <th class="pb-3 font-medium text-muted">ФИО</th>
                            <th class="pb-3 font-medium text-muted">Адрес</th>
                            <th class="pb-3 font-medium text-muted">Товары</th>
                            <th class="pb-3 font-medium text-muted text-right w-28">Сумма</th>
                            <th class="pb-3 font-medium text-muted">Комментарий</th>
                            <th class="pb-3 font-medium text-muted w-40">Результат</th>
                            <th class="pb-3 w-36"></th>
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
                                {{ order.full_address }}
                            </td>

                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400">
                                <span v-for="(good, i) in (order.goods || [])" :key="i" class="block">
                                    {{ good }} × {{ order.quantities?.[i] ?? 1 }}
                                    <span v-if="order.prices?.[i]" class="text-gray-400 dark:text-gray-500">({{ order.prices[i] }} р.)</span>
                                </span>
                            </td>

                            <td class="py-3 text-right whitespace-nowrap">{{ formatByn(orderSum(order)) }}</td>

                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400 max-w-[160px]">
                                {{ order.comment || '—' }}
                            </td>

                            <td class="py-3">
                                <span v-if="sending[order.id]" class="text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                                    <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    Отправка…
                                </span>
                                <template v-else-if="results[order.id]">
                                    <span v-if="results[order.id].success" class="text-xs text-green-600 dark:text-green-400 font-medium">
                                        ✓ Telegram
                                    </span>
                                    <span v-else class="text-xs text-red-500 leading-tight">
                                        {{ results[order.id].error_message || results[order.id].error || 'Ошибка' }}
                                    </span>
                                </template>
                            </td>

                            <td class="py-3 text-right whitespace-nowrap">
                                <a
                                    :href="`/courier/orders/${order.id}/sheet.pdf`"
                                    class="btn-secondary btn-xs mr-1"
                                >PDF</a>
                                <button
                                    class="btn-secondary btn-xs"
                                    :disabled="processing || sending[order.id] || readOnly"
                                    @click="sendOne(order)"
                                >
                                    В Telegram
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import PackingChecklist from '@/Components/PackingChecklist.vue'
import { useSubscription } from '@/composables/useSubscription'
import { apiFetch } from '@/utils/api'

const { readOnly } = useSubscription()

const props = defineProps({
    eligibleOrders: { type: Array, default: () => [] },
})

const orderQueue = ref([...props.eligibleOrders])
const processing = ref(false)
const sending    = reactive({})
const results    = ref({})

async function sendAll() {
    if (readOnly.value) return
    if (processing.value) return
    processing.value = true
    try {
        const resp = await apiFetch('/courier/telegram-all', 'POST')
        const data = await resp.json()
        if (data.results) {
            results.value = { ...results.value, ...data.results }
        } else if (!resp.ok) {
            const failure = {
                success: false,
                error: data.error || 'exception',
                error_message: data.error_message || 'Ошибка',
            }
            const next = { ...results.value }
            orderQueue.value.forEach((order) => {
                next[order.id] = failure
            })
            results.value = next
        }
    } catch (e) {
        const failure = { success: false, error: 'exception', error_message: e.message }
        const next = { ...results.value }
        orderQueue.value.forEach((order) => {
            next[order.id] = failure
        })
        results.value = next
    } finally {
        processing.value = false
    }
}

async function sendOne(order) {
    if (readOnly.value) return
    sending[order.id] = true
    try {
        const resp = await apiFetch(`/courier/orders/${order.id}/telegram`, 'POST')
        const data = await resp.json()
        results.value = { ...results.value, [order.id]: data }
    } catch (e) {
        results.value = {
            ...results.value,
            [order.id]: { success: false, error: 'exception', error_message: e.message },
        }
    } finally {
        sending[order.id] = false
    }
}

function orderSum(order) {
    const goods  = order.goods || []
    const qtys   = order.quantities || []
    const prices = order.prices || []
    let sum = 0
    goods.forEach((_, i) => {
        const qty   = Number(qtys[i] ?? 1) || 1
        const price = Number(prices[i] ?? 0) || 0
        sum += price * qty
    })
    return sum
}

function formatByn(value) {
    return `${Number(value || 0).toFixed(2)} BYN`
}

function formatPhone(phone) {
    if (!phone) return ''
    const p = String(phone).replace(/\D/g, '')
    return p.length >= 9 ? '+375 ' + p.slice(-9, -7) + ' ' + p.slice(-7, -4) + '-' + p.slice(-4, -2) + '-' + p.slice(-2) : phone
}
</script>

<style scoped>
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
