<template>
    <div ref="rootRef" class="relative" @click.stop @mousedown.stop>
        <button
            ref="triggerRef"
            type="button"
            :disabled="disabled"
            :class="triggerClass"
            :aria-expanded="isOpen"
            aria-haspopup="listbox"
            @click="toggle"
            @keydown="onTriggerKeydown"
        >
            <span class="truncate">{{ displayLabel }}</span>
            <svg class="w-4 h-4 shrink-0 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <Teleport to="body">
            <ul
                v-if="isOpen"
                ref="menuRef"
                tabindex="-1"
                role="listbox"
                :style="menuStyle"
                class="rounded-md border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 shadow-lg py-1 text-sm focus:outline-none overscroll-contain"
                @keydown="onMenuKeydown"
            >
                <li
                    v-for="(option, index) in normalizedOptions"
                    :key="String(option.value)"
                    role="option"
                    :aria-selected="option.value === modelValue"
                    :class="[
                        'px-3 py-1.5 cursor-pointer truncate',
                        index === highlightedIndex
                            ? 'bg-indigo-50 dark:bg-indigo-900/40'
                            : 'hover:bg-gray-50 dark:hover:bg-gray-700',
                        option.value === modelValue && 'font-medium',
                        optionClassFn?.(option.value),
                    ]"
                    @mousedown.prevent="selectOption(option.value)"
                    @mouseenter="highlightedIndex = index"
                >
                    {{ option.label }}
                </li>
            </ul>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted, nextTick } from 'vue'
import { useDropdownPosition } from '@/composables/useDropdownPosition'
import { registerScrollSelectOpen, unregisterScrollSelectClose } from '@/utils/scrollSelectRegistry'

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: '— выберите —' },
    size: { type: String, default: 'md', validator: v => ['sm', 'md'].includes(v) },
    minWidth: { type: Number, default: 160 },
    maxHeight: { type: Number, default: 240 },
    emptyOption: { type: Object, default: null },
    optionClassFn: { type: Function, default: null },
})

const emit = defineEmits(['update:modelValue', 'change'])

const rootRef = ref(null)
const triggerRef = ref(null)
const menuRef = ref(null)
const isOpen = ref(false)
const highlightedIndex = ref(0)

const { menuStyle, updatePosition, attachListeners, detachListeners } = useDropdownPosition(
    triggerRef,
    menuRef,
    { maxMenuHeight: props.maxHeight, minWidth: props.minWidth },
)

function normalizeOption(option) {
    if (typeof option === 'object' && option !== null) {
        return { value: option.value, label: option.label ?? String(option.value) }
    }
    return { value: option, label: String(option) }
}

const normalizedOptions = computed(() => {
    const items = props.options.map(normalizeOption)
    if (props.emptyOption) {
        return [normalizeOption(props.emptyOption), ...items]
    }
    return items
})

const displayLabel = computed(() => {
    const match = normalizedOptions.value.find(o => o.value === props.modelValue)
    if (match) return match.label
    if (props.modelValue === '' || props.modelValue == null) {
        return props.placeholder
    }
    return String(props.modelValue)
})

const triggerClass = computed(() => {
    const base = 'w-full flex items-center justify-between gap-1 text-left border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:outline-none focus:ring-1 disabled:opacity-50 disabled:cursor-not-allowed'
    return props.size === 'sm'
        ? `${base} text-xs py-1 px-2 max-w-[140px]`
        : `${base} text-sm px-3 py-2`
})

function findIndexByValue(value) {
    return normalizedOptions.value.findIndex(o => o.value === value)
}

function close() {
    if (!isOpen.value) return
    isOpen.value = false
    detachListeners()
    document.removeEventListener('mousedown', onClickOutside)
    document.removeEventListener('keydown', onDocumentKeydown)
    unregisterScrollSelectClose(close)
}

function open() {
    if (props.disabled || isOpen.value) return
    registerScrollSelectOpen(close)
    isOpen.value = true
    const idx = findIndexByValue(props.modelValue)
    highlightedIndex.value = idx >= 0 ? idx : 0
    nextTick(() => {
        attachListeners(close)
        menuRef.value?.focus({ preventScroll: true })
    })
    document.addEventListener('mousedown', onClickOutside)
    document.addEventListener('keydown', onDocumentKeydown)
}

function toggle() {
    if (isOpen.value) close()
    else open()
}

function selectOption(value) {
    if (value !== props.modelValue) {
        emit('update:modelValue', value)
        emit('change', value)
    }
    close()
}

function onClickOutside(event) {
    if (rootRef.value?.contains(event.target) || menuRef.value?.contains(event.target)) return
    close()
}

function onDocumentKeydown(event) {
    if (event.key === 'Escape') close()
}

function onTriggerKeydown(event) {
    if (props.disabled) return
    if (!['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) return
    event.preventDefault()

    if (!isOpen.value) {
        open()
        return
    }

    const count = normalizedOptions.value.length
    if (event.key === 'ArrowDown') {
        highlightedIndex.value = (highlightedIndex.value + 1) % count
    } else if (event.key === 'ArrowUp') {
        highlightedIndex.value = (highlightedIndex.value - 1 + count) % count
    } else {
        const option = normalizedOptions.value[highlightedIndex.value]
        if (option) selectOption(option.value)
    }
}

function onMenuKeydown(event) {
    const count = normalizedOptions.value.length
    if (!count) return

    if (event.key === 'ArrowDown') {
        event.preventDefault()
        highlightedIndex.value = (highlightedIndex.value + 1) % count
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        highlightedIndex.value = (highlightedIndex.value - 1 + count) % count
    } else if (event.key === 'Enter') {
        event.preventDefault()
        const option = normalizedOptions.value[highlightedIndex.value]
        if (option) selectOption(option.value)
    }
}

watch(() => props.maxHeight, () => {
    if (isOpen.value) updatePosition()
})

onUnmounted(close)
</script>
