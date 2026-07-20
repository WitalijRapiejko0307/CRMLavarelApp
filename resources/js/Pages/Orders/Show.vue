<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button class="btn-secondary btn-sm" @click="back">← Назад</button>
                    <h1 class="page-title">
                        Заказ #{{ order.id }}
                        <span v-if="order.external_id" class="text-sm font-normal text-gray-400 dark:text-gray-500">
                            ({{ order.external_id }})
                        </span>
                    </h1>
                    <OrderStatusBadge :status="order.status" />
                </div>
                <div class="flex items-center gap-2">
                    <button v-if="!editing" class="btn-secondary" @click="startEdit">Редактировать</button>
                    <template v-else>
                        <button class="btn-secondary" @click="cancelEdit">Отмена</button>
                        <button class="btn-primary" :disabled="form.processing" @click="saveEdit">
                            {{ form.processing ? 'Сохраняю…' : 'Сохранить' }}
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Main info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Customer info -->
                <div class="card">
                    <h2 class="section-title mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Клиент
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="label">ФИО</label>
                            <div v-if="!editing" class="mt-1 flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ order.full_name }}</span>
                                <span
                                    v-if="!isFullNameComplete(order.full_name)"
                                    class="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full"
                                >
                                    ФИО неполное
                                </span>
                            </div>
                            <template v-else>
                                <input
                                    v-model="form.full_name"
                                    type="text"
                                    class="w-full mt-1"
                                    placeholder="Иванов Иван Иванович"
                                />
                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    Фамилия, имя и отчество через пробел (требование Белпочты)
                                </p>
                            </template>
                        </div>
                        <div>
                            <label class="label">Телефон</label>
                            <div v-if="!editing" class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                <a :href="`tel:${order.phone}`" class="text-indigo-600 hover:underline">
                                    {{ formatPhone(order.phone) || order.phone || '—' }}
                                </a>
                            </div>
                            <input
                                v-else
                                v-model="form.phone"
                                type="tel"
                                class="w-full mt-1"
                                placeholder="375291234567"
                            />
                        </div>
                        <div>
                            <label class="label">Источник</label>
                            <div class="mt-1 text-sm text-muted capitalize">{{ order.source ?? '—' }}</div>
                        </div>
                        <div>
                            <label class="label">Дата создания</label>
                            <div class="mt-1 text-sm text-muted">{{ formatDate(order.created_at) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="card">
                    <h2 class="section-title mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Адрес
                        <span
                            v-if="order.delivery_type === 'belpost' && order.belpost_address_id"
                            class="inline-flex items-center gap-1 ml-1 text-xs font-normal text-green-600 dark:text-green-200 bg-green-50 dark:bg-green-900/30 px-2 py-0.5 rounded-full"
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Белпочта
                        </span>
                    </h2>

                    <!-- View mode: structured display -->
                    <div v-if="!editing" class="text-sm text-body space-y-0.5">
                        <template v-if="fullAddress">
                            <div v-if="order.city" class="text-gray-800 dark:text-gray-200">{{ order.city }}</div>
                            <div v-if="order.street" class="text-gray-600 dark:text-gray-400">{{ order.street }}</div>
                            <div v-if="order.building || order.housing || order.apartment" class="text-gray-600 dark:text-gray-400">
                                <span v-if="order.building">Дом {{ order.building }}</span>
                                <span v-if="order.housing"> · корп. {{ order.housing }}</span>
                                <span v-if="order.apartment"> · кв. {{ order.apartment }}</span>
                            </div>
                        </template>
                        <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                    </div>

                    <!-- Edit mode: belpost → inline picker, others → plain fields -->
                    <div v-else>
                        <!-- Belpost: inline picker -->
                        <template v-if="order.delivery_type === 'belpost'">
                            <AddressInlinePicker
                                ref="pickerRef"
                                v-model:city="form.city"
                                v-model:street="form.street"
                                v-model:building="form.building"
                                v-model:belpostAddressId="form.belpost_address_id"
                                :initial-query="pickerInitialQuery"
                            />
                            <!-- Housing + apartment remain plain inputs -->
                            <div class="grid grid-cols-2 gap-3 mt-3">
                                <div>
                                    <label class="label">Корпус</label>
                                    <input v-model="form.housing" type="text" class="w-full mt-1" />
                                </div>
                                <div>
                                    <label class="label">Квартира</label>
                                    <input v-model="form.apartment" type="text" class="w-full mt-1" />
                                </div>
                            </div>
                        </template>

                        <!-- Non-belpost: plain text fields -->
                        <div v-else class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <div class="col-span-2 sm:col-span-3">
                                <label class="label">Город</label>
                                <input v-model="form.city" type="text" class="w-full mt-1" placeholder="Минск" />
                            </div>
                            <div class="col-span-2">
                                <label class="label">Улица</label>
                                <input v-model="form.street" type="text" class="w-full mt-1" />
                            </div>
                            <div>
                                <label class="label">Дом</label>
                                <input v-model="form.building" type="text" class="w-full mt-1" />
                            </div>
                            <div>
                                <label class="label">Корпус</label>
                                <input v-model="form.housing" type="text" class="w-full mt-1" />
                            </div>
                            <div>
                                <label class="label">Квартира</label>
                                <input v-model="form.apartment" type="text" class="w-full mt-1" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Goods -->
                <div class="card">
                    <h2 class="section-title mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        Товары
                    </h2>

                    <div v-if="!editing">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 font-medium text-gray-600 dark:text-gray-400">Товар</th>
                                    <th class="text-right py-2 font-medium text-gray-600 dark:text-gray-400 w-20">Кол-во</th>
                                    <th class="text-right py-2 font-medium text-gray-600 dark:text-gray-400 w-28">Цена, руб.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(good, i) in order.goods" :key="i" class="border-b border-gray-100 dark:border-gray-700">
                                    <td class="py-2 text-gray-800 dark:text-gray-200">
                                        <span class="inline-flex items-center gap-2 flex-wrap">
                                            {{ good }}
                                            <span
                                                v-if="isUnknownGood(good)"
                                                class="inline-flex items-center text-xs font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full"
                                            >
                                                Не на складе
                                            </span>
                                        </span>
                                    </td>
                                    <td class="py-2 text-right text-gray-600 dark:text-gray-400">{{ order.quantities?.[i] ?? 1 }}</td>
                                    <td class="py-2 text-right text-gray-600 dark:text-gray-400">{{ formatPrice(order.prices?.[i]) }}</td>
                                </tr>
                                <tr v-if="!order.goods?.length">
                                    <td colspan="3" class="py-4 text-center text-gray-400 dark:text-gray-500">Нет товаров</td>
                                </tr>
                                <tr v-if="order.goods?.length" class="font-semibold">
                                    <td colspan="2" class="pt-3 text-right text-gray-700 dark:text-gray-300">Итого:</td>
                                    <td class="pt-3 text-right text-gray-900 dark:text-gray-100">{{ formatPrice(totalPrice) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Edit mode: simple rows -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="(good, i) in form.goods"
                            :key="i"
                            class="space-y-1"
                        >
                            <div class="flex items-center gap-3">
                                <select v-model="form.goods[i]" class="flex-1">
                                    <option value="">— выберите товар —</option>
                                    <option
                                        v-if="form.goods[i] && !isInCatalog(form.goods[i])"
                                        :value="form.goods[i]"
                                    >
                                        {{ form.goods[i] }} (нет на складе)
                                    </option>
                                    <option v-for="p in products" :key="p.id" :value="p.name">{{ p.name }}</option>
                                </select>
                                <input
                                    v-model.number="form.quantities[i]"
                                    type="number"
                                    min="1"
                                    class="w-20 text-center"
                                    placeholder="шт."
                                />
                                <input
                                    v-model.number="form.prices[i]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-28 text-right"
                                    placeholder="цена"
                                />
                                <button class="text-red-400 hover:text-red-600 p-1" @click="removeGood(i)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <div
                                v-if="form.goods[i] && !isInCatalog(form.goods[i])"
                                class="flex items-center gap-3 flex-wrap text-xs"
                            >
                                <span class="inline-flex items-center font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full">
                                    Товар не найден на складе
                                </span>
                                <a
                                    :href="`/products?suggest_name=${encodeURIComponent(form.goods[i])}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-indigo-600 hover:underline"
                                >
                                    Добавить на склад
                                </a>
                            </div>
                        </div>
                        <button class="btn-secondary btn-sm" @click="addGood">+ Добавить товар</button>
                    </div>
                </div>
            </div>

            <!-- Right: Status + delivery + history -->
            <div class="space-y-6">
                <!-- Status change -->
                <div class="card">
                    <h2 class="section-title mb-4">Статус заказа</h2>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between text-sm text-muted">
                            <span>Текущий:</span>
                            <OrderStatusBadge :status="order.status" />
                        </div>
                        <div v-if="order.status_changed_at" class="text-xs text-gray-400 dark:text-gray-500">
                            Изменён: {{ formatDate(order.status_changed_at) }}
                        </div>
                        <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                            <label class="label block mb-1">Изменить статус:</label>
                            <select v-model="newStatus" class="w-full mb-2">
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <button
                                class="btn-primary w-full justify-center"
                                :disabled="statusForm.processing || newStatus === order.status"
                                @click="changeStatus"
                            >
                                {{ statusForm.processing ? 'Сохраняю…' : 'Применить' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Delivery -->
                <div class="card">
                    <h2 class="section-title mb-4">Доставка</h2>
                    <div class="space-y-3 text-sm">
                        <div class="pt-0">
                            <label class="label block mb-1">Вид доставки:</label>
                            <select v-model="selectedDelivery" class="w-full mb-2">
                                <option value="">— не выбрано —</option>
                                <option v-for="(label, key) in deliveryTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <button
                                class="btn-primary w-full justify-center"
                                :disabled="deliveryForm.processing || !selectedDelivery || selectedDelivery === (order.delivery_type ?? '')"
                                @click="changeDeliveryType"
                            >
                                {{ deliveryForm.processing ? 'Сохраняю…' : 'Применить' }}
                            </button>
                        </div>
                        <div v-if="order.track_number" class="pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span class="text-muted">Трек-номер:</span>
                            <span class="ml-2 font-mono text-indigo-700 dark:text-indigo-400 font-medium">{{ order.track_number }}</span>
                        </div>
                        <div v-if="!order.track_number" class="text-gray-400 dark:text-gray-500 italic text-xs">
                            Трек не присвоен
                        </div>
                    </div>
                </div>

                <!-- Status history -->
                <div class="card">
                    <h2 class="section-title mb-4">История статусов</h2>
                    <div v-if="order.status_history?.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic">
                        Нет истории
                    </div>
                    <ol class="relative border-l border-gray-200 dark:border-gray-700 space-y-4">
                        <li
                            v-for="entry in order.status_history"
                            :key="entry.id"
                            class="ml-4"
                        >
                            <div class="absolute w-2.5 h-2.5 bg-indigo-400 rounded-full -left-1.5 border border-white dark:border-gray-800"></div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ entry.to_status }}</span>
                                <span v-if="entry.from_status" class="text-xs text-gray-400 dark:text-gray-500">← {{ entry.from_status }}</span>
                            </div>
                            <time class="text-xs text-gray-400 dark:text-gray-500">{{ formatDate(entry.created_at) }}</time>
                        </li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Edit warning modal -->
        <div v-if="editWarningOpen" class="modal-backdrop" @click.self="editWarningOpen = false">
            <div class="modal-box">
                <h2 class="section-title mb-3">Редактирование оформленной заявки</h2>
                <p class="text-sm text-body mb-6">
                    Заявка уже оформлена. Изменение данных может не совпадать с оформленным бланком или отправлением.
                </p>
                <div class="flex justify-end gap-2">
                    <button class="btn-secondary" @click="editWarningOpen = false">Отмена</button>
                    <button class="btn-primary" @click="confirmEdit">Продолжить</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { useForm } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrderStatusBadge from '@/Components/OrderStatusBadge.vue'
import AddressInlinePicker from '@/Components/AddressInlinePicker.vue'
import { formatPhone, isFullNameComplete, isInCatalog as checkInCatalog } from '@/utils/phone'

const props = defineProps({
    order:         Object,
    statuses:      Array,
    deliveryTypes: Object,
    products:      Array,
    unknownGoods:  { type: Array, default: () => [] },
})

const productNames = computed(() => props.products.map(p => p.name))

function isUnknownGood(name) {
    return props.unknownGoods.includes(name)
}

function isInCatalog(name) {
    return checkInCatalog(name, productNames.value)
}

// --- Edit form ---
const editing         = ref(false)
const editWarningOpen = ref(false)
const pickerRef       = ref(null)

const form = useForm({
    full_name:          props.order.full_name          ?? '',
    phone:              props.order.phone              ?? '',
    city:               props.order.city               ?? '',
    street:             props.order.street             ?? '',
    building:           props.order.building           ?? '',
    housing:            props.order.housing            ?? '',
    apartment:          props.order.apartment          ?? '',
    goods:              [...(props.order.goods         ?? [])],
    quantities:         [...(props.order.quantities    ?? [])],
    prices:             [...(props.order.prices        ?? [])],
    track_number:       props.order.track_number       ?? '',
    belpost_address_id: props.order.belpost_address_id ?? '',
})

// Initial query for the picker pre-fills with current city + street
const pickerInitialQuery = computed(() =>
    [props.order.city, props.order.street].filter(Boolean).join(' ')
)

function cancelEdit() {
    form.reset()
    editing.value = false
}

function startEdit() {
    if (props.order.status === 'Оформлен') {
        editWarningOpen.value = true
        return
    }
    editing.value = true
}

function confirmEdit() {
    editWarningOpen.value = false
    editing.value = true
}

function saveEdit() {
    // Validate house selection for belpost orders
    if (props.order.delivery_type === 'belpost' && pickerRef.value) {
        if (!pickerRef.value.validate()) return
    }
    form.put(`/orders/${props.order.id}`, {
        onSuccess: () => { editing.value = false },
    })
}

function addGood() {
    form.goods.push('')
    form.quantities.push(1)
    form.prices.push(0)
}

function removeGood(index) {
    form.goods.splice(index, 1)
    form.quantities.splice(index, 1)
    form.prices.splice(index, 1)
}

// --- Status change ---
const newStatus    = ref(props.order.status)
const statusForm   = useForm({ status: props.order.status })

function changeStatus() {
    statusForm.status = newStatus.value
    statusForm.patch(`/orders/${props.order.id}/status`, {
        onSuccess: () => {},
    })
}

// --- Delivery type change ---
const selectedDelivery = ref(props.order.delivery_type ?? '')
const deliveryForm     = useForm({ delivery_type: props.order.delivery_type ?? '' })

function changeDeliveryType() {
    deliveryForm.delivery_type = selectedDelivery.value
    deliveryForm.patch(`/orders/${props.order.id}/delivery-type`, {
        onSuccess: () => {},
    })
}

// --- Helpers ---

function back() {
    Inertia.get('/orders')
}

function formatDate(value) {
    if (!value) return '—'
    const d = new Date(value)
    return d.toLocaleDateString('ru-RU', {
        day: '2-digit', month: '2-digit', year: '2-digit',
        hour: '2-digit', minute: '2-digit',
    })
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '—'
    return Number(value).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' р.'
}

const fullAddress = computed(() => {
    const parts = [
        props.order.city,
        props.order.street,
        props.order.building,
        props.order.housing  ? 'корп. ' + props.order.housing  : null,
        props.order.apartment ? 'кв. ' + props.order.apartment : null,
    ].filter(Boolean)
    return parts.join(', ')
})

const totalPrice = computed(() => {
    if (!props.order.prices) return 0
    return props.order.prices.reduce((sum, p, i) => {
        const qty = props.order.quantities?.[i] ?? 1
        return sum + (Number(p) || 0) * qty
    }, 0)
})
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
</style>
