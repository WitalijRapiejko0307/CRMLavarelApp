<template>
    <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', colorClass]">
        {{ status }}
    </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
})

const colorMap = {
    'Позвонить':   'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    'Перезвонить': 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    'Отправить':   'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
    'Отправлено':  'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    'Возврат':     'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    'Отказ':       'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    'Дубль':       'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
}

const colorClass = computed(() => colorMap[props.status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300')
</script>
