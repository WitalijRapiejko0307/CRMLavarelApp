<template>
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div class="min-w-0">
            <slot name="title" />
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 md:justify-end">
            <slot name="actions" />
        </div>
    </div>
</template>

<script setup>
</script>
