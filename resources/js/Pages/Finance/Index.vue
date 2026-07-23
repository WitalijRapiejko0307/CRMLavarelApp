<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h1 class="page-title">Финансы</h1>

                <div class="flex items-center gap-2">
                    <button class="btn-secondary btn-sm" @click="prevMonth">&#8249;</button>
                    <input
                        type="month"
                        v-model="currentMonth"
                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:focus:ring-indigo-500"
                        @change="loadMonth"
                    />
                    <button class="btn-secondary btn-sm" @click="nextMonth">&#8250;</button>
                </div>
            </div>
        </template>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="card py-4">
                <p class="stat-label">Доходы</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ fmt(totalIncome) }}</p>
            </div>
            <div class="card py-4">
                <p class="stat-label">Расходы</p>
                <p class="text-2xl font-bold text-red-500 dark:text-red-400 mt-1">{{ fmt(totalExpenses) }}</p>
            </div>
            <div class="card py-4 col-span-2">
                <p class="stat-label">Прибыль</p>
                <p class="text-2xl font-bold mt-1" :class="profit >= 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-red-600 dark:text-red-400'">
                    {{ fmt(profit) }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- ── Expenses ── -->
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="section-title">Расходы</h2>
                <button v-if="!readOnly" class="btn-primary btn-sm" @click="openExpenseModal">+ Добавить</button>
                </div>

                <!-- Category summary -->
                <div v-if="Object.keys(byCat).length" class="mb-4 flex flex-wrap gap-2">
                    <span
                        v-for="(sum, cat) in byCat" :key="cat"
                        class="text-xs bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 rounded-full px-2 py-0.5"
                    >
                        {{ cat }}: <strong>{{ fmt(sum) }}</strong>
                    </span>
                </div>

                <div v-if="expenseList.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">
                    Нет расходов за этот месяц
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="pb-2 font-medium text-muted">Дата</th>
                                <th class="pb-2 font-medium text-muted">Категория</th>
                                <th class="pb-2 font-medium text-muted">Описание</th>
                                <th class="pb-2 font-medium text-muted text-right">Сумма</th>
                                <th class="pb-2 w-8"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="exp in expenseList" :key="exp.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-2 text-muted whitespace-nowrap">{{ fmtDate(exp.date) }}</td>
                                <td class="py-2">
                                    <span class="text-xs bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200 rounded px-1.5 py-0.5">{{ exp.category ?? '—' }}</span>
                                </td>
                                <td class="py-2 text-gray-600 dark:text-gray-400 max-w-[160px] truncate">{{ exp.description ?? '—' }}</td>
                                <td class="py-2 text-right font-medium text-red-500 dark:text-red-400">{{ fmt(exp.amount) }}</td>
                                <td class="py-2 text-right">
                                    <button
                                        class="text-gray-300 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                                        title="Удалить"
                                        @click="deleteExpense(exp)"
                                    >✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Income ── -->
            <div class="card">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="section-title">Доходы</h2>
                <button v-if="!readOnly" class="btn-primary btn-sm" @click="openIncomeModal">+ Добавить</button>
                </div>

                <div v-if="incomeListData.length === 0" class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm">
                    Нет доходов за этот месяц
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="pb-2 font-medium text-muted">Дата</th>
                                <th class="pb-2 font-medium text-muted">Источник</th>
                                <th class="pb-2 font-medium text-muted">Описание</th>
                                <th class="pb-2 font-medium text-muted text-right">Сумма</th>
                                <th class="pb-2 w-8"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="inc in incomeListData" :key="inc.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-2 text-muted whitespace-nowrap">{{ fmtDate(inc.date) }}</td>
                                <td class="py-2">
                                    <span v-if="inc.source" class="text-xs bg-green-50 text-green-700 dark:bg-green-900/40 dark:text-green-200 rounded px-1.5 py-0.5">{{ inc.source }}</span>
                                    <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                                </td>
                                <td class="py-2 text-gray-600 dark:text-gray-400 max-w-[160px] truncate">{{ inc.description ?? '—' }}</td>
                                <td class="py-2 text-right font-medium text-green-600 dark:text-green-400">{{ fmt(inc.amount) }}</td>
                                <td class="py-2 text-right">
                                    <button
                                        class="text-gray-300 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                                        title="Удалить"
                                        @click="deleteIncome(inc)"
                                    >✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Add Expense Modal ── -->
        <div v-if="expenseModal" class="modal-backdrop" @click.self="expenseModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-4">Добавить расход</h2>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Сумма (р.)</label>
                            <input v-model.number="expForm.amount" type="number" min="0.01" step="0.01" class="input" />
                        </div>
                        <div>
                            <label class="label">Дата</label>
                            <input v-model="expForm.date" type="date" class="input" />
                        </div>
                    </div>
                    <div>
                        <label class="label">Категория</label>
                        <select v-model="expForm.category" class="input">
                            <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Описание</label>
                        <input v-model="expForm.description" class="input" placeholder="Необязательно" />
                    </div>
                    <p v-if="formError" class="text-xs text-red-600">{{ formError }}</p>
                </div>
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="expenseModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving" @click="saveExpense">
                        {{ saving ? 'Сохраняю…' : 'Добавить' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Add Income Modal ── -->
        <div v-if="incomeModal" class="modal-backdrop" @click.self="incomeModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-4">Добавить доход</h2>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Сумма (р.)</label>
                            <input v-model.number="incForm.amount" type="number" min="0.01" step="0.01" class="input" />
                        </div>
                        <div>
                            <label class="label">Дата</label>
                            <input v-model="incForm.date" type="date" class="input" />
                        </div>
                    </div>
                    <div>
                        <label class="label">Источник</label>
                        <input v-model="incForm.source" class="input" placeholder="Напр. магазин, партнёр…" />
                    </div>
                    <div>
                        <label class="label">Описание</label>
                        <input v-model="incForm.description" class="input" placeholder="Необязательно" />
                    </div>
                    <p v-if="formError" class="text-xs text-red-600">{{ formError }}</p>
                </div>
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="incomeModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving" @click="saveIncome">
                        {{ saving ? 'Сохраняю…' : 'Добавить' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useSubscription } from '@/composables/useSubscription'
import { apiFetch } from '@/utils/api'

const { readOnly } = useSubscription()

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    expenses:      { type: Array,  default: () => [] },
    incomeList:    { type: Array,  default: () => [] },
    totalExpenses: { type: Number, default: 0 },
    totalIncome:   { type: Number, default: 0 },
    profit:        { type: Number, default: 0 },
    byCategory:    { type: Object, default: () => ({}) },
    categories:    { type: Array,  default: () => [] },
    month:         { type: String, default: '' },
})

// ── State ─────────────────────────────────────────────────────────────────────
const expenseList   = ref([...props.expenses])
const incomeListData = ref([...props.incomeList])
const currentMonth  = ref(props.month)
const byCat         = ref({ ...props.byCategory })

const expenseModal = ref(false)
const incomeModal  = ref(false)
const saving       = ref(false)
const formError    = ref('')

const todayStr = new Date().toISOString().slice(0, 10)
const expForm = ref({ amount: '', date: todayStr, category: props.categories[0] ?? '', description: '' })
const incForm = ref({ amount: '', date: todayStr, source: '', description: '' })

// ── Month navigation ─────────────────────────────────────────────────────────
function loadMonth() {
    Inertia.get('/finances', { month: currentMonth.value }, { preserveState: false })
}

function prevMonth() {
    const [y, m] = currentMonth.value.split('-').map(Number)
    const d = new Date(y, m - 2, 1)
    currentMonth.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    loadMonth()
}

function nextMonth() {
    const [y, m] = currentMonth.value.split('-').map(Number)
    const d = new Date(y, m, 1)
    currentMonth.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    loadMonth()
}

// ── Expense CRUD ──────────────────────────────────────────────────────────────
function openExpenseModal() {
    expForm.value = { amount: '', date: todayStr, category: props.categories[0] ?? '', description: '' }
    formError.value = ''
    expenseModal.value = true
}

async function saveExpense() {
    formError.value = ''
    if (!expForm.value.amount || expForm.value.amount <= 0) {
        formError.value = 'Введите сумму'
        return
    }
    saving.value = true
    try {
        const resp = await apiFetch('/finances/expenses', 'POST', expForm.value)
        const data = await resp.json()
        if (data.success) {
            expenseList.value.unshift(data.expense)
            recalcByCat()
            expenseModal.value = false
        } else {
            formError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

async function deleteExpense(exp) {
    if (!confirm('Удалить расход?')) return
    const resp = await apiFetch(`/finances/expenses/${exp.id}`, 'DELETE')
    const data = await resp.json()
    if (data.success) {
        expenseList.value = expenseList.value.filter(e => e.id !== exp.id)
        recalcByCat()
    }
}

// ── Income CRUD ────────────────────────────────────────────────────────────────
function openIncomeModal() {
    incForm.value = { amount: '', date: todayStr, source: '', description: '' }
    formError.value = ''
    incomeModal.value = true
}

async function saveIncome() {
    formError.value = ''
    if (!incForm.value.amount || incForm.value.amount <= 0) {
        formError.value = 'Введите сумму'
        return
    }
    saving.value = true
    try {
        const resp = await apiFetch('/finances/income', 'POST', incForm.value)
        const data = await resp.json()
        if (data.success) {
            incomeListData.value.unshift(data.income)
            incomeModal.value = false
        } else {
            formError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

async function deleteIncome(inc) {
    if (!confirm('Удалить запись о доходе?')) return
    const resp = await apiFetch(`/finances/income/${inc.id}`, 'DELETE')
    const data = await resp.json()
    if (data.success) {
        incomeListData.value = incomeListData.value.filter(i => i.id !== inc.id)
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function recalcByCat() {
    const map = {}
    expenseList.value.forEach(e => {
        const k = e.category ?? 'Прочее'
        map[k] = (map[k] ?? 0) + Number(e.amount)
    })
    byCat.value = map
}

function fmt(value) {
    return Number(value ?? 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtDate(dateStr) {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return `${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}`
}
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
</style>
