import axios from 'axios'

const api = axios.create({
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
})

api.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 419) {
            window.location.reload()
        }
        return Promise.reject(error)
    },
)

// Binary downloads: same XSRF cookie flow, but no auto-reload on 419.
const downloadApi = axios.create({
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    responseType: 'blob',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
})

function filenameFromDisposition(header, fallback) {
    if (!header) return fallback
    const match = header.match(/filename="?([^";]+)"?/i)
    return match ? match[1] : fallback
}

async function readBlobError(response) {
    const status = response.status
    if (status === 419) {
        return 'Сессия устарела. Обновите страницу (F5) и повторите.'
    }
    try {
        const text = await response.data.text()
        const data = JSON.parse(text)
        if (data?.message) return data.message
    } catch {
        // not JSON
    }
    return `Ошибка сервера (HTTP ${status})`
}

/**
 * POST/GET a file. Resolves { ok, blob, filename, message, status }.
 * @param {string[]} acceptTypes content-type substrings considered a valid file
 */
export async function apiDownload(path, method, body, acceptTypes, fallbackName) {
    try {
        const response = await downloadApi.request({
            url: path,
            method,
            data: body,
            headers: { Accept: `${acceptTypes.join(', ')}, application/json`, 'Content-Type': 'application/json' },
        })
        const contentType = response.headers['content-type'] || ''
        if (!acceptTypes.some(t => contentType.includes(t))) {
            return { ok: false, status: response.status, message: await readBlobError(response) }
        }
        return {
            ok: true,
            status: response.status,
            blob: response.data,
            filename: filenameFromDisposition(response.headers['content-disposition'], fallbackName),
        }
    } catch (error) {
        if (error.response) {
            return { ok: false, status: error.response.status, message: await readBlobError(error.response) }
        }
        return { ok: false, status: 0, message: error.message || 'Нет связи с сервером' }
    }
}

function toFetchResponse(response) {
    return {
        ok: response.status >= 200 && response.status < 300,
        status: response.status,
        json: async () => response.data,
    }
}

export async function apiFetch(path, method, body) {
    try {
        const response = await api.request({
            url: path,
            method,
            data: body,
            headers: { 'Content-Type': 'application/json' },
        })
        return toFetchResponse(response)
    } catch (error) {
        if (error.response) {
            return toFetchResponse(error.response)
        }
        throw error
    }
}
