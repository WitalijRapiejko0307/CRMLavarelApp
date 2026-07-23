<template>
    <AppLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link href="/orders" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    ← Заказы
                </Link>
                <span class="text-gray-300 dark:text-gray-600">/</span>
                <h1 class="page-title">Импорт CSV</h1>
            </div>
        </template>

        <div class="max-w-2xl mx-auto space-y-6">

            <!-- Format hint -->
            <div class="card bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                <h2 class="text-sm font-semibold text-blue-800 dark:text-blue-200 mb-2">Формат файла</h2>
                <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed mb-3">
                    Экспорт Google Sheets (лист «Заказы»). Разделитель — запятая или точка с запятой.
                    Строка заголовков определяется автоматически (первая строка-шаблон этикетки пропускается).
                </p>
                <p class="text-xs text-blue-700 dark:text-blue-300 leading-relaxed mb-3">
                    Несколько товаров в одной строке: <strong>товары через запятую</strong>,
                    <strong>штуки и цены через пробел</strong>.
                    Пример: <code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Триммер…, Культиватор…</code> /
                    <code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">3 3</code> /
                    <code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">135 123</code>
                </p>
                <div class="grid grid-cols-2 gap-1 text-xs text-blue-700 dark:text-blue-300">
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">№ п/п</code> — внешний ID</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">ФИО</code> — имя клиента</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Товар</code> — товары (через запятую)</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Штук</code> — количество (через пробел)</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Цена за ед.</code> — цены (через пробел)</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Вид доставки</code> — Белпочта, Европочта, Курьер, Самовывоз, Лично</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Телефон</code> — телефон</div>
                    <div><code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">Дата создания</code> — дата заказа</div>
                </div>
                <p class="text-xs text-blue-600 dark:text-blue-400 mt-2">
                    Строки с уже существующим <code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">№ п/п</code> (external_id) будут пропущены.
                    Строки без <code class="bg-blue-100 dark:bg-blue-900/50 rounded px-1">ФИО</code> игнорируются.
                    Колонка «Доставка» (стоимость) не импортируется.
                </p>
            </div>

            <!-- Upload form -->
            <div class="card">
                <h2 class="section-title mb-4">Загрузить файл</h2>

                <div
                    class="border-2 border-dashed rounded-xl p-8 text-center transition-colors"
                    :class="dragging ? 'border-indigo-400 bg-indigo-50 dark:bg-indigo-900/30' : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <div v-if="!selectedFile">
                        <svg class="w-10 h-10 text-gray-300 dark:text-gray-600 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <p class="text-sm text-muted mb-2">Перетащите CSV-файл или</p>
                        <label class="btn-secondary cursor-pointer text-sm">
                            Выбрать файл
                            <input type="file" accept=".csv,.txt" class="hidden" @change="onFileSelect" />
                        </label>
                    </div>
                    <div v-else class="flex items-center justify-center gap-3">
                        <svg class="w-6 h-6 text-indigo-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <div class="text-left">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ selectedFile.name }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ fmtSize(selectedFile.size) }}</p>
                        </div>
                        <button class="text-gray-300 dark:text-gray-600 hover:text-red-500 dark:hover:text-red-400 ml-4 transition-colors" @click="clearFile">✕</button>
                    </div>
                </div>

                <p v-if="fileError" class="text-xs text-red-600 mt-2">{{ fileError }}</p>

                <div class="flex justify-end mt-5">
                    <button
                        class="btn-primary"
                        :disabled="!selectedFile || importing || readOnly"
                        @click="startImport"
                    >
                        {{ importing ? 'Импортирую…' : 'Импортировать' }}
                    </button>
                </div>
            </div>

            <!-- Result -->
            <div v-if="result" class="card border" :class="result.errors > 0 ? 'border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-900/20' : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20'">
                <h2 class="text-sm font-semibold mb-3" :class="result.errors > 0 ? 'text-yellow-800 dark:text-yellow-200' : 'text-green-800 dark:text-green-200'">
                    Импорт завершён
                </h2>
                <div class="grid grid-cols-3 gap-4 text-center text-sm">
                    <div>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ result.created }}</p>
                        <p class="text-xs text-muted">Создано</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-muted">{{ result.skipped }}</p>
                        <p class="text-xs text-muted">Пропущено</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold text-red-500 dark:text-red-400">{{ result.errors }}</p>
                        <p class="text-xs text-muted">Ошибок</p>
                    </div>
                </div>
                <div class="flex justify-center mt-4">
                    <Link href="/orders" class="btn-primary btn-sm">Перейти к заказам</Link>
                </div>
                <div v-if="result.warnings?.length" class="mt-4 pt-4 border-t border-yellow-200 dark:border-yellow-800">
                    <p class="text-xs font-semibold text-yellow-800 dark:text-yellow-200 mb-2">Предупреждения</p>
                    <ul class="space-y-1 text-xs text-yellow-700 dark:text-yellow-300">
                        <li v-for="(w, i) in result.warnings" :key="i">
                            Строка {{ w.row }}: {{ w.message }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link } from '@inertiajs/inertia-vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useSubscription } from '@/composables/useSubscription'

defineProps({
    statuses: { type: Array, default: () => [] },
})

const { readOnly } = useSubscription()

const selectedFile = ref(null)
const dragging     = ref(false)
const importing    = ref(false)
const fileError    = ref('')
const result       = ref(null)

function onFileSelect(e) {
    const file = e.target.files?.[0]
    if (file) setFile(file)
}

function onDrop(e) {
    dragging.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) setFile(file)
}

function setFile(file) {
    fileError.value = ''
    result.value    = null
    if (!file.name.match(/\.(csv|txt)$/i)) {
        fileError.value = 'Допустимы только файлы .csv или .txt'
        return
    }
    if (file.size > 10 * 1024 * 1024) {
        fileError.value = 'Файл слишком большой (максимум 10 МБ)'
        return
    }
    selectedFile.value = file
}

function clearFile() {
    selectedFile.value = null
    result.value       = null
    fileError.value    = ''
}

async function startImport() {
    if (!selectedFile.value || readOnly.value) return
    importing.value = true
    fileError.value = ''
    result.value    = null

    const formData = new FormData()
    formData.append('file', selectedFile.value)
    formData.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '')

    try {
        const resp = await fetch('/orders/import-csv', {
            method: 'POST',
            body: formData,
        })
        const data = await resp.json()

        if (data.success) {
            result.value = data
            selectedFile.value = null
        } else {
            fileError.value = data.message ?? 'Ошибка импорта'
        }
    } catch (e) {
        fileError.value = 'Сетевая ошибка. Попробуйте снова.'
    } finally {
        importing.value = false
    }
}

function fmtSize(bytes) {
    if (bytes < 1024) return bytes + ' Б'
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ'
    return (bytes / 1024 / 1024).toFixed(1) + ' МБ'
}
</script>

<style scoped>
.btn-sm {
    @apply text-sm px-3 py-1.5;
}
</style>
