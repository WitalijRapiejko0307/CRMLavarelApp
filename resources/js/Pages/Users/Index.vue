<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="page-title">Пользователи</h1>
                <button class="btn-primary" @click="openCreateModal">+ Добавить</button>
            </div>
        </template>

        <div class="card">
            <div v-if="userList.length === 0" class="text-center py-12 text-gray-400 dark:text-gray-500 text-sm">
                Пользователи не найдены
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                            <th class="pb-3 font-medium text-muted">Имя</th>
                            <th class="pb-3 font-medium text-muted">Email</th>
                            <th class="pb-3 font-medium text-muted w-32">Роль</th>
                            <th class="pb-3 font-medium text-muted w-28 text-right">Добавлен</th>
                            <th class="pb-3 w-36"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="user in userList" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="py-3 font-medium text-gray-800 dark:text-gray-200">
                                {{ user.name }}
                                <span v-if="user.id === currentUserId" class="text-xs text-indigo-400 ml-1">(вы)</span>
                            </td>
                            <td class="py-3 text-gray-600 dark:text-gray-400">{{ user.email }}</td>
                            <td class="py-3">
                                <span class="text-xs rounded-full px-2 py-0.5 font-medium"
                                    :class="roleBadgeClass(user.role)">
                                    {{ roleLabel(user.role) }}
                                </span>
                            </td>
                            <td class="py-3 text-right text-gray-400 dark:text-gray-500 text-xs whitespace-nowrap">
                                {{ fmtDate(user.created_at) }}
                            </td>
                            <td class="py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button class="btn-secondary btn-xs" @click="openEditModal(user)">
                                        Изменить
                                    </button>
                                    <button
                                        class="btn-secondary btn-xs text-red-500"
                                        :disabled="user.id === currentUserId"
                                        :title="user.id === currentUserId ? 'Нельзя удалить самого себя' : ''"
                                        @click="confirmDelete(user)"
                                    >
                                        Удалить
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Create modal ── -->
        <div v-if="createModal" class="modal-backdrop" @click.self="createModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-4">Добавить пользователя</h2>
                <div class="space-y-3">
                    <div>
                        <label class="label">Имя</label>
                        <input v-model="createForm.name" class="input" placeholder="Иван Иванов" />
                    </div>
                    <div>
                        <label class="label">Email</label>
                        <input v-model="createForm.email" type="email" class="input" placeholder="user@example.com" />
                    </div>
                    <div>
                        <label class="label">Пароль</label>
                        <input v-model="createForm.password" type="password" class="input" placeholder="Минимум 8 символов" />
                    </div>
                    <div>
                        <label class="label">Роль</label>
                        <select v-model="createForm.role" class="input">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                        </select>
                    </div>
                    <p v-if="formError" class="text-xs text-red-600">{{ formError }}</p>
                </div>
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="createModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving" @click="createUser">
                        {{ saving ? 'Создаю…' : 'Создать' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Edit modal ── -->
        <div v-if="editModal" class="modal-backdrop" @click.self="editModal = false">
            <div class="modal-box">
                <h2 class="section-title mb-1">Изменить пользователя</h2>
                <p class="text-sm text-gray-400 dark:text-gray-500 mb-4">{{ editTarget?.email }}</p>
                <div class="space-y-3">
                    <div>
                        <label class="label">Имя</label>
                        <input v-model="editForm.name" class="input" />
                    </div>
                    <div>
                        <label class="label">Роль</label>
                        <select v-model="editForm.role" class="input"
                            :disabled="editTarget?.id === currentUserId">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                        </select>
                        <p v-if="editTarget?.id === currentUserId" class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            Нельзя изменить свою роль
                        </p>
                    </div>
                    <div>
                        <label class="label">Новый пароль <span class="text-gray-400 dark:text-gray-500">(оставьте пустым, чтобы не менять)</span></label>
                        <input v-model="editForm.password" type="password" class="input" placeholder="Минимум 8 символов" />
                    </div>
                    <p v-if="formError" class="text-xs text-red-600">{{ formError }}</p>
                </div>
                <div class="flex justify-end gap-3 mt-5">
                    <button class="btn-secondary" @click="editModal = false">Отмена</button>
                    <button class="btn-primary" :disabled="saving" @click="saveEdit">
                        {{ saving ? 'Сохраняю…' : 'Сохранить' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { apiFetch } from '@/utils/api'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps({
    users: { type: Array, default: () => [] },
    roles: { type: Array, default: () => [] },
})

const page          = usePage()
const currentUserId = page.props.value.auth?.user?.id

// ── State ─────────────────────────────────────────────────────────────────────
const userList   = ref([...props.users])
const saving     = ref(false)
const formError  = ref('')

const createModal = ref(false)
const createForm  = ref({ name: '', email: '', password: '', role: 'operator' })

const editModal  = ref(false)
const editTarget = ref(null)
const editForm   = ref({ name: '', role: '', password: '' })

// ── Create ────────────────────────────────────────────────────────────────────
function openCreateModal() {
    createForm.value = { name: '', email: '', password: '', role: 'operator' }
    formError.value  = ''
    createModal.value = true
}

async function createUser() {
    formError.value = ''
    if (!createForm.value.name.trim()) { formError.value = 'Введите имя'; return }
    if (!createForm.value.email.trim()) { formError.value = 'Введите email'; return }
    if (createForm.value.password.length < 8) { formError.value = 'Пароль минимум 8 символов'; return }

    saving.value = true
    try {
        const resp = await apiFetch('/users', 'POST', createForm.value)
        const data = await resp.json()
        if (data.success) {
            userList.value.push(data.user)
            createModal.value = false
        } else {
            formError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

// ── Edit ──────────────────────────────────────────────────────────────────────
function openEditModal(user) {
    editTarget.value = user
    editForm.value   = { name: user.name, role: user.role, password: '' }
    formError.value  = ''
    editModal.value  = true
}

async function saveEdit() {
    formError.value = ''
    const payload = { name: editForm.value.name, role: editForm.value.role }
    if (editForm.value.password) {
        if (editForm.value.password.length < 8) {
            formError.value = 'Пароль минимум 8 символов'
            return
        }
        payload.password = editForm.value.password
    }

    saving.value = true
    try {
        const resp = await apiFetch(`/users/${editTarget.value.id}`, 'PUT', payload)
        const data = await resp.json()
        if (data.success) {
            updateInList(data.user)
            editModal.value = false
        } else {
            formError.value = data.message ?? 'Ошибка'
        }
    } finally {
        saving.value = false
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function confirmDelete(user) {
    if (!confirm(`Удалить пользователя «${user.name}»?`)) return
    const resp = await apiFetch(`/users/${user.id}`, 'DELETE')
    const data = await resp.json()
    if (data.success) {
        userList.value = userList.value.filter(u => u.id !== user.id)
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function updateInList(updated) {
    const idx = userList.value.findIndex(u => u.id === updated.id)
    if (idx !== -1) userList.value[idx] = { ...userList.value[idx], ...updated }
}

const roleLabelMap = { admin: 'Администратор', manager: 'Менеджер', operator: 'Оператор' }
function roleLabel(role) { return roleLabelMap[role] ?? role }

function roleBadgeClass(role) {
    return {
        admin:    'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        manager:  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-200',
        operator: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200',
    }[role] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
}

function fmtDate(value) {
    if (!value) return '—'
    return new Date(value).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
}
</script>

<style scoped>
.modal-backdrop {
    @apply fixed inset-0 bg-black/40 dark:bg-black/60 flex items-center justify-center z-50 p-4;
}
.btn-xs {
    @apply text-xs px-2 py-1;
}
</style>
