<template>
    <div>
        <div class="hidden md:block">
            <slot name="table" />
        </div>
        <div class="md:hidden space-y-3">
            <slot name="cards" />
        </div>
    </div>
</template>

<script setup>
// CSS-only table/cards switch — no JS branching, so there's no flash of the
// wrong layout during SSR/hydration or before useBreakpoint could react.
</script>
