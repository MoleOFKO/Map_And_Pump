<?php include 'share_header.php'; ?>

<div class="dashboard-panel-card">
    <h3 class="text-white mb-1">Usage Log Reports</h3>
    <p class="text-secondary mb-4" style="font-size:13px;">Review historical system usage, transaction rates, and verified delivery logs.</p>

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
            <tbody class="text-white" style="font-size:13px; font-weight:500;">
                <tr class="border-bottom border-secondary border-opacity-5">
                    <td class="py-3 font-semibold text-white">TXN-29103-MP</td>
                    <td class="py-3 text-secondary">2026-07-10</td>
                    <td class="py-3">Yangon Terminal Center</td>
                    <td class="py-3">12.5 Liters</td>
                    <td class="py-3"><span class="fuel-tag fuel-95">Octane 95</span></td>
                    <td class="py-3"><span class="status-pill active" style="font-size:9px;">Delivered</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php include 'share_footer.php'; ?>