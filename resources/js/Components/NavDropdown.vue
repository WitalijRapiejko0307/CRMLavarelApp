<template>
    <div class="relative" ref="rootEl">
        <button
            type="button"
            class="text-sm font-medium transition-colors hover:text-indigo-200 inline-flex items-center gap-1"
            :class="active ? 'text-white' : 'text-indigo-300'"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="toggle"
        >
            {{ label }}
            <svg class="w-3.5 h-3.5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div
            v-if="open"
            class="absolute left-0 mt-2 min-w-[11rem] rounded-md bg-white text-indigo-700 shadow-lg py-1 z-50"
            role="menu"
        >
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                class="block px-3 py-1.5 text-sm font-medium hover:bg-indigo-50"
                :class="itemActive(item.href) ? 'bg-indigo-50 text-indigo-900' : 'text-indigo-700'"
                role="menuitem"
                @click="close"
            >
                {{ item.label }}
            </Link>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'

const props = defineProps({
    label:  { type: String, required: true },
    active: { type: Boolean, default: false },
    items:  { type: Array, default: () => [] },
})

const open   = ref(false)
const rootEl = ref(null)

function toggle() {
    open.value = !open.value
}

function close() {
    open.value = false
}

function itemActive(href) {
    return window.location.pathname.startsWith(href)
}

function onDocumentClick(event) {
    if (!open.value) return
    if (rootEl.value && rootEl.value.contains(event.target)) return
    close()
}

function onKeydown(event) {
    if (event.key === 'Escape') close()
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick)
    document.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick)
    document.removeEventListener('keydown', onKeydown)
})
</script>
