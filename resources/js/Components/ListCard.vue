<template>
    <div
        class="list-card overflow-visible"
        :class="clickable ? 'list-card-clickable' : ''"
        :role="clickable ? 'button' : undefined"
        :tabindex="clickable ? 0 : undefined"
        @click="onClick"
        @keydown.enter="onClick"
    >
        <slot />
    </div>
</template>

<script setup>
const props = defineProps({
    clickable: { type: Boolean, default: false },
})

const emit = defineEmits(['click'])

function onClick(event) {
    if (props.clickable) emit('click', event)
}
</script>
