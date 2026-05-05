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

        const state = { quick: 'all', month: '', standort: '', category: '' };

        // Quick-Tabs (Heute / Diese Woche / Diesen Monat / Alle)
        bar.querySelectorAll('.vw-events-quicktabs button').forEach((btn) => {
            btn.addEventListener('click', () => {
                bar.querySelectorAll('.vw-events-quicktabs button').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                state.quick = btn.dataset.quick;
                if (state.quick !== 'all') {
                    // Quicktabs überschreiben Monatsdropdown
                    const sel = bar.querySelector('select[data-filter="month"]');
                    if (sel) { sel.value = ''; }
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
                    // Monat überschreibt Quicktabs
                    bar.querySelectorAll('.vw-events-quicktabs button').forEach((b) => b.classList.remove('is-active'));
                    const allBtn = bar.querySelector('.vw-events-quicktabs button[data-quick="all"]');
                    if (allBtn) allBtn.classList.add('is-active');
                    state.quick = 'all';
                }
                apply();
            });
        }

        // Standort + Kategorie Pills
        bar.querySelectorAll('.vw-events-pills').forEach((group) => {
            group.querySelectorAll('button').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const key = btn.dataset.filter;
                    group.querySelectorAll('button').forEach((b) => b.classList.remove('is-active'));
                    btn.classList.add('is-active');
                    state[key] = btn.dataset.value || '';
                    apply();
                });
            });
        });

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
            const cards = list.querySelectorAll('.vw-event-card, .vw-event-up');
            let visible = 0;

            cards.forEach((card) => {
                let show = true;

                if (state.quick !== 'all' && !inRange(card, state.quick)) show = false;
                if (state.month && card.dataset.month !== state.month) show = false;
                if (state.standort) {
                    const slugs = (card.dataset.standort || '').split(' ');
                    // verband-weit zählt überall
                    if (!slugs.includes(state.standort) && !slugs.includes('verband-weit')) show = false;
                }
                if (state.category) {
                    const cats = (card.dataset.category || '').split(' ');
                    if (!cats.includes(state.category)) show = false;
                }

                card.classList.toggle('is-hidden', !show);
                if (show) visible++;
            });

            if (status) {
                if (visible === cards.length) {
                    status.hidden = true;
                    status.textContent = '';
                } else if (visible === 0) {
                    status.hidden = false;
                    status.textContent = 'Keine Veranstaltungen passen zu deinen Filtern.';
                } else {
                    status.hidden = false;
                    status.textContent = visible + ' von ' + cards.length + ' Veranstaltungen angezeigt.';
                }
            }
        }
    }
})();
