<template>
    <div
        v-if="prefill && !prefill.acknowledged && !dismissed"
        class="mb-4 flex flex-wrap items-start justify-between gap-3 rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-900/30 dark:text-sky-100"
    >
        <div>
            <div class="font-semibold">Данные подтянулись автоматически из базы клиентов</div>
            <div class="mt-0.5">
                Заполнено: {{ labels }}.
                <template v-if="prefill.source_order_id">
                    Источник: заявка
                    <a :href="`/orders/${prefill.source_order_id}`" class="underline">#{{ prefill.source_order_id }}</a>.
                </template>
                Товар не менялся. Уточните данные у клиента.
            </div>
        </div>
        <button type="button" class="btn-secondary btn-sm shrink-0" @click="ack">Понятно</button>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { apiFetch } from '@/utils/api'

const props = defineProps({
    prefill: { type: Object, default: null },
    orderId: { type: Number, required: true },
})

const dismissed = ref(false)

const labels = computed(() => {
    const fields = props.prefill?.fields ?? []
    const out = []
    if (fields.includes('full_name')) out.push('ФИО')
    if (fields.some(f => ['city', 'street', 'building', 'housing', 'apartment', 'poste_restante'].includes(f))) out.push('адрес')
    if (fields.some(f => ['delivery_type', 'belpost_address_id', 'europochta_store_id'].includes(f))) out.push('способ доставки')
    return out.join(', ')
})

async function ack() {
    dismissed.value = true
    try {
        await apiFetch(`/orders/${props.orderId}/customer-prefill/ack`, 'POST', {})
    } catch (e) {
        // The banner is informational: it will simply show again next time.
    }
}
</script>
