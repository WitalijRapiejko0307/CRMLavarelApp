<template>
    <AdminLayout>
        <template #header>
            <PageHeader>
                <template #title>
                    <h1 class="page-title">Тенанты</h1>
                </template>
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="btn-secondary text-sm"
                            :class="!showArchived && 'ring-2 ring-indigo-400/60'"
                            @click="goFilter(false)"
                        >
                            Активные
                        </button>
                        <button
                            type="button"
                            class="btn-secondary text-sm"
                            :class="showArchived && 'ring-2 ring-indigo-400/60'"
                            @click="goFilter(true)"
                        >
                            Архив
                        </button>
                    </div>
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
                                <th class="py-3 pr-4">Заявки</th>
                                <th class="py-3 pr-4">Активен до</th>
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
                                <td class="py-3 pr-4 text-xs">
                                    {{ leadsLabel(tenant) }}
                                </td>
                                <td class="py-3 pr-4">
                                    <input
                                        v-model="forms[tenant.id].access_until"
                                        type="datetime-local"
                                        class="w-full max-w-[220px]"
                                        :disabled="isAccessDateLocked(tenant.id)"
                                        @change="saveTenant(tenant.id)"
                                    />
                                </td>
                                <td class="py-3 pr-4">{{ tenant.admin_email || '—' }}</td>
                                <td class="py-3 pr-4">{{ tenant.users_count }}</td>
                                <td class="py-3 pr-4">{{ tenant.orders_count }}</td>
                                <td class="py-3 align-top">
                                    <div class="relative inline-block">
                                        <button
                                            type="button"
                                            class="btn-secondary text-xs px-3"
                                            :disabled="forms[tenant.id].processing"
                                            aria-label="Действия"
                                            @click="toggleMenu(tenant.id)"
                                        >
                                            …
                                        </button>
                                    <div
                                        v-if="menuOpen[tenant.id]"
                                        class="absolute right-0 top-full z-20 mt-1 w-52 p-2 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg space-y-1 text-xs"
                                    >
                                        <button
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="activateTenant(tenant.id)"
                                        >
                                            Активировать
                                        </button>
                                        <button
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="extendSubscription(tenant.id)"
                                        >
                                            Продлить месяц
                                        </button>
                                        <button
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="extendTrial(tenant.id)"
                                        >
                                            +14 дней trial
                                        </button>
                                        <button
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="stopLeads(tenant.id)"
                                        >
                                            Остановить заявки
                                        </button>
                                        <button
                                            v-if="canResumeLeads(tenant)"
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="resumeLeads(tenant.id)"
                                        >
                                            Возобновить заявки
                                        </button>
                                        <button
                                            v-if="!tenant.archived_at"
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="archiveTenant(tenant.id)"
                                        >
                                            В архив
                                        </button>
                                        <button
                                            v-if="tenant.archived_at"
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                            :disabled="forms[tenant.id].processing"
                                            @click="unarchiveTenant(tenant.id)"
                                        >
                                            Из архива
                                        </button>
                                        <template v-if="tenant.deletion_scheduled_for">
                                            <p class="px-2 py-1 text-muted">
                                                Удаление: {{ formatDate(tenant.deletion_scheduled_for) }}
                                            </p>
                                            <button
                                                type="button"
                                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                                :disabled="forms[tenant.id].processing"
                                                @click="cancelDeletion(tenant.id)"
                                            >
                                                Отменить удаление
                                            </button>
                                        </template>
                                        <button
                                            v-if="!deleteState(tenant.id).open"
                                            type="button"
                                            class="block w-full text-left px-2 py-1.5 rounded text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20"
                                            :disabled="forms[tenant.id].processing"
                                            @click="startDeleteConfirm(tenant.id)"
                                        >
                                            Удалить
                                        </button>
                                    </div>
                                    <div
                                        v-if="deleteState(tenant.id).open"
                                        class="mt-2 p-2 rounded border border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-900/20 space-y-2 text-xs"
                                    >
                                        <p class="text-red-800 dark:text-red-200">
                                            Введите «{{ tenant.name }}» для подтверждения
                                        </p>
                                        <input
                                            v-model="deleteState(tenant.id).input"
                                            type="text"
                                            class="w-full"
                                        />
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="btn-secondary text-xs text-red-600 dark:text-red-400"
                                                :disabled="deleteState(tenant.id).input !== tenant.name || deleteState(tenant.id).processing"
                                                @click="confirmDelete(tenant)"
                                            >
                                                Удалить навсегда
                                            </button>
                                            <button
                                                type="button"
                                                class="btn-secondary text-xs"
                                                @click="cancelDeleteConfirm(tenant.id)"
                                            >
                                                Отмена
                                            </button>
                                        </div>
                                    </div>
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
                    <p class="mt-1 text-xs text-muted">{{ leadsLabel(tenant) }}</p>

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
                            <label class="label">Активен до</label>
                            <input
                                v-model="forms[tenant.id].access_until"
                                type="datetime-local"
                                class="w-full mt-1"
                                :disabled="isAccessDateLocked(tenant.id)"
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

                    <div class="mt-3">
                        <div class="relative inline-block">
                            <button
                                type="button"
                                class="btn-secondary text-xs touch-target px-3"
                                :disabled="forms[tenant.id].processing"
                                aria-label="Действия"
                                @click="toggleMenu(tenant.id)"
                            >
                                …
                            </button>
                        <div
                            v-if="menuOpen[tenant.id]"
                            class="absolute left-0 top-full z-20 mt-1 w-52 p-2 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg space-y-1 text-xs"
                        >
                            <button
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="activateTenant(tenant.id)"
                            >
                                Активировать
                            </button>
                            <button
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="extendSubscription(tenant.id)"
                            >
                                Продлить месяц
                            </button>
                            <button
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="extendTrial(tenant.id)"
                            >
                                +14 дней trial
                            </button>
                            <button
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="stopLeads(tenant.id)"
                            >
                                Остановить заявки
                            </button>
                            <button
                                v-if="canResumeLeads(tenant)"
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="resumeLeads(tenant.id)"
                            >
                                Возобновить заявки
                            </button>
                            <button
                                v-if="!tenant.archived_at"
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="archiveTenant(tenant.id)"
                            >
                                В архив
                            </button>
                            <button
                                v-if="tenant.archived_at"
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="unarchiveTenant(tenant.id)"
                            >
                                Из архива
                            </button>
                            <template v-if="tenant.deletion_scheduled_for">
                                <p class="px-2 py-1 text-muted">
                                    Удаление: {{ formatDate(tenant.deletion_scheduled_for) }}
                                </p>
                                <button
                                    type="button"
                                    class="block w-full text-left px-2 py-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-800 touch-target"
                                    :disabled="forms[tenant.id].processing"
                                    @click="cancelDeletion(tenant.id)"
                                >
                                    Отменить удаление
                                </button>
                            </template>
                            <button
                                v-if="!deleteState(tenant.id).open"
                                type="button"
                                class="block w-full text-left px-2 py-1.5 rounded text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 touch-target"
                                :disabled="forms[tenant.id].processing"
                                @click="startDeleteConfirm(tenant.id)"
                            >
                                Удалить
                            </button>
                        </div>
                        <div
                            v-if="deleteState(tenant.id).open"
                            class="p-2 rounded border border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-900/20 space-y-2 text-xs"
                        >
                            <p class="text-red-800 dark:text-red-200">
                                Введите «{{ tenant.name }}» для подтверждения
                            </p>
                            <input
                                v-model="deleteState(tenant.id).input"
                                type="text"
                                class="w-full"
                            />
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="btn-secondary text-xs text-red-600 dark:text-red-400 touch-target"
                                    :disabled="deleteState(tenant.id).input !== tenant.name || deleteState(tenant.id).processing"
                                    @click="confirmDelete(tenant)"
                                >
                                    Удалить навсегда
                                </button>
                                <button
                                    type="button"
                                    class="btn-secondary text-xs touch-target"
                                    @click="cancelDeleteConfirm(tenant.id)"
                                >
                                    Отмена
                                </button>
                            </div>
                        </div>
                        </div>
                    </div>
                </ListCard>
            </template>
        </ResponsiveList>
    </AdminLayout>
