<template>
    <div v-if="open" class="modal-backdrop" @click.self="emit('cancel')">
        <div class="modal-box">
            <h2 class="section-title mb-3">Удалить заказ?</h2>
            <p class="text-sm text-body mb-1">
                Заказ <strong>#{{ order?.id }}</strong>
                <span v-if="order?.full_name"> — {{ order.full_name }}</span>
            </p>
            <p v-if="order?.status" class="text-sm text-muted mb-6">
                Статус: {{ order.status }}
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button class="btn-secondary justify-center" :disabled="deleting" @click="emit('cancel')">
                    Отмена
                </button>
                <button
                    class="btn-secondary text-red-500 justify-center"
                    :disabled="deleting"
                    @click="emit('confirm')"
                >
                    {{ deleting ? 'Удаляю…' : 'Удалить' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    open:     { type: Boolean, default: false },
    order:    { type: Object, default: null },
    deleting: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'confirm'])
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
</style>
