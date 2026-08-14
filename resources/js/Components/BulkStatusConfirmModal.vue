<template>
    <div v-if="open" class="modal-backdrop" @click.self="emit('cancel')">
        <div class="modal-box">
            <h2 class="section-title mb-3">Подтвердить смену статуса</h2>
            <p class="text-sm text-body mb-1">
                Изменить статус у <strong>{{ count }}</strong>
                {{ countLabel }} на «<strong>{{ status }}</strong>»?
            </p>
            <p v-if="warning" class="text-sm text-amber-700 dark:text-amber-300 mb-6 mt-3">
                {{ warning }}
            </p>
            <p v-else class="mb-6"></p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button class="btn-secondary justify-center" :disabled="applying" @click="emit('cancel')">
                    Отмена
                </button>
                <button class="btn-primary justify-center" :disabled="applying" @click="emit('confirm')">
                    {{ applying ? 'Применяю…' : 'Подтвердить' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    open:     { type: Boolean, default: false },
    count:    { type: Number, default: 0 },
    status:   { type: String, default: '' },
    warning:  { type: String, default: '' },
    applying: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'confirm'])

const countLabel = computed(() => {
    const n = props.count
    const mod10 = n % 10
    const mod100 = n % 100
    if (mod10 === 1 && mod100 !== 11) return 'заказа'
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return 'заказов'
    return 'заказов'
})
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
</style>
