<template>
    <div class="space-y-3">
        <!-- ── Search field (hidden once street is picked) ── -->
        <div v-if="!pickedItem" class="relative" ref="wrapperRef">
            <label class="label">Поиск адреса Белпочты</label>
            <div class="relative mt-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    ref="inputRef"
                    v-model="query"
                    type="text"
                    class="input pl-10 pr-4 py-2 mt-1"
                    placeholder="Минск ул. Ленина… (мин. 3 символа)"
                    autocomplete="off"
                    @input="onInput"
                    @keydown="onKeydown"
                    @focus="dropdownVisible = items.length > 0"
                />
            </div>

            <!-- Dropdown -->
            <div
                v-if="dropdownVisible && (items.length > 0 || loading || error || hasSearched)"
                class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl
                       max-h-64 overflow-y-auto"
            >
                <!-- Loading -->
                <div v-if="loading" class="flex items-center gap-2 px-4 py-3 text-sm text-muted">
                    <svg class="w-4 h-4 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Поиск…
                </div>

                <!-- Error -->
                <div v-else-if="error" class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>

                <!-- Empty -->
                <div v-else-if="hasSearched && items.length === 0"
                     class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500 text-center">
                    По запросу «{{ query }}» ничего не найдено
                </div>

                <!-- Results -->
                <ul v-else>
                    <li
                        v-for="(item, idx) in items"
                        :key="item.id ?? idx"
                        :class="[
                            'px-4 py-2.5 cursor-pointer text-sm transition-colors border-b border-gray-50 dark:border-gray-700 last:border-0',
                            activeIndex === idx ? 'bg-indigo-50 dark:bg-indigo-900/40' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50',
                        ]"
                        @click="pickItem(item)"
                        @mouseenter="activeIndex = idx"
                    >
                        <div class="font-medium text-gray-800 dark:text-gray-200">
                            <span class="text-indigo-700 dark:text-indigo-400">{{ item.city_type }} {{ item.city }}</span>,
                            {{ item.street_type }} {{ item.street }}
                            <span v-if="item.district" class="text-gray-400 dark:text-gray-500 text-xs font-normal ml-1">({{ item.district }})</span>
                        </div>
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            {{ item.postcode }} •
                            {{ item.params_null ? 'все дома' : 'дома: ' + item.houses.join(', ') }}
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- ── Picked state: readonly city/street + house select ── -->
        <template v-else>
            <!-- City (readonly) -->
            <div>
                <label class="label">Город</label>
                <div class="mt-1 flex items-center gap-2">
                    <div class="flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                        {{ cityValue }}
                    </div>
                </div>
            </div>

            <!-- Street (readonly) -->
            <div>
                <label class="label">Улица</label>
                <div class="mt-1 flex items-center gap-2">
                    <div class="flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                        {{ streetValue }}
                    </div>
                    <button
                        type="button"
                        class="flex-shrink-0 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 underline whitespace-nowrap"
                        @click="reset"
                    >
                        Сбросить
                    </button>
                </div>
            </div>

            <!-- House: select or free input -->
            <div>
                <label class="label">
                    Дом
                    <span v-if="!pickedItem.params_null" class="text-red-400 ml-0.5">*</span>
                </label>

                <!-- House list from directory -->
                <select
                    v-if="!pickedItem.params_null"
                    v-model="localBuilding"
                    class="w-full mt-1"
                    :class="{ 'border-red-400 focus:ring-red-400': buildingError }"
                    @change="emitBuilding"
                >
                    <option value="">— выберите дом —</option>
                    <option v-for="h in pickedItem.houses" :key="h" :value="h">{{ h }}</option>
                </select>

                <!-- Free input (params_null) -->
                <input
                    v-else
                    v-model="localBuilding"
                    type="text"
                    class="w-full mt-1"
                    placeholder="Номер дома"
                    @input="emitBuilding"
                />

                <p v-if="buildingError" class="mt-1 text-xs text-red-500">{{ buildingError }}</p>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'

// ── Props ─────────────────────────────────────────────────────────────────
const props = defineProps({
    city:             { type: String, default: '' },
    street:           { type: String, default: '' },
    building:         { type: String, default: '' },
    belpostAddressId: { type: String, default: '' },
    /** Pre-fill search field on mount */
    initialQuery:     { type: String, default: '' },
})

const emit = defineEmits([
    'update:city',
    'update:street',
    'update:building',
    'update:belpostAddressId',
])

// ── Exposed for parent validation ─────────────────────────────────────────
const buildingError = ref('')