</template>

<script setup>
import { reactive, computed } from 'vue'
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
    archived: {
        type: Boolean,
        default: false,
    },
})

const showArchived = computed(() => props.archived)

const menuOpen = reactive({})
const deleteConfirm = reactive({})

function ensureDeleteState(id) {
    if (!deleteConfirm[id]) {
        deleteConfirm[id] = { open: false, input: '', processing: false }
    }
    return deleteConfirm[id]
}

function deleteState(id) {
    return ensureDeleteState(id)
}

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
            subscription_status: tenant.subscription_status,
            access_until: toLocalInput(tenant.access_until),
            processing: false,
        },
    ])
))

function leadsLabel(tenant) {
    const enabled = tenant.leads_enabled !== false
    return enabled ? 'заявки принимаются' : 'заявки остановлены'
}

function canResumeLeads(tenant) {
    if (tenant.archived_at) return false
    const status = tenant.subscription_status
    return status === 'trial' || status === 'active'
}

function formatDate(value) {
    if (!value) return '—'
    return new Date(value).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}

function goFilter(archived) {
    if (archived) {
        Inertia.get('/admin/tenants', { archived: 1 }, { preserveState: true, preserveScroll: true })
    } else {
        Inertia.get('/admin/tenants', {}, { preserveState: true, preserveScroll: true })
    }
}

