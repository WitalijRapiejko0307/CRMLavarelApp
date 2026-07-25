<template>
    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', colorClass]">
        {{ status }}
    </span>
</template>

<script setup>
import { computed } from 'vue'
import { statusColorClass } from '@/utils/orderStatusColors'

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
})

const colorClass = computed(() => statusColorClass(props.status))
</script>
