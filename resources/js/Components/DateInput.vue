<template>
    <input
        ref="inputRef"
        type="text"
        inputmode="numeric"
        maxlength="10"
        placeholder="дд/мм/гг"
        class="w-full"
        :value="modelValue"
        @input="onInput"
        @change="emit('change')"
    />
</template>

<script setup>
import { maskDateInput } from '@/utils/date'

defineProps({
    modelValue: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'change'])

function onInput(event) {
    emit('update:modelValue', maskDateInput(event.target.value))
}
</script>
