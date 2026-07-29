<template>
    <AdminLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Тенанты</h1>
                </template>
            </PageHeader>
        </template>

        <ResponsiveList>
            <template #table>
                <div class="card overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-muted">
                                <th class="py-3 pr-4">Компания</th>
                                <th class="py-3 pr-4">Статус</th>
                                <th class="py-3 pr-4">Trial до</th>
                                <th class="py-3 pr-4">Admin</th>
                                <th class="py-3 pr-4">Пользователи</th>
                                <th class="py-3 pr-4">Заказы</th>
                                <th class="py-3">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="tenant in tenants"
                                :key="tenant.id"
                                class="border-b border-gray-100 dark:border-gray-800"
                            >
                                <td class="py-3 pr-4 font-medium">{{ tenant.name }}</td>
                                <td class="py-3 pr-4">
                                    <select
                                        v-model="forms[tenant.id].subscription_status"
                                        class="w-full max-w-[140px]"
                                        @change="saveTenant(tenant.id)"
                                    >
                                        <option value="trial">trial</option>
                                        <option value="active">active</option>
                                        <option value="expired">expired</option>
                                        <option value="suspended">suspended</option>
                                    </select>
                                    <div v-if="tenant.read_only" class="text-xs text-red-600 dark:text-red-400 mt-1">read-only</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <input
                                        v-model="forms[tenant.id].trial_ends_at"
                                        type="datetime-local"
                                        class="w-full max-w-[220px]"
                                        @change="saveTenant(tenant.id)"
                                    />
                                </td>
                                <td class="py-3 pr-4">{{ tenant.admin_email || '—' }}</td>
                                <td class="py-3 pr-4">{{ tenant.users_count }}</td>
                                <td class="py-3 pr-4">{{ tenant.orders_count }}</td>
                                <td class="py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="btn-secondary text-xs"
                                            :disabled="forms[tenant.id].processing"
                                            @click="activateTenant(tenant.id)"
                                        >
                                            Активировать
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-secondary text-xs"
                                            :disabled="forms[tenant.id].processing"
                                            @click="extendTrial(tenant.id)"
                                        >
                                            +14 дней trial
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template #cards>
                <ListCard v-for="tenant in tenants" :key="tenant.id">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ tenant.name }}</p>
                        <span v-if="tenant.read_only" class="text-xs text-red-600 dark:text-red-400 shrink-0">read-only</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ tenant.admin_email || '—' }}</p>

                    <div class="mt-3 space-y-2">
                        <div>
                            <label class="label">Статус</label>
                            <select
                                v-model="forms[tenant.id].subscription_status"
                                class="w-full mt-1"
                                @change="saveTenant(tenant.id)"
                            >
                                <option value="trial">trial</option>
                                <option value="active">active</option>
                                <option value="expired">expired</option>
                                <option value="suspended">suspended</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Trial до</label>
                            <input
                                v-model="forms[tenant.id].trial_ends_at"
                                type="datetime-local"
                                class="w-full mt-1"
                                @change="saveTenant(tenant.id)"
                            />
                        </div>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted">Пользователи:</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ tenant.users_count }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-muted">Заказы:</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ tenant.orders_count }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn-secondary text-xs touch-target"
                            :disabled="forms[tenant.id].processing"
                            @click="activateTenant(tenant.id)"
                        >
                            Активировать
                        </button>
                        <button
                            type="button"
                            class="btn-secondary text-xs touch-target"
                            :disabled="forms[tenant.id].processing"
                            @click="extendTrial(tenant.id)"
                        >
                            +14 дней trial
                        </button>
                    </div>
                </ListCard>
            </template>
        </ResponsiveList>
    </AdminLayout>
</template>

<script setup>
import { reactive } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import ResponsiveList from '@/Components/ResponsiveList.vue'
import ListCard from '@/Components/ListCard.vue'
import { Inertia } from '@inertiajs/inertia'

const props = defineProps({
    tenants: {
        type: Array,
        required: true,
    },
})

function toLocalInput(value) {
    if (!value) return ''
    const date = new Date(value)
    const pad = (n) => String(n).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const forms = reactive(Object.fromEntries(
    props.tenants.map((tenant) => [
        tenant.id,
        {
            subscription_status: tenant.stored_status,
            trial_ends_at: toLocalInput(tenant.trial_ends_at),
            processing: false,
        },
    ])
))

function saveTenant(id) {
    forms[id].processing = true
    Inertia.patch(`/admin/tenants/${id}`, {
        subscription_status: forms[id].subscription_status,
        trial_ends_at: forms[id].trial_ends_at || null,
    }, {
        preserveScroll: true,
        onFinish: () => { forms[id].processing = false },
    })
}

function activateTenant(id) {
    forms[id].processing = true
    Inertia.post(`/admin/tenants/${id}/activate`, {}, {
        preserveScroll: true,
        onFinish: () => { forms[id].processing = false },
    })
}

function extendTrial(id) {
    forms[id].processing = true
    Inertia.post(`/admin/tenants/${id}/extend-trial`, { days: 14 }, {
        preserveScroll: true,
        onFinish: () => { forms[id].processing = false },
    })
}
</script>
