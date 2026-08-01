document.addEventListener('alpine:init', () => {
    // Must stay in sync with GiacomoMasseroni\AsyncColumn\Livewire\AsyncColumnHook::METHOD
    // — this string is the one place that constant is unavoidably duplicated, because
    // the PHP constant cannot be read from plain JS with no build step.
    const RESOLVE_METHOD = 'resolveAsyncColumns'

    window.Alpine.store('asyncColumn', {
        // Map<componentId, Map<token, el[]>> — grouped by Livewire component (its
        // wire:id) rather than one page-wide bag. BatchResolver::resolve() only ever
        // resolves columns against the CALLED component's own table, so a page with
        // several tables (e.g. a resource table plus a relation manager) must issue
        // one request PER TABLE. Routing every pending cell into a single component's
        // call — which a page-wide bag would do — silently strands every other
        // component's cells on their skeleton forever.
        pending: new Map(),

        // Map<componentId, Map<token, html>> — likewise keyed by component. A
        // CellToken only encodes columnName + recordKey, so two different tables using
        // the same column name against a record with the same id produce
        // byte-identical tokens. An unscoped cache would let one table's resolved HTML
        // get painted into another table's cell for a *different* underlying record —
        // a data-correctness bug, not just a missed cache hit.
        cache: new Map(),

        scheduled: false,
        observer: null,

        register(el) {
            const token = el.dataset.asyncColumnToken

            if (! token) {
                return
            }

            const componentId = this.componentIdFor(el)

            // Repaint instantly from the client-side cache: after a sort or a
            // paginate, rows we already resolved should not hit the server again.
            if (componentId && this.cache.get(componentId)?.has(token)) {
                el.innerHTML = this.cache.get(componentId).get(token)

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
            const componentId = this.componentIdFor(el)

            // No enclosing Livewire component root: there is nowhere to route this
            // token, so it is dropped rather than being folded into some other
            // component's batch.
            if (! componentId) {
                return
            }

            if (! this.pending.has(componentId)) {
                this.pending.set(componentId, new Map())
            }

            const cells = this.pending.get(componentId)

            if (! cells.has(token)) {
                cells.set(token, [])
            }

            cells.get(token).push(el)
            this.schedule()
        },

        // One animation frame of debounce coalesces everything pending into as many
        // requests as there are distinct Livewire components with pending cells —
        // still exactly one request per component, no matter how many rows (including
        // several rows across several tables scrolling into view together) triggered
        // it within that frame.
        schedule() {
            if (this.scheduled) {
                return
            }

            this.scheduled = true

            requestAnimationFrame(() => {
                this.scheduled = false
                this.flush()
            })
        },

        flush() {
            if (this.pending.size === 0) {
                return
            }

            const batches = this.pending
            this.pending = new Map()

            batches.forEach((cells, componentId) => {
                let component = null

                // The component may have been removed from the page (wire:navigate
                // away, a relation manager's modal closing, ...) between enqueue and
                // this flush, or window.Livewire.find() may throw on an id it no
                // longer knows about. Either way this must not throw here, and it must
                // not stop any OTHER component's batch in this same flush from being
                // sent.
                try {
                    component = window.Livewire.find(componentId)
                } catch (error) {
                    component = null
                }

                if (! component) {
                    this.markFailed(cells)

                    return
                }

                try {
                    component.call(RESOLVE_METHOD, Array.from(cells.keys()))
                        .then((result) => this.apply(componentId, cells, result))
                        .catch(() => this.markFailed(cells))
                } catch (error) {
                    this.markFailed(cells)
                }
            })
        },

        apply(componentId, batch, result) {
            const cells = (result && result.cells) || {}

            batch.forEach((elements, token) => {
                const cell = cells[token]

                if (! cell) {
                    return
                }

                if (! cell.error) {
                    if (! this.cache.has(componentId)) {
                        this.cache.set(componentId, new Map())
                    }

                    this.cache.get(componentId).set(token, cell.html)
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

            const token = el.dataset.asyncColumnToken
            const componentId = this.componentIdFor(el)

            if (componentId) {
                this.cache.get(componentId)?.delete(token)
            }

            this.enqueue(el)
        },

        componentIdFor(el) {
            const root = el.closest('[wire\\:id]')

            return root ? root.getAttribute('wire:id') : null
        },
    })
})
