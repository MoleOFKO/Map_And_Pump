<?php include 'share_header.php'; ?>

<div class="dashboard-panel-card">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="text-white mb-1">Usage Log Reports</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Review historical system usage, transaction rates, and verified delivery logs.</p>
        </div>

        <div class="usage-date-picker">
            <label class="text-secondary d-block mb-1" style="font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;">Filter by date range</label>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" id="usage-range-toggle" class="btn custom-form-field usage-range-toggle" aria-expanded="false" aria-controls="usage-calendar-panel">
                    <i class="bi bi-calendar3 me-2"></i>
                    <span id="usage-range-label">All dates</span>
                    <i class="bi bi-chevron-down ms-2 usage-range-caret"></i>
                </button>
                <button type="button" id="usage-date-clear" class="btn btn-outline-secondary btn-sm rounded-3 px-3" title="Show all dates">All</button>
            </div>

            <div id="usage-calendar-panel" class="usage-calendar-panel d-none" role="dialog" aria-label="Date range calendar">
                <div class="usage-cal-header">
                    <button type="button" id="usage-cal-prev" class="usage-cal-nav" aria-label="Previous month"><i class="bi bi-chevron-left"></i></button>
                    <span id="usage-cal-title" class="usage-cal-title"></span>
                    <button type="button" id="usage-cal-next" class="usage-cal-nav" aria-label="Next month"><i class="bi bi-chevron-right"></i></button>
                </div>
                <div class="usage-cal-weekdays">
                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                </div>
                <div id="usage-cal-grid" class="usage-cal-grid"></div>
                <p class="usage-cal-hint text-secondary mb-0">
                    <span class="usage-cal-dot"></span> Dates with records &nbsp;·&nbsp; Start date then end date &nbsp;·&nbsp; Click twice for only one day
                </p>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="--bs-table-bg: transparent; border-color: rgba(255,255,255,0.05);">
            <thead>
                <tr class="text-secondary border-bottom border-secondary border-opacity-15" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;">
                    <th class="pb-3 border-0">TRANSACTION ID</th>
                    <th class="pb-3 border-0">DISPATCH DATE</th>
                    <th class="pb-3 border-0">STATION LOCATION</th>
                    <th class="pb-3 border-0">VOLUME REGISTERED</th>
                    <th class="pb-3 border-0">FUEL CLASSIFICATION</th>
                    <th class="pb-3 border-0">STATUS STATE</th>
                </tr>
            </thead>
            <tbody id="usage-log-body" class="text-white" style="font-size:13px; font-weight:500;">
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-07-10">
                    <td class="py-3 font-semibold text-white">TXN-29103-MP</td>
                    <td class="py-3 text-secondary">2026-07-10</td>
                    <td class="py-3">Yangon Terminal Center</td>
                    <td class="py-3">12.5 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-95">Octane 95</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-07-22">
                    <td class="py-3 font-semibold text-white">TXN-30842-MP</td>
                    <td class="py-3 text-secondary">2026-07-22</td>
                    <td class="py-3">Sanchaung Fuel Hub</td>
                    <td class="py-3">9.0 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-92">Octane 92</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-07-28">
                    <td class="py-3 font-semibold text-white">TXN-31501-MP</td>
                    <td class="py-3 text-secondary">2026-07-28</td>
                    <td class="py-3">Insein Branch Station 04</td>
                    <td class="py-3">15.0 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-95">Octane 95</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-08-02">
                    <td class="py-3 font-semibold text-white">TXN-32915-MP</td>
                    <td class="py-3 text-secondary">2026-08-02</td>
                    <td class="py-3">Tamwe Express Station</td>
                    <td class="py-3">8.0 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-diesel">Diesel</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-08-05">
                    <td class="py-3 font-semibold text-white">TXN-33560-MP</td>
                    <td class="py-3 text-secondary">2026-08-05</td>
                    <td class="py-3">Kamayut Central Pump</td>
                    <td class="py-3">18.5 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-92">Octane 92</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <p id="usage-empty-msg" class="text-secondary text-center py-4 mb-0 d-none" style="font-size:13px;">No usage records for the selected date range.</p>
</div>

<style>
.usage-date-picker {
    position: relative;
    min-width: 220px;
}
.usage-range-toggle {
    display: inline-flex;
    align-items: center;
    min-width: 220px;
    max-width: 280px;
    text-align: left;
    cursor: pointer;
    color: var(--slate-50, #e8f3ff) !important;
    font-size: 13px;
    font-weight: 500;
}
.usage-range-toggle:hover,
.usage-range-toggle[aria-expanded="true"] {
    border-color: rgba(13, 110, 253, 0.45) !important;
}
.usage-range-caret {
    margin-left: auto;
    font-size: 11px;
    opacity: 0.7;
    transition: transform 0.2s ease;
}
.usage-range-toggle[aria-expanded="true"] .usage-range-caret {
    transform: rotate(180deg);
}

.usage-calendar-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    z-index: 40;
    width: 300px;
    padding: 14px;
    border-radius: 16px;
    background: rgba(10, 22, 44, 0.96);
    border: 1px solid rgba(13, 110, 253, 0.2);
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
}

