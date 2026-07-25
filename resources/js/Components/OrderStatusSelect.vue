<template>
    <div @click.stop @mousedown.stop>
        <select
            v-model="selectedStatus"
            class="text-xs py-1 max-w-[140px] truncate rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
            :disabled="disabled || form.processing"
            @change="onChange"
        >
            <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
        </select>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/inertia-vue3'

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
