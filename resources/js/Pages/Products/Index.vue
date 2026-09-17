<template>
    <AppLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Товары и склад</h1>
                </template>
                <template #actions>
                    <button v-if="!readOnly" class="btn-primary" @click="openCreateModal()">
                        + Добавить товар
                    </button>
                </template>
            </PageHeader>
        </template>

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="card py-4">
                <p class="stat-label">Позиций</p>
                <p class="stat-value mt-1">{{ productList.length }}</p>
            </div>
            <div class="card py-4">
                <p class="stat-label">На складе</p>
                <p class="stat-value mt-1">{{ totalStock }}</p>
            </div>
            <div class="card py-4">
                <p class="stat-label">Продано (шт)</p>
                <p class="stat-value mt-1">{{ totalSoldCount }}</p>
            </div>
            <div class="card py-4">
                <p class="stat-label">Выручка (р.)</p>
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-1">{{ formatAmount(totalSoldAmount) }}</p>
            </div>
        </div>

        <!-- Products -->
        <div v-if="productList.length === 0" class="card text-center py-12">
            <p class="text-gray-500 dark:text-gray-400 text-sm">
                Добавьте хотя бы один товар — его можно выбрать в заказе.
            </p>
            <button
                v-if="!readOnly"
                type="button"
                class="btn-primary mt-4"
                @click="openCreateModal()"
            >
                + Добавить товар
            </button>
        </div>

        <ResponsiveList v-else>
            <template #table>
                <div class="card overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="pb-3 font-medium text-muted">Название</th>
                                <th class="pb-3 font-medium text-muted">Ссылка</th>
                                <th class="pb-3 font-medium text-muted text-right w-24">Вес (г)</th>
                                <th v-if="srEnabled" class="pb-3 font-medium text-muted text-right w-28">ID SR</th>
                                <th class="pb-3 font-medium text-muted text-right w-28">На складе</th>
                                <th class="pb-3 font-medium text-muted text-right w-28">Продано (шт)</th>
                                <th class="pb-3 font-medium text-muted text-right w-32">Выручка (р.)</th>
                                <th class="pb-3 w-32"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <template v-for="product in productList" :key="product.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                <td class="py-3">
                                    <span v-if="editing !== product.id" class="font-medium text-gray-800 dark:text-gray-200">{{ product.name }}</span>
                                    <input
                                        v-else
                                        v-model="editForm.name"
                                        class="input max-w-xs py-1"
                                        @keyup.enter="saveEdit(product)"
                                        @keyup.esc="cancelEdit"
                                    />
                                </td>

                                <td class="py-3 text-sm">
                                    <template v-if="editing !== product.id">
                                        <a
                                            v-if="product.page_url"
                                            :href="product.page_url"
                                            target="_blank"
                                            rel="noopener"
                                            :title="product.page_url"
                                            class="text-xs text-indigo-600 hover:underline inline-flex items-center gap-1 dark:text-indigo-400"
                                        >
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5M21 3l-9 9m0 0h5.25M12 12V3"/>
                                            </svg>
                                            {{ truncateLinkDisplay(product.page_url) }}
                                        </a>
                                        <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                                    </template>
                                    <input
                                        v-else
                                        v-model="editForm.page_url"
                                        type="text"
                                        placeholder="example.com/page"
                                        class="input max-w-xs py-1"
                                    />
                                </td>

                                <td class="py-3 text-right text-gray-600 dark:text-gray-400">
                                    <span v-if="editing !== product.id">{{ product.weight ?? '—' }}</span>
                                    <input
                                        v-else
                                        v-model="editForm.weight"
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        class="input w-20 py-1 text-right"
                                    />
                                </td>

                                <td v-if="srEnabled" class="py-3 text-right text-gray-600 dark:text-gray-400">
                                    <span v-if="editing !== product.id">{{ product.sr_item_id ?? '—' }}</span>
                                    <input
                                        v-else
                                        v-model.number="editForm.sr_item_id"
                                        type="number"
                                        min="1"
                                        placeholder="—"
                                        class="input w-24 py-1 text-right"
                                    />
                                </td>

                                <td class="py-3 text-right">
                                    <span :class="product.stock < 10 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-300'">
                                        {{ product.stock }}
                                    </span>
                                </td>

                                <td class="py-3 text-right text-gray-600 dark:text-gray-400">{{ product.sold_count ?? 0 }}</td>

                                <td class="py-3 text-right font-medium"
                                    :class="(product.sold_amount ?? 0) > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'">
                                    {{ formatAmount(product.sold_amount ?? 0) }}
                                </td>

                                <td class="py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Editing mode -->
                                        <template v-if="editing === product.id">
                                            <button class="btn-primary btn-xs" @click="saveEdit(product)">Сохранить</button>
                                            <button class="btn-secondary btn-xs" @click="cancelEdit">Отмена</button>
                                        </template>

                                        <!-- Normal mode -->
                                        <template v-else>
                                            <button
                                                class="btn-secondary btn-xs text-indigo-600"
                                                @click="openIntakeModal(product)"
                                                title="Приход товара"
                                            >
                                                + Приход
                                            </button>
                                            <button class="btn-secondary btn-xs" @click="startEdit(product)">
                                                Изменить
                                            </button>
                                            <button
                                                class="btn-secondary btn-xs text-red-500"
                                                @click="confirmDelete(product)"
                                            >
                                                Удалить
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="editing === product.id">
                                <td :colspan="srEnabled ? 8 : 7" class="pb-4 pt-0">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-gray-50 dark:bg-gray-800/60 rounded-md p-3">
                                        <div>
                                            <label class="label">Апсейл</label>
                                            <input v-model="editForm.upsell_name" class="input mt-1 py-1" placeholder="Название" />
                                        </div>
                                        <div>
                                            <label class="label">Цена апсейла</label>
                                            <input v-model="editForm.upsell_price" type="number" min="0" step="0.01" class="input mt-1 py-1" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="label">Текст апсейла</label>
                                            <textarea v-model="editForm.upsell_text" rows="2" class="input mt-1 resize-none" />
                                        </div>
                                        <div>
                                            <label class="label">Кроссейл</label>
                                            <input v-model="editForm.cross_name" class="input mt-1 py-1" placeholder="Название" />
                                        </div>
                                        <div>
                                            <label class="label">Цена кроссейла</label>
                                            <input v-model="editForm.cross_price" type="number" min="0" step="0.01" class="input mt-1 py-1" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="label">Текст кроссейла</label>
                                            <textarea v-model="editForm.cross_text" rows="2" class="input mt-1 resize-none" />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="label">Заметка оператору</label>
                                            <textarea v-model="editForm.manager_note" rows="2" class="input mt-1 resize-none" />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>

            <template #cards>
                <ListCard v-for="product in productList" :key="product.id">
                    <template v-if="editing !== product.id">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ product.name }}</p>

                        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">Вес:</dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ product.weight ?? '—' }} г</dd>
                            </div>
                            <div v-if="srEnabled" class="flex justify-between gap-2">
                                <dt class="text-muted">ID SR:</dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ product.sr_item_id ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">На складе:</dt>
                                <dd :class="product.stock < 10 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-300'">
                                    {{ product.stock }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">Продано:</dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ product.sold_count ?? 0 }} шт</dd>
                            </div>
                            <div class="col-span-2 flex justify-between gap-2">
                                <dt class="text-muted">Выручка:</dt>
                                <dd class="font-medium"
                                    :class="(product.sold_amount ?? 0) > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'">
                                    {{ formatAmount(product.sold_amount ?? 0) }} р.
                                </dd>
                            </div>
                            <div v-if="product.upsell_name" class="col-span-2 flex justify-between gap-2">
                                <dt class="text-muted">Апсейл:</dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ product.upsell_name }}</dd>
                            </div>
                            <div v-if="product.manager_note" class="col-span-2">
                                <dt class="text-muted">Заметка КЦ:</dt>
                                <dd class="text-xs text-muted mt-0.5">{{ product.manager_note }}</dd>
                            </div>
                        </dl>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button class="btn-secondary btn-xs text-indigo-600 touch-target" @click="openIntakeModal(product)">
                                + Приход
                            </button>
                            <button class="btn-secondary btn-xs touch-target" @click="startEdit(product)">Изменить</button>
                            <button class="btn-secondary btn-xs text-red-500 touch-target" @click="confirmDelete(product)">Удалить</button>
                        </div>
                    </template>

                    <template v-else>
                        <div class="space-y-2">
                            <div>
                                <label class="label">Название</label>
                                <input v-model="editForm.name" class="input mt-1" />
                            </div>
                            <div>
                                <label class="label">Ссылка на страницу</label>
                                <input v-model="editForm.page_url" type="text" placeholder="example.com/page" class="input mt-1" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="label">Вес (г)</label>
                                    <input v-model="editForm.weight" type="number" step="0.1" min="0" class="input mt-1" />
                                </div>
                                <div v-if="srEnabled">
                                    <label class="label">ID SR</label>
                                    <input v-model.number="editForm.sr_item_id" type="number" min="1" placeholder="—" class="input mt-1" />
                                </div>
                            </div>
                            <div>
                                <label class="label">Апсейл</label>
                                <input v-model="editForm.upsell_name" class="input mt-1" placeholder="Название" />
                            </div>
                            <div>
                                <label class="label">Цена апсейла</label>
                                <input v-model="editForm.upsell_price" type="number" min="0" step="0.01" class="input mt-1" />
                            </div>
                            <div>
                                <label class="label">Текст апсейла</label>
                                <textarea v-model="editForm.upsell_text" rows="2" class="input mt-1 resize-none" />
                            </div>
                            <div>
                                <label class="label">Кроссейл</label>
                                <input v-model="editForm.cross_name" class="input mt-1" placeholder="Название" />
                            </div>
                            <div>
                                <label class="label">Цена кроссейла</label>
                                <input v-model="editForm.cross_price" type="number" min="0" step="0.01" class="input mt-1" />
                            </div>
                            <div>
                                <label class="label">Текст кроссейла</label>
                                <textarea v-model="editForm.cross_text" rows="2" class="input mt-1 resize-none" />
                            </div>
                            <div>
                                <label class="label">Заметка оператору</label>
                                <textarea v-model="editForm.manager_note" rows="2" class="input mt-1 resize-none" />
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button class="btn-primary btn-xs flex-1 justify-center touch-target" @click="saveEdit(product)">Сохранить</button>
                            <button class="btn-secondary btn-xs flex-1 justify-center touch-target" @click="cancelEdit">Отмена</button>
                        </div>
                    </template>
                </ListCard>
            </template>
        </ResponsiveList>

        <!-- ── Create product modal ── -->
        <div v-if="createModal" class="modal-backdrop" @click.self="createModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-4">Добавить товар</h2>
                <div class="space-y-3">
                    <div>
                        <label class="label">Название</label>
                        <input v-model="createForm.name" class="input" placeholder="Наименование товара" />
                    </div>
                    <div>
                        <label class="label">Ссылка на страницу товара</label>
                        <input v-model="createForm.page_url" type="text" class="input" placeholder="example.com/page" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Вес (г)</label>
                            <input v-model.number="createForm.weight" type="number" min="0" step="0.1" class="input" />
                        </div>
                        <div>
                            <label class="label">Остаток (шт)</label>
                            <input v-model.number="createForm.stock" type="number" min="0" class="input" />
                        </div>
                    </div>
                    <div v-if="srEnabled">
                        <label class="label">ID SalesRender</label>
                        <input v-model.number="createForm.sr_item_id" type="number" min="1" class="input" placeholder="Оставьте пустым если не нужно" />
                    </div>
                    <div>
                        <label class="label">Апсейл</label>
                        <input v-model="createForm.upsell_name" class="input" placeholder="Название" />
                    </div>
                    <div>
                        <label class="label">Цена апсейла</label>
                        <input v-model="createForm.upsell_price" type="number" min="0" step="0.01" class="input" />
                    </div>
                    <div>
                        <label class="label">Текст апсейла</label>
                        <textarea v-model="createForm.upsell_text" rows="2" class="input resize-none" />
                    </div>
                    <div>
                        <label class="label">Кроссейл</label>
                        <input v-model="createForm.cross_name" class="input" placeholder="Название" />
                    </div>
                    <div>
                        <label class="label">Цена кроссейла</label>
                        <input v-model="createForm.cross_price" type="number" min="0" step="0.01" class="input" />
                    </div>
                    <div>
                        <label class="label">Текст кроссейла</label>
                        <textarea v-model="createForm.cross_text" rows="2" class="input resize-none" />
                    </div>
                    <div>
                        <label class="label">Заметка оператору</label>
                        <textarea v-model="createForm.manager_note" rows="2" class="input resize-none" />
                    </div>
                    <p v-if="createError" class="text-xs text-red-600">{{ createError }}</p>
                </div>
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 mt-5">
                    <button class="btn-secondary justify-center" @click="createModal = false">Отмена</button>
                    <button class="btn-primary justify-center" :disabled="saving" @click="createProduct">
                        {{ saving ? 'Сохраняю…' : 'Добавить' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Intake modal (приход товара) ── -->
        <div v-if="intakeModal" class="modal-backdrop" @click.self="intakeModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-1">Приход товара</h2>
                <p class="text-sm text-muted mb-4">{{ intakeProduct?.name }}</p>
                <div class="space-y-3">
                    <div>
                        <label class="label">Количество (+ добавить)</label>
                        <input
                            v-model.number="intakeDelta"
                            type="number"
                            min="1"
                            class="input"
                            placeholder="Напр. 50"
                        />
                    </div>
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Текущий остаток: <strong>{{ intakeProduct?.stock }}</strong> →
                        Станет: <strong>{{ (intakeProduct?.stock ?? 0) + (intakeDelta || 0) }}</strong>
                    </p>
                    <p v-if="intakeError" class="text-xs text-red-600">{{ intakeError }}</p>
                </div>
                <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 mt-5">
                    <button class="btn-secondary justify-center" @click="intakeModal = false">Отмена</button>
                    <button class="btn-primary justify-center" :disabled="saving || !intakeDelta" @click="saveIntake">
                        {{ saving ? 'Сохраняю…' : 'Оприходовать' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import ResponsiveList from '@/Components/ResponsiveList.vue'
import ListCard from '@/Components/ListCard.vue'
import { useSubscription } from '@/composables/useSubscription'
import { apiFetch } from '@/utils/api'
import { truncateLinkDisplay } from '@/utils/truncateLink'

const { readOnly } = useSubscription()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    products:  { type: Array,   default: () => [] },
    counted:   { type: Object,  default: () => ({}) },
    srEnabled: { type: Boolean, default: false },
})

// ── State ─────────────────────────────────────────────────────────────────────
const productList = ref([...props.products])
const editing     = ref(null)
const editForm    = ref(emptyProductForm())
const saving      = ref(false)

// Create modal
const createModal = ref(false)
const createForm  = ref(emptyProductForm())
const createError = ref('')

// Intake modal
const intakeModal   = ref(false)
const intakeProduct = ref(null)
const intakeDelta   = ref(0)
const intakeError   = ref('')

// ── Computed stats ────────────────────────────────────────────────────────────
const totalStock      = computed(() => productList.value.reduce((s, p) => s + (p.stock ?? 0), 0))
const totalSoldCount  = computed(() => productList.value.reduce((s, p) => s + (p.sold_count ?? 0), 0))
const totalSoldAmount = computed(() => productList.value.reduce((s, p) => s + (p.sold_amount ?? 0), 0))

// ── Edit inline ───────────────────────────────────────────────────────────────
function emptyOfferFields() {
    return {
        upsell_name:  '',
        upsell_price: null,
        upsell_text:  '',
        cross_name:   '',
        cross_price:  null,
        cross_text:   '',
        manager_note: '',
    }
}

function emptyProductForm(presetName = '') {
    return {
        name:       presetName,
        page_url:   '',
        weight:     0,
        stock:      0,
        sr_item_id: null,
        ...emptyOfferFields(),
    }
}

function offerPayload(src) {
    const str = (v) => (v === '' || v == null ? null : v)
    const num = (v) => (v === '' || v == null ? null : v)
    return {
        upsell_name:  str(src.upsell_name),
        upsell_price: num(src.upsell_price),
        upsell_text:  str(src.upsell_text),
        cross_name:   str(src.cross_name),
        cross_price:  num(src.cross_price),
        cross_text:   str(src.cross_text),
        manager_note: str(src.manager_note),
    }
}

function startEdit(product) {
    editing.value  = product.id
    editForm.value = {
        name:         product.name,
        page_url:     product.page_url ?? '',
        weight:       product.weight ?? 0,
        sr_item_id:   product.sr_item_id ?? null,
        upsell_name:  product.upsell_name ?? '',
        upsell_price: product.upsell_price ?? null,
        upsell_text:  product.upsell_text ?? '',
        cross_name:   product.cross_name ?? '',
        cross_price:  product.cross_price ?? null,
        cross_text:   product.cross_text ?? '',
        manager_note: product.manager_note ?? '',
    }
}

function cancelEdit() {
    editing.value = null
}

async function saveEdit(product) {
    saving.value = true
    try {
        const resp = await apiFetch(`/products/${product.id}`, 'PUT', {
            name:       editForm.value.name,
            page_url:   editForm.value.page_url || null,
            weight:     editForm.value.weight,
            sr_item_id: editForm.value.sr_item_id || null,
            ...offerPayload(editForm.value),
        })
        const data = await resp.json()
        if (data.success) {
            updateInList(data.product)
            editing.value = null
        }
    } finally {
        saving.value = false
    }
}

// ── Create ────────────────────────────────────────────────────────────────────
function openCreateModal(presetName = '') {
    createForm.value  = emptyProductForm(typeof presetName === 'string' ? presetName : '')
    createError.value = ''
    createModal.value = true
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search)
    const suggestName = params.get('suggest_name')
    if (suggestName) {
        openCreateModal(suggestName)
    }
})

