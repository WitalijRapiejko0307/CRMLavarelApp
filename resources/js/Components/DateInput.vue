<template>
    <input
        type="date"
        class="w-full"
        :value="modelValue || ''"
        @input="onInput"
        @change="emit('change')"
    />
</template>

<script setup>
defineProps({
    modelValue: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])

function onInput(event) {
    emit('update:modelValue', event.target.value)
}
</script>
