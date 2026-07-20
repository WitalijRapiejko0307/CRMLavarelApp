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