function validate() {
    if (pickedItem.value && !pickedItem.value.params_null && !localBuilding.value) {
        buildingError.value = 'Выберите номер дома из справочника'
        return false
    }
    buildingError.value = ''
    return true
}

defineExpose({ validate })

// ── Search state ──────────────────────────────────────────────────────────
const query          = ref(props.initialQuery)
const items          = ref([])
const loading        = ref(false)
const error          = ref('')
const hasSearched    = ref(false)
const activeIndex    = ref(-1)
const dropdownVisible = ref(false)

const inputRef   = ref(null)
const wrapperRef = ref(null)

let debounceTimer = null

// ── Picked state ──────────────────────────────────────────────────────────
const pickedItem     = ref(null)
const cityValue      = ref(props.city)
const streetValue    = ref(props.street)
const localBuilding  = ref(props.building)

// If the parent already has a belpostAddressId the order was previously picked —
// show the readonly address without triggering a new search.
onMounted(() => {
    if (props.belpostAddressId && props.city && props.street) {
        pickedItem.value = {
            id:         props.belpostAddressId,
            params_null: true,  // allow free input; real houses list is unknown here
            houses:     [],
        }
        cityValue.value   = props.city
        streetValue.value = props.street
        localBuilding.value = props.building
    }
    document.addEventListener('click', onOutsideClick)
})

onBeforeUnmount(() => {
    clearTimeout(debounceTimer)
    document.removeEventListener('click', onOutsideClick)
})

// ── Outside-click closes dropdown ─────────────────────────────────────────
function onOutsideClick(e) {
    if (wrapperRef.value && !wrapperRef.value.contains(e.target)) {
        dropdownVisible.value = false
    }
}

// ── Search ────────────────────────────────────────────────────────────────
function onInput() {
    clearTimeout(debounceTimer)
    error.value       = ''
    activeIndex.value = -1
    hasSearched.value = false

    if (query.value.trim().length < 3) {
        items.value       = []
        dropdownVisible.value = false
        return
    }

    loading.value         = true
    dropdownVisible.value = true
    debounceTimer         = setTimeout(doSearch, 600)
}

async function doSearch() {
    loading.value = true
    error.value   = ''

    try {
        const resp = await fetch(
            `/api/address/search?q=${encodeURIComponent(query.value.trim())}`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
        )
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`)
        const data    = await resp.json()
        items.value   = data.items ?? []
        hasSearched.value  = true
        dropdownVisible.value = true
    } catch (e) {
        error.value = 'Ошибка поиска: ' + e.message
        items.value = []
    } finally {
        loading.value = false
    }
}

// ── Keyboard ──────────────────────────────────────────────────────────────
function onKeydown(e) {
    if (!dropdownVisible.value) return

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value + 1) % Math.max(items.value.length, 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value - 1 + items.value.length) % Math.max(items.value.length, 1)
    } else if (e.key === 'Enter' && activeIndex.value >= 0) {
        e.preventDefault()
        if (items.value[activeIndex.value]) pickItem(items.value[activeIndex.value])
    } else if (e.key === 'Escape') {
        dropdownVisible.value = false
    }
}

// ── Select item ───────────────────────────────────────────────────────────
function pickItem(item) {
    dropdownVisible.value = false

    // Build GAS-format strings (mirrors ScSA.gs setSelectedAddress)
    const city   = `${item.postcode} ${item.city_type} ${item.city}`.trim()
    const street = `${item.street_type} ${item.street}`.trim()

    cityValue.value   = city
    streetValue.value = street
    pickedItem.value  = item

    // Prefill building if current value is in houses list (normalised match)
    const normalised = (props.building ?? '').toLowerCase().trim()
    const match = !item.params_null
        && item.houses.find(h => h.toLowerCase().trim() === normalised)

    localBuilding.value = match || ''

    // Emit immediately
    emit('update:city',             city)
    emit('update:street',           street)
    emit('update:belpostAddressId', String(item.id))
    emit('update:building',         localBuilding.value)

    buildingError.value = ''
}

// ── Emit building change ──────────────────────────────────────────────────
function emitBuilding() {
    buildingError.value = ''
    emit('update:building', localBuilding.value)
}

// ── Reset ─────────────────────────────────────────────────────────────────
function reset() {
    pickedItem.value      = null
    query.value           = ''
    items.value           = []
    hasSearched.value     = false
    localBuilding.value   = ''
    dropdownVisible.value = false
    buildingError.value   = ''

    emit('update:belpostAddressId', '')
    // Keep city/street so user can still read them; they can type freely now
}
</script>
