let activeClose = null

export function registerScrollSelectOpen(closeFn) {
    if (activeClose && activeClose !== closeFn) {
        activeClose()
    }
    activeClose = closeFn
}

export function unregisterScrollSelectClose(closeFn) {
    if (activeClose === closeFn) {
        activeClose = null
    }
}
