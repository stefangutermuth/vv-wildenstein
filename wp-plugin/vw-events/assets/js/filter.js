(function () {
    'use strict';

    document.querySelectorAll('.vw-events-filterbar[data-vw-filter]').forEach(initFilterBar);

    function initFilterBar(bar) {
        // Find the cards-container directly following the filter bar.
        let next = bar.nextElementSibling;
        while (next && !next.classList.contains('vw-events-list')) {
            next = next.nextElementSibling;
        }
        if (!next) return;
        const list = next;
        const status = bar.querySelector('.vw-events-filter-status');

        const state = { quick: 'all', month: '', duration: '', search: '' };

        // Pre-cache durchsuchbaren Text pro Card (Title + Where + Tags), kleingeschrieben
        const cards = Array.from(list.querySelectorAll('.vw-event-card, .vw-event-up'));
        cards.forEach((card) => {
            const parts = [];
            card.querySelectorAll('.vw-event-card-title, .vw-event-up-title, .vw-event-card-where, .vw-event-up-where, .vw-event-card-tags').forEach((el) => {
                if (el.textContent) parts.push(el.textContent);
            });
            card.dataset.searchText = parts.join(' ').toLowerCase();
            // Markiere Karten ohne Bild — wird in der Listenansicht ausgeblendet/ohne Spalte gerendert
            if (!card.querySelector('.vw-event-card-image-fg, .vw-event-up-image img')) {
                card.classList.add('has-no-image');
            }
        });

        // View-Toggle (Kacheln / Liste)
        bar.querySelectorAll('.vw-events-viewtoggle button').forEach((btn) => {
            btn.addEventListener('click', () => {
                bar.querySelectorAll('.vw-events-viewtoggle button').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                list.classList.toggle('is-list-view', btn.dataset.view === 'list');
                layoutMasonry();
            });
        });

        // Masonry: verteile sichtbare Karten reihen-weise (links→rechts) in N Spalten.
        // Spaltenanzahl bestimmt sich aus Container-Breite, Min-Spaltenbreite 240 px.
        function layoutMasonry() {
            // Bestehende Spalten auflösen und Karten in ORIGINAL-Reihenfolge zurückhängen,
            // damit Listenansicht / DOM die chronologische Sortierung behält.
            list.querySelectorAll('.vw-events-col').forEach((col) => col.remove());
            cards.forEach((card) => list.appendChild(card));
            if (list.classList.contains('is-list-view')) return;

            const visible = cards.filter((c) => !c.classList.contains('is-hidden') && c.classList.contains('vw-event-card'));
            if (visible.length === 0) return;

            const minCol = 240;
            const gap = 24; // 1.5rem
            const w = list.clientWidth || list.parentElement.clientWidth || 960;
            const N = Math.max(1, Math.min(visible.length, Math.floor((w + gap) / (minCol + gap))));

            const columns = [];
            for (let i = 0; i < N; i++) {
                const col = document.createElement('div');
                col.className = 'vw-events-col';
                columns.push(col);
                list.appendChild(col);
            }
            visible.forEach((card, i) => columns[i % N].appendChild(card));
        }

        let resizeTimer = null;
        window.addEventListener('resize', () => {
            if (resizeTimer) clearTimeout(resizeTimer);
            resizeTimer = setTimeout(layoutMasonry, 120);
        });

        // Initiale Verteilung + Re-Layout sobald Bilder ihre Höhe kennen
        layoutMasonry();
        list.querySelectorAll('img').forEach((img) => {
            if (!img.complete) img.addEventListener('load', layoutMasonry, { once: true });
        });

        // Quick-Tabs
        bar.querySelectorAll('.vw-events-quicktabs button').forEach((btn) => {
            btn.addEventListener('click', () => {
                bar.querySelectorAll('.vw-events-quicktabs button').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                state.quick = btn.dataset.quick;
                if (state.quick !== 'all') {
                    const sel = bar.querySelector('select[data-filter="month"]');
                    if (sel) sel.value = '';
                    state.month = '';
                }
                apply();
            });
        });

        // Monat-Dropdown
        const monthSel = bar.querySelector('select[data-filter="month"]');
        if (monthSel) {
            monthSel.addEventListener('change', () => {
                state.month = monthSel.value;
                if (state.month) {
                    bar.querySelectorAll('.vw-events-quicktabs button').forEach((b) => b.classList.remove('is-active'));
                    const allBtn = bar.querySelector('.vw-events-quicktabs button[data-quick="all"]');
                    if (allBtn) allBtn.classList.add('is-active');
                    state.quick = 'all';
                }
                apply();
            });
        }

        // Dauer-Dropdown (Eintägig / Mehrtägig)
        const durationSel = bar.querySelector('select[data-filter="duration"]');
        if (durationSel) {
            durationSel.addEventListener('change', () => {
                state.duration = durationSel.value;
                apply();
            });
        }

        // Live-Suche (debounced)
        const searchInput = bar.querySelector('input[data-filter="search"]');
        const clearBtn = bar.querySelector('[data-search-clear]');
        if (searchInput) {
            let timer = null;
            searchInput.addEventListener('input', () => {
                if (timer) clearTimeout(timer);
                timer = setTimeout(() => {
                    state.search = searchInput.value.trim().toLowerCase();
                    if (clearBtn) clearBtn.hidden = state.search === '';
                    apply();
                }, 80);
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
                state.search = '';
                clearBtn.hidden = true;
                apply();
            });
        }

        function inRange(card, mode) {
            const start = card.dataset.start;
            const end   = card.dataset.end || start;
            if (!start) return false;
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const sDate = new Date(start);
            const eDate = new Date(end);
            if (mode === 'today') {
                return sDate <= today && eDate >= today;
            }
            if (mode === 'week') {
                const weekEnd = new Date(today); weekEnd.setDate(today.getDate() + 6);
                return sDate <= weekEnd && eDate >= today;
            }
            if (mode === 'month') {
                const ym = today.toISOString().slice(0, 7);
                return start.startsWith(ym) || end.startsWith(ym) ||
                       (start < ym + '-01' && end >= ym + '-01');
            }
            return true;
        }

        function apply() {
            let visible = 0;

            cards.forEach((card) => {
                let show = true;

                if (state.quick !== 'all' && !inRange(card, state.quick)) show = false;
                if (show && state.month && card.dataset.month !== state.month) show = false;
                if (show && state.duration) {
                    const s = card.dataset.start;
                    const e = card.dataset.end || s;
                    const isMulti = !!s && !!e && s !== e;
                    if (state.duration === 'single' && isMulti) show = false;
                    if (state.duration === 'multi' && !isMulti) show = false;
                }
                if (show && state.search) {
                    const text = card.dataset.searchText || '';
                    if (!text.includes(state.search)) show = false;
                }

                card.classList.toggle('is-hidden', !show);
                if (show) visible++;
            });

            layoutMasonry();

            if (status) {
                if (visible === cards.length && state.quick === 'all' && !state.month && !state.duration && !state.search) {
                    status.hidden = true;
                    status.textContent = '';
                } else if (visible === 0) {
                    status.hidden = false;
                    status.textContent = 'Keine Veranstaltungen passen zu deiner Auswahl.';
                } else {
                    status.hidden = false;
                    status.textContent = visible + ' von ' + cards.length + ' Veranstaltungen angezeigt.';
                }
            }
        }
    }
})();