.usage-cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.usage-cal-title {
    font-weight: 700;
    font-size: 14px;
    color: #e8f3ff;
}
.usage-cal-nav {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    border: 1px solid rgba(13, 110, 253, 0.18);
    background: rgba(13, 110, 253, 0.06);
    color: #e8f3ff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.usage-cal-nav:hover:not(:disabled) {
    background: rgba(13, 110, 253, 0.16);
    border-color: rgba(13, 110, 253, 0.4);
}
.usage-cal-nav:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.usage-cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    margin-bottom: 4px;
    text-align: center;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #8fa0dd;
}
.usage-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}
.usage-cal-day {
    position: relative;
    aspect-ratio: 1;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #c9d2e3;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}
.usage-cal-day:hover:not(:disabled):not(.is-empty) {
    background: rgba(13, 110, 253, 0.12);
    color: #fff;
}
.usage-cal-day.is-empty {
    cursor: default;
    visibility: hidden;
}
.usage-cal-day.is-disabled {
    color: #4a5568;
    cursor: not-allowed;
    opacity: 0.45;
}
.usage-cal-day.has-records::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: 4px;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #0d6efd;
    transform: translateX(-50%);
    box-shadow: 0 0 6px rgba(13, 110, 253, 0.55);
}
.usage-cal-day.has-records {
    color: #fff;
    font-weight: 700;
}
.usage-cal-day.is-in-range {
    background: rgba(13, 110, 253, 0.14);
    color: #fff;
    border-radius: 0;
}
.usage-cal-day.is-range-start,
.usage-cal-day.is-range-end,
.usage-cal-day.is-selected {
    background: #0d6efd !important;
    color: #fff !important;
    border-radius: 10px;
}
.usage-cal-day.is-range-start.is-in-range {
    border-radius: 10px 0 0 10px;
}
.usage-cal-day.is-range-end.is-in-range {
    border-radius: 0 10px 10px 0;
}
.usage-cal-day.is-range-start.is-range-end {
    border-radius: 10px;
}
.usage-cal-day.is-today:not(.is-selected):not(.is-range-start):not(.is-range-end) {
    box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.45);
}
.usage-cal-hint {
    margin-top: 12px;
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.usage-cal-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #0d6efd;
    box-shadow: 0 0 6px rgba(13, 110, 253, 0.5);
    flex: 0 0 auto;
}

body.customer-light-mode .usage-range-toggle {
    color: #102033 !important;
}
body.customer-light-mode .usage-calendar-panel {
    background: rgba(255, 255, 255, 0.98);
    border-color: rgba(11, 94, 215, 0.18);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
}
body.customer-light-mode .usage-cal-title,
body.customer-light-mode .usage-cal-nav {
    color: #102033;
}
body.customer-light-mode .usage-cal-nav {
    background: rgba(11, 94, 215, 0.06);
    border-color: rgba(11, 94, 215, 0.16);
}
body.customer-light-mode .usage-cal-day {
    color: #334155;
}
body.customer-light-mode .usage-cal-day.has-records {
    color: #0b5ed7;
}
body.customer-light-mode .usage-cal-day.is-disabled {
    color: #94a3b8;
}
body.customer-light-mode .usage-cal-day.is-in-range {
    background: rgba(11, 94, 215, 0.1);
    color: #0b5ed7;
}
body.customer-light-mode .usage-cal-day.is-range-start,
body.customer-light-mode .usage-cal-day.is-range-end,
body.customer-light-mode .usage-cal-day.is-selected {
    background: #0b5ed7 !important;
    color: #fff !important;
}
</style>

