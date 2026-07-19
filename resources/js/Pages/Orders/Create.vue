<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <button class="btn-secondary btn-sm" @click="cancel">← Назад</button>
                    <h1 class="page-title">Новый заказ</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button class="btn-secondary" @click="cancel">Отмена</button>
                    <button class="btn-primary" :disabled="form.processing" @click="submit">
                        {{ form.processing ? 'Сохраняю…' : 'Создать заказ' }}
                    </button>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Main info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Customer -->
                <div class="card">
                    <h2 class="section-title mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        Клиент
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="label">
                                ФИО <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.full_name"
                                type="text"
                                class="w-full mt-1"
                                :class="{ 'border-red-400 focus:ring-red-300': form.errors.full_name }"
                                placeholder="Иванов Иван Иванович"
                            />
                            <p v-if="form.errors.full_name" class="mt-1 text-xs text-red-500">
                                {{ form.errors.full_name }}
                            </p>
                        </div>
                        <div>
                            <label class="label">Телефон</label>
                            <input
                                v-model="form.phone"
                                type="tel"
                                class="w-full mt-1"
                                placeholder="+375 29 123-45-67"
                            />
                        </div>
                        <div>
                            <label class="label">
                                Статус <span class="text-red-500">*</span>
                            </label>
                            <select v-model="form.status" class="w-full mt-1">
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Источник</label>
                            <input
                                v-model="form.source"
                                type="text"
                                class="w-full mt-1"
                                placeholder="manual"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Комментарий</label>
                            <textarea
                                v-model="form.sms_log"
                                rows="2"
                                class="w-full mt-1 resize-none"
                                placeholder="Заметки, пожелания клиента…"
                            />
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
                    </h2>
                    <template v-if="form.delivery_type === 'belpost'">
                        <AddressInlinePicker
                            ref="pickerRef"
                            v-model:city="form.city"
                            v-model:street="form.street"
                            v-model:building="form.building"
                            v-model:belpostAddressId="form.belpost_address_id"
                        />
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

                <!-- Goods -->
                <div class="card">
                    <h2 class="section-title mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        Товары
                    </h2>
                    <div class="space-y-3">
                        <div
                            v-for="(_, i) in form.goods"
                            :key="i"
                            class="flex items-center gap-3"
                        >
                            <select v-model="form.goods[i]" class="flex-1">
                                <option value="">— выберите товар —</option>
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
                            <button
                                class="text-red-400 hover:text-red-600 p-1 shrink-0"
                                @click="removeGood(i)"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.goods.length === 0" class="text-sm text-gray-400 dark:text-gray-500 italic">Нет товаров</p>
                        <button class="btn-secondary btn-sm" @click="addGood">+ Добавить товар</button>
                    </div>

                    <div v-if="totalPrice > 0" class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                            Итого: {{ formatPrice(totalPrice) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Right: Summary + actions -->
            <div class="space-y-6">
                <!-- Delivery -->
                <div class="card">
                    <h2 class="section-title mb-4">Доставка</h2>
                    <label class="label block mb-1">Вид доставки</label>
                    <select v-model="form.delivery_type" class="w-full">
                        <option value="">— выберите —</option>
                        <option v-for="(label, key) in deliveryTypes" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="card">
                    <h2 class="section-title mb-4">Итог</h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted">Статус:</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ form.status }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Товаров:</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ filledGoods }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Сумма:</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ totalPrice > 0 ? formatPrice(totalPrice) : '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Доставка:</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ deliveryTypes[form.delivery_type] ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted">Источник:</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ form.source || 'manual' }}</dd>
                        </div>
                    </dl>
                    <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-2">
                        <button
                            class="btn-primary w-full justify-center"
                            :disabled="form.processing"
                            @click="submit"
                        >
                            {{ form.processing ? 'Сохраняю…' : 'Создать заказ' }}
                        </button>
                        <button class="btn-secondary w-full justify-center" @click="cancel">
                            Отмена
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import { useForm } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AddressInlinePicker from '@/Components/AddressInlinePicker.vue'

const props = defineProps({
    statuses:      Array,
    deliveryTypes: Object,
    products:      Array,
})

const pickerRef = ref(null)

const form = useForm({
    full_name:     '',
    phone:         '',
    status:        'Позвонить',
    source:        '',
    sms_log:       '',
    delivery_type: '',
    city:              '',
    street:            '',
    building:          '',
    housing:           '',
    apartment:         '',
    belpost_address_id: '',
    goods:      [],
    quantities: [],
    prices:     [],
})

watch(() => form.delivery_type, (newType, oldType) => {
    if (oldType === 'belpost' && newType !== 'belpost') {
        form.belpost_address_id = ''
    }
})

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

function submit() {
    if (form.delivery_type === 'belpost' && pickerRef.value) {
        if (!pickerRef.value.validate()) return
    }
    form.transform(data => ({
        ...data,
        delivery_type: data.delivery_type || null,
    })).post('/orders')
}

function cancel() {
    Inertia.get('/orders')
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '—'
    return Number(value).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' р.'
}

const totalPrice = computed(() =>
    form.prices.reduce((sum, p, i) => {
        const qty = form.quantities[i] ?? 1
        return sum + (Number(p) || 0) * Number(qty)
    }, 0)
)

const filledGoods = computed(() => form.goods.filter(g => g !== '').length)
</script>
