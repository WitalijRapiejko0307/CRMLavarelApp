<template>
    <div class="md:hidden">
        <!-- Burger button -->
        <button
            type="button"
            class="touch-target inline-flex items-center justify-center -ml-2 rounded-md text-white/90 hover:text-white transition-colors"
            :aria-expanded="isOpen"
            aria-label="Меню"
            @click="toggle"
        >
            <svg v-if="!isOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

        <Teleport to="body">
            <Transition name="fade">
                <div v-if="isOpen" class="nav-drawer-overlay" @click="close" />
            </Transition>
            <Transition name="slide">
                <aside
                    v-if="isOpen"
                    class="nav-drawer-panel"
                    :class="props.theme === 'slate' ? 'bg-slate-800 dark:bg-slate-950' : 'bg-indigo-700 dark:bg-indigo-950'"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="`Меню — ${title}`"
                >
                    <div class="flex items-center justify-between h-14 px-4 border-b border-white/10 shrink-0">
                        <span class="text-lg font-bold tracking-tight truncate">{{ title }}</span>
                        <button
                            type="button"
                            class="touch-target inline-flex items-center justify-center -mr-2 text-white/80 hover:text-white transition-colors"
                            aria-label="Закрыть меню"
                            @click="close"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <nav class="flex-1 overflow-y-auto py-2">
                        <Link
                            v-for="link in links"
                            :key="link.href"
                            :href="link.href"
                            class="touch-target flex items-center px-4 text-base font-medium transition-colors"
                            :class="link.active ? 'text-white bg-white/10' : 'text-indigo-200 hover:text-white hover:bg-white/5'"
                            @click="close"
                        >
                            {{ link.label }}
                        </Link>
                    </nav>

                    <div class="border-t border-white/10 py-2 shrink-0">
                        <slot name="actions" />
                    </div>
                </aside>
            </Transition>
        </Teleport>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'

const props = defineProps({
    title: { type: String, default: '' },
    links: { type: Array, default: () => [] },
    theme: { type: String, default: 'indigo', validator: v => ['indigo', 'slate'].includes(v) },
})

const isOpen = ref(false)

function toggle() {
    isOpen.value ? close() : open()
}

function open() {
    isOpen.value = true
}

function close() {
    isOpen.value = false
}

function onKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) close()
}

watch(isOpen, (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
})

let removeNavigateListener = null

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    removeNavigateListener = Inertia.on('navigate', close)
})

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    removeNavigateListener?.()
    document.body.style.overflow = ''
})

defineExpose({ close })
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
    transition: transform 0.25s ease;
}
.slide-enter-from,
.slide-leave-to {
    transform: translateX(-100%);
}
</style>
