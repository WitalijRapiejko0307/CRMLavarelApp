<template>
    <div @click.stop @mousedown.stop>
        <AppScrollSelect
            v-model="selectedStatus"
            :options="statuses"
            size="sm"
            :disabled="disabled || form.processing"
            :option-class-fn="statusColorClass"
            @change="onChange"
        />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/inertia-vue3'
import AppScrollSelect from '@/Components/AppScrollSelect.vue'
import { statusColorClass } from '@/utils/orderStatusColors'

const props = defineProps({
    orderId:  { type: Number, required: true },
    status:   { type: String, required: true },
    statuses: { type: Array,  required: true },
    disabled: { type: Boolean, default: false },
})

const selectedStatus = ref(props.status)
const form = useForm({ status: props.status })

watch(() => props.status, (value) => {
    selectedStatus.value = value
    form.status = value
})

function onChange() {
    if (selectedStatus.value === props.status) return

    form.status = selectedStatus.value
    form.patch(`/orders/${props.orderId}/status`, {
        preserveScroll: true,
        only: ['orders'],
        onError: () => {
            selectedStatus.value = props.status
            form.status = props.status
        },
    })
}
</script>
