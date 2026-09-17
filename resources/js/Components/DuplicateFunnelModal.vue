<template>
    <div v-if="open" class="modal-backdrop" @click.self="onCancel">
        <div class="modal-box">
            <h2 class="section-title mb-3">Исключить из воронки?</h2>
            <p class="text-sm text-body mb-4">
                Заказ будет со статусом «Дубль» и по умолчанию не попадёт в отчёт магазина.
            </p>

            <p class="label mb-2">Причина</p>
            <div class="space-y-2 mb-4">
                <label
                    v-for="option in reasonOptions"
                    :key="option.value"
                    class="flex items-center gap-2 cursor-pointer"
                >
                    <input
                        v-model="funnelReason"
                        type="radio"
                        :value="option.value"
                        class="text-indigo-600"
                        :disabled="processing"
                    />
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ option.label }}</span>
                </label>
            </div>

            <label class="flex items-center gap-2 cursor-pointer mb-6">
                <input
                    v-model="funnelExclude"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 dark:bg-gray-700"
                    :disabled="processing"
                />
                <span class="text-sm text-gray-700 dark:text-gray-300">Не учитывать в воронке</span>
            </label>

            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button class="btn-secondary justify-center" :disabled="processing" @click="onCancel">
                    Отмена
                </button>
                <button
                    class="btn-primary justify-center"
                    :disabled="processing || !funnelReason"
                    @click="onConfirm"
                >
                    {{ processing ? 'Сохраняю…' : 'Подтвердить' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    open:       { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'confirm'])

const reasonOptions = [
    { value: 'duplicate', label: 'дубль' },
    { value: 'test', label: 'тест' },
    { value: 'extra', label: 'доп. к другому заказу' },
]

const funnelReason = ref('duplicate')
const funnelExclude = ref(true)

watch(() => props.open, (isOpen) => {
    if (!isOpen) return
    funnelReason.value = 'duplicate'
    funnelExclude.value = true
})

function onCancel() {
    if (props.processing) return
    emit('cancel')
}

function onConfirm() {
    if (props.processing || !funnelReason.value) return
    emit('confirm', {
        funnel_exclude: funnelExclude.value,
        funnel_reason: funnelReason.value,
    })
}
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
</style>
