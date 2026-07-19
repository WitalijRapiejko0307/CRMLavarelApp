import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/inertia-vue3'
import { InertiaProgress } from '@inertiajs/progress'
import { initTheme } from './composables/useTheme'
import '../css/app.css'

InertiaProgress.init({
    color: '#4f46e5',
    includeCSS: true,
    showSpinner: true,
})

createInertiaApp({
    resolve: name => require(`./Pages/${name}`).default,
    setup({ el, App, props, plugin }) {
        const userTheme = props.initialPage?.props?.auth?.user?.theme ?? null
        initTheme(userTheme)

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    title: title => title ? `${title} — BaseCRM` : 'BaseCRM',
})
