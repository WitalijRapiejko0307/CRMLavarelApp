const colorMap = {
    'Позвонить':    'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    'Перезвонить':  'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    'Недозвон':     'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    'Недозвон1':    'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    'Недозвон2':    'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    'Сомнения':     'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
    'Отдал заявку': 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
    'Заказать':     'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-200',
    'Подтвержден':  'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
    'Отправить':    'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
    'Отправлено':   'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    'Возврат':      'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    'Отказ':        'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    'Спам':         'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300',
    'Дубль':        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
}

const fallbackClass = 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'

export function statusColorClass(status) {
    return colorMap[status] ?? fallbackClass
}
