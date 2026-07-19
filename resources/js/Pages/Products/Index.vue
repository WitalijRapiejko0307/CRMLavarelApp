<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="page-title">Товары и склад</h1>
                <button class="btn-primary" @click="openCreateModal">
                    + Добавить товар
                </button>
            </div>
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

        <!-- Products table -->
        <div class="card">
            <div v-if="productList.length === 0" class="text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
                Нет товаров. Добавьте первый товар.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted">Название</th>
                            <th class="pb-3 font-medium text-muted text-right w-24">Вес (г)</th>
                            <th v-if="srEnabled" class="pb-3 font-medium text-muted text-right w-28">ID SR</th>
                            <th class="pb-3 font-medium text-muted text-right w-28">На складе</th>
                            <th class="pb-3 font-medium text-muted text-right w-28">Продано (шт)</th>
                            <th class="pb-3 font-medium text-muted text-right w-32">Выручка (р.)</th>
                            <th class="pb-3 w-32"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="product in productList" :key="product.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
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
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Create product modal ── -->
        <div v-if="createModal" class="modal-backdrop" @click.self="createModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-4">Добавить товар</h2>
                <div class="space-y-3">
                    <div>
                        <label class="label">Название</label>
                        <input v-model="createForm.name" class="input" placeholder="Наименование товара" />
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
                    <p v-if="createError" class="text-xs text-red-600">{{ createError }}</p>
                </div>
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="createModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving" @click="createProduct">
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
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="intakeModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving || !intakeDelta" @click="saveIntake">
                        {{ saving ? 'Сохраняю…' : 'Оприходовать' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    products:  { type: Array,   default: () => [] },
    counted:   { type: Object,  default: () => ({}) },
    srEnabled: { type: Boolean, default: false },
})

// ── State ─────────────────────────────────────────────────────────────────────
const productList = ref([...props.products])
const editing     = ref(null)
const editForm    = ref({ name: '', weight: 0, sr_item_id: null })
const saving      = ref(false)

// Create modal
const createModal = ref(false)
const createForm  = ref({ name: '', weight: 0, stock: 0, sr_item_id: null })
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
function startEdit(product) {
    editing.value  = product.id
    editForm.value = { name: product.name, weight: product.weight ?? 0, sr_item_id: product.sr_item_id ?? null }
}

function cancelEdit() {
    editing.value = null
}

async function saveEdit(product) {
    saving.value = true
    try {
        const resp = await apiFetch(`/products/${product.id}`, 'PUT', {
            name:       editForm.value.name,
            weight:     editForm.value.weight,
            sr_item_id: editForm.value.sr_item_id || null,
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
function openCreateModal() {
    createForm.value  = { name: '', weight: 0, stock: 0, sr_item_id: null }
    createError.value = ''
    createModal.value = true
}

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

function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

async function apiFetch(path, method, body) {
    return fetch(path, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    })
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
