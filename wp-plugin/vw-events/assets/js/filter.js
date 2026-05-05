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

        const state = { quick: 'all', month: '', search: '' };

        // Pre-cache durchsuchbaren Text pro Card (Title + Where + Tags), kleingeschrieben
        const cards = Array.from(list.querySelectorAll('.vw-event-card, .vw-event-up'));
        cards.forEach((card) => {
            const parts = [];
            card.querySelectorAll('.vw-event-card-title, .vw-event-up-title, .vw-event-card-where, .vw-event-up-where, .vw-event-card-tags').forEach((el) => {
                if (el.textContent) parts.push(el.textContent);
            });
            card.dataset.searchText = parts.join(' ').toLowerCase();
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
                if (show && state.search) {
                    const text = card.dataset.searchText || '';
                    if (!text.includes(state.search)) show = false;
                }

                card.classList.toggle('is-hidden', !show);
                if (show) visible++;
            });

            if (status) {
                if (visible === cards.length && state.quick === 'all' && !state.month && !state.search) {
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
