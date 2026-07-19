<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-950">
        <div class="w-full max-w-md">
            <!-- Logo -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-indigo-700 dark:text-indigo-400">BaseCRM</h1>
                <p class="text-muted mt-1 text-sm">Управление заказами</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md dark:shadow-none dark:border dark:border-gray-700 p-8">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-6">Вход в систему</h2>

                <form @submit.prevent="submit" class="space-y-5">
                    <div>
                        <label for="email" class="label mb-1">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            class="w-full"
                            :class="{ 'border-red-500': form.errors.email }"
                            placeholder="admin@crm.by"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="label mb-1">Пароль</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="w-full"
                        />
                    </div>

                    <div class="flex items-center">
                        <input
                            id="remember"
                            v-model="form.remember"
                            type="checkbox"
                            class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 dark:bg-gray-700"
                        />
                        <label for="remember" class="ml-2 block text-sm text-body">Запомнить меня</label>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full btn-primary justify-center py-2.5"
                        :class="{ 'opacity-50 cursor-not-allowed': form.processing }"
                    >
                        <span v-if="form.processing">Вход…</span>
                        <span v-else>Войти</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useForm } from '@inertiajs/inertia-vue3'

const form = useForm({
    email:    '',
    password: '',
    remember: false,
})

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>