function toggleMenu(id) {
    menuOpen[id] = !menuOpen[id]
}

function withProcessing(id, fn) {
    forms[id].processing = true
    fn({
        preserveScroll: true,
        onFinish: () => { forms[id].processing = false },
    })
}

function isAccessDateLocked(id) {
    const status = forms[id].subscription_status
    return status === 'expired' || status === 'suspended'
}

function saveTenant(id) {
    const payload = {
        subscription_status: forms[id].subscription_status,
    }
    if (!isAccessDateLocked(id)) {
        payload.access_until = forms[id].access_until || null
    }
    withProcessing(id, (opts) => {
        Inertia.patch(`/admin/tenants/${id}`, payload, opts)
    })
}

function activateTenant(id) {
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/activate`, {}, opts))
}

function extendSubscription(id) {
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/extend-subscription`, {}, opts))
}

function extendTrial(id) {
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/extend-trial`, { days: 14 }, opts))
}

function stopLeads(id) {
    menuOpen[id] = false
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/leads-enabled`, { enabled: false }, opts))
}

function resumeLeads(id) {
    menuOpen[id] = false
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/leads-enabled`, { enabled: true }, opts))
}

function archiveTenant(id) {
    menuOpen[id] = false
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/archive`, {}, opts))
}

function unarchiveTenant(id) {
    menuOpen[id] = false
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/unarchive`, {}, opts))
}

function cancelDeletion(id) {
    menuOpen[id] = false
    withProcessing(id, (opts) => Inertia.post(`/admin/tenants/${id}/cancel-deletion`, {}, opts))
}

function startDeleteConfirm(id) {
    menuOpen[id] = false
    const state = ensureDeleteState(id)
    state.open = true
    state.input = ''
}

function cancelDeleteConfirm(id) {
    const state = ensureDeleteState(id)
    state.open = false
    state.input = ''
}

function confirmDelete(tenant) {
    const state = ensureDeleteState(tenant.id)
    if (state.input !== tenant.name || state.processing) return
    state.processing = true
    forms[tenant.id].processing = true
    Inertia.delete(`/admin/tenants/${tenant.id}`, {
        data: { confirm_name: state.input },
        preserveScroll: true,
        onFinish: () => {
            state.processing = false
            forms[tenant.id].processing = false
            state.open = false
            state.input = ''
        },
    })
}
</script>
