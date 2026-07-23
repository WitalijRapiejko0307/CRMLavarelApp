<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h1 class="page-title">Европочта — Оформление бланков</h1>
                    <p class="text-sm text-muted mt-0.5">
                        Заявок «Отправить»: <strong>{{ orderQueue.length }}</strong>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <label class="label whitespace-nowrap">Кто платит:</label>
                        <select v-model="whoPays" class="text-sm border border-gray-300 rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                            <option value="Покупатель">Покупатель</option>
                            <option value="Продавец">Продавец</option>
                        </select>
                    </div>
                    <button
                        class="btn-primary"
                        :disabled="processing || orderQueue.length === 0 || readOnly"
                        @click="processAll"
                    >
                        {{ processing
                            ? `Обрабатываю ${processingIndex + 1}/${pendingQueue.length}…`
                            : 'Оформить все бланки' }}
                    </button>
                </div>
            </div>
        </template>

        <!-- Empty state -->
        <div v-if="orderQueue.length === 0" class="card text-center py-16 text-gray-400 dark:text-gray-500">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-sm">Нет заявок со статусом «Отправить» и доставкой «Европочта»</p>
        </div>

        <!-- Orders table -->
        <div v-else class="card">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted w-8">#</th>
                            <th class="pb-3 font-medium text-muted">Клиент</th>
                            <th class="pb-3 font-medium text-muted">Адрес отделения</th>
                            <th class="pb-3 font-medium text-muted">Товары</th>
                            <th class="pb-3 font-medium text-muted w-40">Результат</th>
                            <th class="pb-3 w-24"></th>
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
                                <div>{{ [order.city, order.street, order.building].filter(Boolean).join(', ') }}</div>
                                <div v-if="order.ops_id" class="text-gray-400 dark:text-gray-500">ОПС №{{ order.ops_id }}</div>
                            </td>

                            <td class="py-3 text-xs text-gray-600 dark:text-gray-400">
                                <span v-for="(good, i) in (order.goods || [])" :key="i" class="block">
                                    {{ good }} × {{ order.quantities?.[i] ?? 1 }}
                                    <span v-if="order.prices?.[i]" class="text-gray-400 dark:text-gray-500">({{ order.prices[i] }} р.)</span>
                                </span>
                            </td>

                            <td class="py-3">
                                <!-- Spinner -->
                                <span v-if="processingIndex >= 0 && pendingQueue[processingIndex]?.id === order.id && processing"
                                    class="text-xs text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
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
                                        <span v-if="results[order.id].error_message" class="block text-gray-400 dark:text-gray-500 truncate max-w-[160px]" :title="results[order.id].error_message">
                                            {{ results[order.id].error_message }}
                                        </span>
                                    </span>
                                </template>
                            </td>

                            <td class="py-3 text-right">
                                <button
                                    v-if="!results[order.id]?.success"
                                    class="btn-secondary btn-xs"
                                    :disabled="processing"
                                    @click="registerOne(order)"
                                >
                                    {{ results[order.id] ? 'Повторить' : 'Оформить' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Summary bar -->
            <div v-if="hasResults" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 flex items-center gap-6 text-sm">
                <span class="text-green-600 dark:text-green-400 font-medium">✓ Успешно: {{ successCount }}</span>
                <span v-if="errorCount > 0" class="text-red-500">✕ Ошибок: {{ errorCount }}</span>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useSubscription } from '@/composables/useSubscription'
import { apiFetch } from '@/utils/api'

const { readOnly } = useSubscription()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    eligibleOrders: { type: Array, default: () => [] },
})

// ── State ─────────────────────────────────────────────────────────────────────
const orderQueue      = ref([...props.eligibleOrders])
const processing      = ref(false)
const processingIndex = ref(-1)
const results         = ref({})   // { [orderId]: { success, track_number, error, error_message } }
const whoPays         = ref('Покупатель')

// ── Computed ──────────────────────────────────────────────────────────────────
const pendingQueue = computed(() =>
    orderQueue.value.filter(o => !results.value[o.id]?.success)
)

const hasResults = computed(() => Object.keys(results.value).length > 0)

const successCount = computed(() =>
    Object.values(results.value).filter(r => r.success).length
)

const errorCount = computed(() =>
    Object.values(results.value).filter(r => !r.success).length
)

// ── Methods ───────────────────────────────────────────────────────────────────

async function processAll() {
    if (readOnly.value) return
    if (processing.value) return
    processing.value = true
    processingIndex.value = 0

    const queue = pendingQueue.value

    for (let i = 0; i < queue.length; i++) {
        processingIndex.value = i
        await registerOne(queue[i])
    }

    processing.value      = false
    processingIndex.value = -1
}

async function registerOne(order) {
    if (readOnly.value) return
    try {
        const resp = await apiFetch(`/europochta/orders/${order.id}/register`, 'POST', {
            who_pays: whoPays.value,
        })
        const data = await resp.json()
        results.value = { ...results.value, [order.id]: data }
    } catch (e) {
        results.value = {
            ...results.value,
            [order.id]: { success: false, error: 'exception', error_message: e.message },
        }
    }
}

function formatPhone(phone) {
    if (!phone) return ''
    const p = String(phone).replace(/\D/g, '')
    return p.length >= 9 ? '+375 ' + p.slice(-9, -7) + ' ' + p.slice(-7, -4) + '-' + p.slice(-4, -2) + '-' + p.slice(-2) : phone
}

function errorLabel(code) {
    const map = {
        config_error:             '⚠ Не настроены API-ключи',
        auth_error:               '✕ Ошибка авторизации',
        office_not_found:         '⚠ Отделение не найдено',
        weight_category_not_found: '⚠ Категория веса не найдена',
        api_error:                '✕ Ошибка API',
        exception:                '✕ Исключение',
    }
    return map[code] ?? code
}
</script>

<style scoped>
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
