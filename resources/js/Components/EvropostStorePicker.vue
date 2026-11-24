<template>
    <div class="space-y-1" ref="wrapperRef">
        <div v-if="id && !editing" class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
            <span class="text-sm text-gray-800 dark:text-gray-200">{{ label || `ОПС ${id}` }}</span>
            <button v-if="!disabled" type="button" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline" @click="startEdit">
                Изменить
            </button>
        </div>

        <div v-else class="relative">
            <input
                ref="inputRef"
                v-model="query"
                type="text"
                class="input"
                :placeholder="placeholder"
                :disabled="disabled"
                autocomplete="off"
                @input="onInput"
                @keydown="onKeydown"
            />
            <div
                v-if="dropdownVisible"
                class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-xl max-h-64 overflow-y-auto"
            >
                <div v-if="loading" class="px-4 py-3 text-sm text-muted">Поиск…</div>
                <div v-else-if="error" class="px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error }}</div>
                <div v-else-if="items.length === 0" class="px-4 py-3 text-sm text-gray-400 dark:text-gray-500 text-center">
                    По запросу «{{ query }}» ничего не найдено
                </div>
                <ul v-else>
                    <li
                        v-for="(item, idx) in items"
                        :key="item.id ?? idx"
                        :class="[
                            'px-4 py-2.5 cursor-pointer text-sm border-b border-gray-50 dark:border-gray-700 last:border-0',
                            activeIndex === idx ? 'bg-indigo-50 dark:bg-indigo-900/40' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50',
                        ]"
                        @click="pick(item)"
                        @mouseenter="activeIndex = idx"
                    >
                        <span class="font-medium text-indigo-700 dark:text-indigo-400">{{ item.label }}</span>
                        <div v-if="item.ops_name" class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ item.ops_name }}</div>
                    </li>
                </ul>
            </div>
            <button v-if="id && editing" type="button" class="mt-1 text-xs text-gray-500 hover:underline" @click="editing = false">
                Отмена
            </button>
        </div>
    </div>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Single Europochta OPS picker: emits only the store id and a display label.
 * Used for the sender OPS (settings, order form).
 */
const props = defineProps({
    id:          { type: [Number, String], default: null },
    label:       { type: String, default: '' },
    placeholder: { type: String, default: '777 или Гродно Купалы…' },
    disabled:    { type: Boolean, default: false },
})

const emit = defineEmits(['update:id', 'update:label'])

const query           = ref('')
const items           = ref([])
const loading         = ref(false)
const error           = ref('')
const activeIndex     = ref(-1)
const dropdownVisible = ref(false)
const editing         = ref(false)
const wrapperRef      = ref(null)
const inputRef        = ref(null)

let debounceTimer = null

function onOutsideClick(e) {
    if (wrapperRef.value && !wrapperRef.value.contains(e.target)) dropdownVisible.value = false
}

onMounted(() => document.addEventListener('click', onOutsideClick))
onBeforeUnmount(() => {
    clearTimeout(debounceTimer)
    document.removeEventListener('click', onOutsideClick)
})

function startEdit() {
    editing.value = true
    query.value   = ''
    items.value   = []
    nextTick(() => inputRef.value && inputRef.value.focus())
}

function onInput() {
    clearTimeout(debounceTimer)
    error.value       = ''
    activeIndex.value = -1
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
        if (!resp.ok) throw new Error(data.message || `HTTP ${resp.status}`)
        items.value = data.items ?? []
    } catch (e) {
        error.value = e.message || 'Ошибка поиска отделений'
        items.value = []
    } finally {
        loading.value = false
    }
}

function onKeydown(e) {
    if (!dropdownVisible.value) return
    const n = Math.max(items.value.length, 1)
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value + 1) % n
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activeIndex.value = (activeIndex.value - 1 + n) % n
    } else if (e.key === 'Enter' && activeIndex.value >= 0 && items.value[activeIndex.value]) {
        e.preventDefault()
        pick(items.value[activeIndex.value])
    } else if (e.key === 'Escape') {
        dropdownVisible.value = false
    }
}

function pick(item) {
    dropdownVisible.value = false
    editing.value         = false
    query.value           = ''
    emit('update:id', item.id ?? null)
    emit('update:label', item.label || '')
}
</script>
