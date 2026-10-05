<template>
    <div class="space-y-3">
        <div v-if="!pickedItem" class="relative" ref="wrapperRef">
            <label class="label">Номер отделения или адрес (мин. 2 символа)</label>
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
                    placeholder="777 или Гродно Купалы…"
                    autocomplete="off"
                    @input="onInput"
                    @keydown="onKeydown"
                    @focus="dropdownVisible = items.length > 0"
                />
            </div>

            <div
                v-if="dropdownVisible && (items.length > 0 || loading || error || hasSearched)"
                class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl
                       max-h-64 overflow-y-auto"
            >
                <div v-if="loading" class="flex items-center gap-2 px-4 py-3 text-sm text-muted">
                    <svg class="w-4 h-4 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Поиск…
                </div>

                <div v-else-if="error" class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>

                <div v-else-if="hasSearched && items.length === 0"
                     class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500 text-center">
                    По запросу «{{ query }}» ничего не найдено
                </div>

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
                            <span class="text-indigo-700 dark:text-indigo-400">{{ item.label }}</span>
                        </div>
                        <div v-if="item.ops_name" class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                            {{ item.ops_name }}
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <template v-else>
            <div>
                <label class="label">Отделение</label>
                <div class="mt-1 flex items-center gap-2">
                    <div class="flex-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                        {{ pickedLabel }}
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
            <div>
                <label class="label">Город</label>
                <div class="mt-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                    {{ cityValue }}
                </div>
            </div>
            <div>
                <label class="label">Улица</label>
                <div class="mt-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                    {{ streetValue }}
                </div>
            </div>
            <div>
                <label class="label">Дом</label>
                <div class="mt-1 px-3 py-2 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-sm text-body truncate">
                    {{ buildingValue || '—' }}
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    city:               { type: String, default: '' },
    street:             { type: String, default: '' },
    building:           { type: String, default: '' },
    opsId:              { type: [String, Number], default: '' },
    europochtaStoreId:  { type: [Number, String], default: null },
})

const emit = defineEmits([
    'update:city',
    'update:street',
    'update:building',
    'update:opsId',
    'update:europochtaStoreId',
])

const query           = ref('')
const items           = ref([])
const loading         = ref(false)
const error           = ref('')
const hasSearched     = ref(false)
const activeIndex     = ref(-1)
const dropdownVisible = ref(false)

const inputRef   = ref(null)
const wrapperRef = ref(null)

const pickedItem    = ref(null)
const cityValue     = ref(props.city)
const streetValue   = ref(props.street)
const buildingValue = ref(props.building)

let debounceTimer = null

const pickedLabel = computed(() => {
    const item = pickedItem.value
    if (item && item.label) return item.label
    const ops = props.opsId || item?.ops_number
    if (ops) return `ОПС №${ops}`
    return [cityValue.value, streetValue.value, buildingValue.value].filter(Boolean).join(', ')
})

onMounted(() => {
    if (props.europochtaStoreId && props.city) {
        pickedItem.value = {
            id:         Number(props.europochtaStoreId),
            ops_number: String(props.opsId || ''),
            city:       props.city,
            street:     props.street,
            house:      props.building,
            label:      props.opsId
                ? `ОПС ${props.opsId} · ${[props.city, props.street, props.building].filter(Boolean).join(', ')}`
                : '',
        }
        cityValue.value     = props.city
        streetValue.value   = props.street
        buildingValue.value = props.building
    }
    document.addEventListener('click', onOutsideClick)
})

onBeforeUnmount(() => {
    clearTimeout(debounceTimer)
    document.removeEventListener('click', onOutsideClick)
})

function onOutsideClick(e) {
    if (wrapperRef.value && !wrapperRef.value.contains(e.target)) {
        dropdownVisible.value = false
    }
}

function onInput() {
    clearTimeout(debounceTimer)
    error.value       = ''
    activeIndex.value = -1
    hasSearched.value = false

    if (query.value.trim().length < 2) {
        items.value           = []
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
            `/api/europochta/stores/search?q=${encodeURIComponent(query.value.trim())}`,
            {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            }
        )
        const data = await resp.json().catch(() => ({}))
        if (!resp.ok) {
            throw new Error(data.message || `HTTP ${resp.status}`)
        }
        items.value           = data.items ?? []
        hasSearched.value     = true
        dropdownVisible.value = true
    } catch (e) {
        error.value = e.message || 'Ошибка поиска отделений'
        items.value = []
    } finally {
        loading.value = false
    }
}

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

function pickItem(item) {
    dropdownVisible.value = false
    pickedItem.value      = item
    cityValue.value       = item.city || ''
    streetValue.value     = item.street || ''
    buildingValue.value   = item.house || ''

    emit('update:city', cityValue.value)
    emit('update:street', streetValue.value)
    emit('update:building', buildingValue.value)
    emit('update:opsId', String(item.ops_number ?? ''))
    emit('update:europochtaStoreId', item.id ?? null)
}

function reset() {
    pickedItem.value      = null
    query.value           = ''
    items.value           = []
    hasSearched.value     = false
    dropdownVisible.value = false
    cityValue.value       = ''
    streetValue.value     = ''
    buildingValue.value   = ''

    emit('update:city', '')
    emit('update:street', '')
    emit('update:building', '')
    emit('update:opsId', '')
    emit('update:europochtaStoreId', null)
}
</script>
