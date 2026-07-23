<template>
    <div class="min-h-screen flex flex-col bg-gray-50 dark:bg-gray-950">
        <nav class="bg-slate-800 dark:bg-slate-950 text-white shadow-md border-b border-slate-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-14">
                    <div class="flex items-center gap-5">
                        <span class="text-lg font-bold tracking-tight">BaseCRM Admin</span>
                        <Link href="/admin/tenants" class="text-sm font-medium text-slate-200">
                            Тенанты
                        </Link>
                    </div>
                    <form @submit.prevent="logout">
                        <button type="submit" class="text-slate-300 hover:text-white text-sm">
                            Выйти
                        </button>
                    </form>
                </div>
            </div>
        </nav>

        <div v-if="flash.message || flash.error" class="max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 mt-4">
            <div v-if="flash.message" class="bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 rounded-md px-4 py-3 text-sm">
                {{ flash.message }}
            </div>
            <div v-if="flash.error" class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-md px-4 py-3 text-sm">
                {{ flash.error }}
            </div>
        </div>

        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <header v-if="$slots.header" class="mb-6">
                <slot name="header" />
            </header>
            <slot />
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/inertia-vue3'
import { Inertia } from '@inertiajs/inertia'

const page = usePage()

const flash = computed(() => ({
    message: page.props.value.flash?.message,
    error:   page.props.value.flash?.error,
}))

function logout() {
    Inertia.post('/logout')
}
</script>