async function createProduct() {
    if (!createForm.value.name.trim()) {
        createError.value = 'Введите название'
        return
    }
    saving.value      = true
    createError.value = ''
    try {
        const resp = await apiFetch('/products', 'POST', createForm.value)
        const data = await resp.json()
        if (data.success) {
            productList.value.unshift(data.product)
            createModal.value = false
        } else {
            createError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

// ── Intake (приход товара) ────────────────────────────────────────────────────
function openIntakeModal(product) {
    intakeProduct.value = product
    intakeDelta.value   = null
    intakeError.value   = ''
    intakeModal.value   = true
}

async function saveIntake() {
    if (!intakeDelta.value || intakeDelta.value <= 0) {
        intakeError.value = 'Введите количество больше 0'
        return
    }
    saving.value      = true
    intakeError.value = ''
    try {
        const resp = await apiFetch(`/products/${intakeProduct.value.id}`, 'PUT', {
            stock_delta: intakeDelta.value,
        })
        const data = await resp.json()
        if (data.success) {
            updateInList(data.product)
            intakeModal.value = false
        } else {
            intakeError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function confirmDelete(product) {
    if (!confirm(`Удалить товар «${product.name}»? Это действие нельзя отменить.`)) return

    const resp = await apiFetch(`/products/${product.id}`, 'DELETE')
    const data = await resp.json()
    if (data.success) {
        productList.value = productList.value.filter(p => p.id !== product.id)
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function updateInList(updated) {
    const idx = productList.value.findIndex(p => p.id === updated.id)
    if (idx !== -1) {
        productList.value[idx] = { ...productList.value[idx], ...updated }
    }
}

function formatAmount(value) {
    return Number(value ?? 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
