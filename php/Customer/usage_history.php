<?php include 'share_header.php'; ?>

<div class="dashboard-panel-card">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h3 class="text-white mb-1">Usage Log Reports</h3>
            <p class="text-secondary mb-0" style="font-size:13px;">Review historical system usage, transaction rates, and verified delivery logs.</p>
        </div>
        <div class="usage-date-picker">
            <label for="usage-date-filter" class="text-secondary d-block mb-1" style="font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;">Filter by date</label>
            <div class="d-flex align-items-center gap-2">
                <input
                    type="date"
                    id="usage-date-filter"
                    class="form-control custom-form-field usage-date-input"
                    max=""
                    aria-label="Choose a date to view usage history"
                >
                <button type="button" id="usage-date-clear" class="btn btn-outline-secondary btn-sm rounded-3 px-3" title="Show all dates">All</button>
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
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-07-18">
                    <td class="py-3 font-semibold text-white">TXN-30441-MP</td>
                    <td class="py-3 text-secondary">2026-07-18</td>
                    <td class="py-3">Insein Branch Station 04</td>
                    <td class="py-3">20.0 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-92">Octane 92</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
                <tr class="border-bottom border-secondary border-opacity-5" data-date="2026-07-28">
                    <td class="py-3 font-semibold text-white">TXN-31802-MP</td>
                    <td class="py-3 text-secondary">2026-07-28</td>
                    <td class="py-3">Sanchaung Fuel Hub</td>
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
    <p id="usage-empty-msg" class="text-secondary text-center py-4 mb-0 d-none" style="font-size:13px;">No usage records for the selected date.</p>
</div>

<style>
.usage-date-picker {
    flex: 0 0 auto;
}
.usage-date-input {
    width: auto;
    min-width: 160px;
    max-width: 190px;
    color-scheme: dark;
}
.usage-date-input::-webkit-calendar-picker-indicator {
    filter: invert(0.75);
    cursor: pointer;
}
</style>

<script>
(function () {
    const dateInput = document.getElementById('usage-date-filter');
    const clearBtn = document.getElementById('usage-date-clear');
    const rows = Array.from(document.querySelectorAll('#usage-log-body tr'));
    const emptyMsg = document.getElementById('usage-empty-msg');

    // Only today and past dates are selectable.
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const todayStr = `${yyyy}-${mm}-${dd}`;
    dateInput.max = todayStr;

    // Most recent date that has at least one history row.
    const lastHistoryDate = rows
        .map(row => row.getAttribute('data-date'))
        .filter(Boolean)
        .sort()
        .pop() || todayStr;

    function filterByDate(selected) {
        const activeDate = selected || lastHistoryDate;
        let visibleCount = 0;
        rows.forEach(row => {
            const rowDate = row.getAttribute('data-date');
            const show = rowDate === activeDate;
            row.classList.toggle('d-none', !show);
            if (show) visibleCount++;
        });
        emptyMsg.classList.toggle('d-none', visibleCount > 0);
    }

    dateInput.addEventListener('change', () => {
        let value = dateInput.value;
        if (value && value > todayStr) {
            value = todayStr;
            dateInput.value = todayStr;
        }
        if (!value) {
            dateInput.value = lastHistoryDate;
            value = lastHistoryDate;
        }
        filterByDate(value);
    });

    // Block typing/pasting future dates in browsers that allow free text.
    dateInput.addEventListener('input', () => {
        if (dateInput.value && dateInput.value > todayStr) {
            dateInput.value = todayStr;
        }
    });

    // "All" resets to the latest date that has history (default view).
    clearBtn.addEventListener('click', () => {
        dateInput.value = lastHistoryDate;
        filterByDate(lastHistoryDate);
    });

    // Default: show the last date that has history.
    dateInput.value = lastHistoryDate;
    filterByDate(lastHistoryDate);
})();
</script>


<?php include 'share_footer.php'; ?>
