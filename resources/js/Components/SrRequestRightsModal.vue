<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        @click.self="$emit('close')"
    >
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow-xl max-w-lg w-full p-5 space-y-3">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">Запрос прав у колл-центра</h3>
            <p class="text-sm text-muted">
                Скопируйте текст и отправьте его колл-центру. Токен в текст не попадает.
            </p>
            <textarea
                readonly
                class="input w-full h-64 text-sm font-mono"
                :value="text"
            />
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary btn-sm" @click="copy">
                    {{ copied ? 'Скопировано' : 'Копировать' }}
                </button>
                <button type="button" class="btn-secondary btn-sm" @click="$emit('close')">Закрыть</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    text: { type: String, default: '' },
})

defineEmits(['close'])

const copied = ref(false)

async function copy() {
    try {
        await navigator.clipboard.writeText(props.text)
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    } catch {
        // clipboard blocked: the text is selectable in the field above
    }
}
</script>
