<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/40 dark:bg-black/60" @click="close" />

            <!-- Modal -->
            <div class="relative z-10 bg-white dark:bg-gray-800 rounded-xl shadow-2xl dark:border dark:border-gray-700 w-full max-w-xl mx-4 flex flex-col max-h-[90vh]">
                <!-- Header -->
                <div class="flex items-start justify-between px-6 pt-5 pb-4 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Поиск адреса Белпочты</h2>
                        <p v-if="hint" class="mt-0.5 text-sm text-muted">{{ hint }}</p>
                    </div>
                    <button class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 -mt-0.5" @click="close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- ── Step 1: Street search ── -->
                <template v-if="step === 1">
                    <!-- Search input -->
                    <div class="px-6 pt-4 pb-2">
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            <input
                                ref="inputRef"
                                v-model="query"
                                type="text"
                                class="input pl-10 pr-4 py-2.5"
                                placeholder="Минск ул. Ленина…  (мин. 3 символа)"
                                autocomplete="off"
                                @input="onInput"
                                @keydown="onKeydown"
                            />
                        </div>
                    </div>

                    <!-- Results area -->
                    <div class="flex-1 overflow-y-auto px-6 pb-4" style="min-height: 120px;">
                        <!-- Loading -->
                        <div v-if="loading" class="flex items-center gap-2 py-6 text-sm text-muted">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Поиск…
                        </div>

                        <!-- Error -->
                        <div v-else-if="error" class="py-4 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/30 rounded-lg px-3 mt-2">
                            {{ error }}
                        </div>

                        <!-- Empty -->
                        <div v-else-if="hasSearched && items.length === 0" class="py-6 text-sm text-gray-400 dark:text-gray-500 text-center">
                            По запросу «{{ query }}» ничего не найдено
                        </div>

                        <!-- Items -->
                        <ul v-else-if="items.length > 0" class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                            <li
                                v-for="(item, idx) in items"
                                :key="item.id ?? idx"
                                :class="[
                                    'py-2.5 px-2 cursor-pointer rounded-lg text-sm transition-colors',
                                    activeIndex === idx ? 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-900 dark:text-indigo-200' : 'text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50',
                                ]"
                                @click="selectStreet(item)"
                                @mouseenter="activeIndex = idx"
                            >
                                <span class="font-medium text-indigo-700 dark:text-indigo-400">
                                    {{ item.city_type }} {{ item.city }}
                                </span>
                                <span class="text-muted">, {{ item.street_type }} {{ item.street }}</span>
                                <span v-if="item.district" class="text-gray-400 dark:text-gray-500 text-xs ml-1">({{ item.district }})</span>
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                                    {{ item.postcode }} •
                                    {{ item.params_null ? 'все дома' : ('дома: ' + item.houses.join(', ')) }}
                                </div>
                            </li>
                        </ul>

                        <!-- Hint before first search -->
                        <div v-else class="py-6 text-sm text-gray-400 dark:text-gray-500 text-center">
                            Начните вводить город и улицу для поиска
                        </div>
                    </div>
                </template>

                <!-- ── Step 2: House selection ── -->
                <template v-else-if="step === 2 && pendingStreet">
                    <div class="px-6 py-4 flex-1 overflow-y-auto space-y-4">
                        <!-- Picked street summary -->
                        <div class="bg-indigo-50 dark:bg-indigo-900/30 rounded-lg px-4 py-3 text-sm">
                            <div class="font-medium text-indigo-800 dark:text-indigo-200">
                                {{ pendingStreet.city_type }} {{ pendingStreet.city }},
                                {{ pendingStreet.street_type }} {{ pendingStreet.street }}
                            </div>
                            <div class="text-xs text-indigo-500 dark:text-indigo-400 mt-0.5">{{ pendingStreet.postcode }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Выберите номер дома
                                <span v-if="!pendingStreet.params_null" class="text-red-400">*</span>
                            </label>

                            <!-- House list from directory -->
                            <select
                                v-if="!pendingStreet.params_null"
                                v-model="selectedHouse"
                                class="w-full"
                            >
                                <option value="">— выберите дом —</option>
                                <option v-for="h in pendingStreet.houses" :key="h" :value="h">{{ h }}</option>
                            </select>

                            <!-- Free input (params_null) -->
                            <input
                                v-else
                                v-model="selectedHouse"
                                type="text"
                                class="w-full"
                                placeholder="Номер дома"
                                ref="houseInputRef"
                            />

                            <p v-if="houseError" class="mt-1 text-xs text-red-500">{{ houseError }}</p>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <button class="btn-secondary flex-1 justify-center" @click="step = 1">← Назад</button>
                            <button class="btn-primary flex-1 justify-center" @click="confirmHouse">Выбрать</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue'

const props = defineProps({
    open:    { type: Boolean, default: false },
    /** Optional hint shown under the title (e.g. "Заказ #12, Иван Иванов") */
    hint:    { type: String,  default: '' },
    /** Pre-fill the search with city + street from the order */
    initialQuery: { type: String, default: '' },
    /** If provided, pre-fill house field (from order.building) */
    initialBuilding: { type: String, default: '' },
})

const emit = defineEmits(['close', 'selected'])

// ── Step 1 state ──────────────────────────────────────────────────────────
const step        = ref(1)
const query       = ref('')
const items       = ref([])
const loading     = ref(false)
const error       = ref('')
const hasSearched = ref(false)
const activeIndex = ref(-1)
const inputRef    = ref(null)

// ── Step 2 state ──────────────────────────────────────────────────────────
const pendingStreet  = ref(null)
const selectedHouse  = ref('')
const houseError     = ref('')
const houseInputRef  = ref(null)

let debounceTimer = null

// ── Lifecycle ──────────────────────────────────────────────────────────────
watch(() => props.open, async (val) => {
    if (val) {
        step.value        = 1
        query.value       = props.initialQuery ?? ''
        items.value       = []
        hasSearched.value = false
        error.value       = ''
        activeIndex.value = -1
        pendingStreet.value = null
        selectedHouse.value = ''
        houseError.value  = ''

        await nextTick()
        inputRef.value?.focus()

        if (query.value.length >= 3) {
            doSearch()
        }
    }
})

// ── Search ─────────────────────────────────────────────────────────────────
function onInput() {
    clearTimeout(debounceTimer)
    error.value       = ''
    activeIndex.value = -1

    if (query.value.trim().length < 3) {
        items.value = []
        return
    }

    loading.value = true
    debounceTimer = setTimeout(doSearch, 600)
}

async function doSearch() {
    loading.value = true
    error.value   = ''

    try {
        const resp = await fetch(`/api/address/search?q=${encodeURIComponent(query.value.trim())}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })

        if (!resp.ok) {
            throw new Error(`HTTP ${resp.status}`)
        }

        const data    = await resp.json()
        items.value   = data.items ?? []
        hasSearched.value = true
    } catch (e) {
        error.value = 'Ошибка поиска: ' + e.message
        items.value = []
    } finally {
        loading.value = false
    }
}

// ── Keyboard navigation ────────────────────────────────────────────────────
function onKeydown(e) {
    if (!items.value.length) return

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value + 1) % items.value.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value - 1 + items.value.length) % items.value.length
    } else if (e.key === 'Enter' && activeIndex.value >= 0) {
        e.preventDefault()
        selectStreet(items.value[activeIndex.value])
    } else if (e.key === 'Escape') {
        close()
    }
}

// ── Step 1 → Step 2 ────────────────────────────────────────────────────────
async function selectStreet(item) {
    pendingStreet.value = item

    // Prefill house if initialBuilding matches one in list
    const normalised = (props.initialBuilding ?? '').toLowerCase().trim()
    const match = !item.params_null
        && item.houses.find(h => h.toLowerCase().trim() === normalised)
    selectedHouse.value = match || ''

    houseError.value = ''
    step.value = 2

    await nextTick()
    houseInputRef.value?.focus()
}

// ── Step 2: confirm house ─────────────────────────────────────────────────
function confirmHouse() {
    if (!pendingStreet.value.params_null && !selectedHouse.value) {
        houseError.value = 'Выберите номер дома из справочника'
        return
    }

    const item = pendingStreet.value
    emit('selected', {
        id:       item.id,
        building: selectedHouse.value,
        city:     `${item.postcode} ${item.city_type} ${item.city}`.trim(),
        street:   `${item.street_type} ${item.street}`.trim(),
    })
    close()
}

function close() {
    clearTimeout(debounceTimer)
    emit('close')
}
</script>
