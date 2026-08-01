document.addEventListener('alpine:init', () => {
    // Must stay in sync with GiacomoMasseroni\AsyncColumn\Livewire\AsyncColumnHook::METHOD
    // — this string is the one place that constant is unavoidably duplicated, because
    // the PHP constant cannot be read from plain JS with no build step.
    const RESOLVE_METHOD = 'resolveAsyncColumns'

    window.Alpine.store('asyncColumn', {
        pending: new Map(),
        cache: new Map(),
        scheduled: false,
        observer: null,

        register(el) {
            const token = el.dataset.asyncColumnToken

            if (! token) {
                return
            }

            // Repaint instantly from the client-side cache: after a sort or a
            // paginate, rows we already resolved should not hit the server again.
            if (this.cache.has(token)) {
                el.innerHTML = this.cache.get(token)

                return
            }

            if (el.dataset.asyncColumnWhenVisible === '1') {
                this.observe(el)

                return
            }

            this.enqueue(el)
        },

        observe(el) {
            if (! this.observer) {
                this.observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (! entry.isIntersecting) {
                            return
                        }

                        this.observer.unobserve(entry.target)
                        this.enqueue(entry.target)
                    })
                })
            }

            this.observer.observe(el)
        },

        enqueue(el) {
            const token = el.dataset.asyncColumnToken

            if (! this.pending.has(token)) {
                this.pending.set(token, [])
            }

            this.pending.get(token).push(el)
            this.schedule(el)
        },

        // One animation frame of debounce coalesces everything pending into a
        // single request — including several rows scrolling into view together.
        schedule(el) {
            if (this.scheduled) {
                return
            }

            this.scheduled = true

            requestAnimationFrame(() => {
                this.scheduled = false
                this.flush(el)
            })
        },

        flush(el) {
            if (this.pending.size === 0) {
                return
            }

            const batch = new Map(this.pending)
            this.pending.clear()

            const component = this.componentFor(el)

            if (! component) {
                return
            }

            component.call(RESOLVE_METHOD, Array.from(batch.keys()))
                .then((result) => this.apply(batch, result))
                .catch(() => this.markFailed(batch))
        },

        apply(batch, result) {
            const cells = (result && result.cells) || {}

            batch.forEach((elements, token) => {
                const cell = cells[token]

                if (! cell) {
                    return
                }

                if (! cell.error) {
                    this.cache.set(token, cell.html)
                }

                elements.forEach((el) => {
                    el.innerHTML = cell.html
                    el.dataset.asyncColumnError = cell.error ? '1' : '0'
                })
            })
        },

        markFailed(batch) {
            batch.forEach((elements) => {
                elements.forEach((el) => {
                    el.dataset.asyncColumnError = '1'
                })
            })
        },

        retry(el) {
            delete el.dataset.asyncColumnError
            this.cache.delete(el.dataset.asyncColumnToken)
            this.enqueue(el)
        },

        componentFor(el) {
            const root = el.closest('[wire\\:id]')

            return root ? window.Livewire.find(root.getAttribute('wire:id')) : null
        },
    })
})