<script>
(function () {
    const rows = Array.from(document.querySelectorAll('#usage-log-body tr'));
    const emptyMsg = document.getElementById('usage-empty-msg');
    const toggleBtn = document.getElementById('usage-range-toggle');
    const clearBtn = document.getElementById('usage-date-clear');
    const panel = document.getElementById('usage-calendar-panel');
    const grid = document.getElementById('usage-cal-grid');
    const titleEl = document.getElementById('usage-cal-title');
    const prevBtn = document.getElementById('usage-cal-prev');
    const nextBtn = document.getElementById('usage-cal-next');
    const labelEl = document.getElementById('usage-range-label');

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    function toISO(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function parseISO(s) {
        const [y, m, d] = s.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const todayStr = toISO(today);

    const recordDates = new Set(
        rows.map(row => row.getAttribute('data-date')).filter(Boolean)
    );

    let viewYear = today.getFullYear();
    let viewMonth = today.getMonth(); // 0-based
    let rangeStart = null; // ISO string or null
    let rangeEnd = null;

    function formatLabel(start, end) {
        if (!start && !end) return 'All dates';
        if (start && !end) return start;
        if (start === end) return start;
        return `${start} → ${end}`;
    }

    function filterRows() {
        let visibleCount = 0;
        rows.forEach(row => {
            const rowDate = row.getAttribute('data-date');
            let show = true;
            if (rangeStart && rangeEnd) {
                show = rowDate >= rangeStart && rowDate <= rangeEnd;
            } else if (rangeStart && !rangeEnd) {
                show = rowDate === rangeStart;
            } else {
                show = true; // no selection → every history row
            }
            row.classList.toggle('d-none', !show);
            if (show) visibleCount++;
        });
        emptyMsg.classList.toggle('d-none', visibleCount > 0);
        labelEl.textContent = formatLabel(rangeStart, rangeEnd);
    }

    function renderCalendar() {
        titleEl.textContent = `${monthNames[viewMonth]} ${viewYear}`;
        grid.innerHTML = '';

        const first = new Date(viewYear, viewMonth, 1);
        const startWeekday = first.getDay(); // 0 Sun
        const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();

        // Disable next if viewing current month of today or later
        const isCurrentOrFutureMonth =
            viewYear > today.getFullYear() ||
            (viewYear === today.getFullYear() && viewMonth >= today.getMonth());
        nextBtn.disabled = isCurrentOrFutureMonth;

        for (let i = 0; i < startWeekday; i++) {
            const cell = document.createElement('button');
            cell.type = 'button';
            cell.className = 'usage-cal-day is-empty';
            cell.tabIndex = -1;
            cell.disabled = true;
            grid.appendChild(cell);
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateObj = new Date(viewYear, viewMonth, day);
            const iso = toISO(dateObj);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'usage-cal-day';
            btn.textContent = String(day);
            btn.dataset.date = iso;

            const isFuture = iso > todayStr;
            const hasRecords = recordDates.has(iso);
            const isToday = iso === todayStr;

            if (isFuture) {
                btn.classList.add('is-disabled');
                btn.disabled = true;
            }
            if (hasRecords) btn.classList.add('has-records');
            if (isToday) btn.classList.add('is-today');

            if (rangeStart && rangeEnd) {
                if (iso === rangeStart) btn.classList.add('is-range-start', 'is-selected');
                if (iso === rangeEnd) btn.classList.add('is-range-end', 'is-selected');
                if (iso > rangeStart && iso < rangeEnd) btn.classList.add('is-in-range');
                if (iso === rangeStart && iso === rangeEnd) {
                    btn.classList.add('is-range-start', 'is-range-end', 'is-selected');
                }
            } else if (rangeStart && iso === rangeStart) {
                btn.classList.add('is-selected', 'is-range-start', 'is-range-end');
            }

            if (!isFuture) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    onDayClick(iso);
                });
            }
            grid.appendChild(btn);
        }
    }

    function onDayClick(iso) {
        if (!rangeStart || (rangeStart && rangeEnd)) {
            // Start a new range — keep panel open for the end date
            rangeStart = iso;
            rangeEnd = null;
        } else {
            // Complete the range (same date twice = single-day filter)
            if (iso < rangeStart) {
                rangeEnd = rangeStart;
                rangeStart = iso;
            } else {
                rangeEnd = iso;
            }
        }
        filterRows();
        renderCalendar();
        // Only close after both ends are set (including start === end)
        if (rangeStart && rangeEnd) {
            closePanel();
        }
    }

    function openPanel() {
        panel.classList.remove('d-none');
        toggleBtn.setAttribute('aria-expanded', 'true');
        renderCalendar();
    }

    function closePanel() {
        panel.classList.add('d-none');
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    toggleBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (panel.classList.contains('d-none')) openPanel();
        else closePanel();
    });

    prevBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        viewMonth -= 1;
        if (viewMonth < 0) {
            viewMonth = 11;
            viewYear -= 1;
        }
        renderCalendar();
    });

    nextBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (nextBtn.disabled) return;
        viewMonth += 1;
        if (viewMonth > 11) {
            viewMonth = 0;
            viewYear += 1;
        }
        renderCalendar();
    });

    clearBtn.addEventListener('click', () => {
        rangeStart = null;
        rangeEnd = null;
        filterRows();
        renderCalendar();
        closePanel();
    });

    panel.addEventListener('click', (e) => e.stopPropagation());

    document.addEventListener('click', (e) => {
        if (!panel.classList.contains('d-none') && !panel.contains(e.target) && !toggleBtn.contains(e.target)) {
            closePanel();
        }
    });

    // Default: no date selected → show every history row
    filterRows();
})();
</script>

<?php include 'share_footer.php'; ?>
