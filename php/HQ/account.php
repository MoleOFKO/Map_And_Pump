
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/Style.css">
</head>
<body>
    <div class="container">
        <h2 class="title">Manage Manager Accounts</h2>
    <p class="subtitle">Control status permissions and access clearance for on-site personnel.</p>

    <div class="card">
        <h3>Manager Roster</h3>
        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding: 12px 8px;">Manager Email</th>
                    <th style="padding: 12px 8px;">Station Assignment</th>
                    <th style="padding: 12px 8px;">Status</th>
                    <th style="padding: 12px 8px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 12px 8px;">john.site9@apex.com</td>
                    <td style="padding: 12px 8px;">Site #009</td>
                    <td style="padding: 12px 8px;"><span style="color: var(--success); font-weight: bold;">Active</span></td>
                    <td style="padding: 12px 8px; text-align: right;">
                        <button class="btn btn-danger" style="padding: 6px 12px; font-size: 0.8rem;" onclick="toggleStatus(this)">Disable</button>
                    </td>
                </tr>
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 12px 8px;">ann.site14@apex.com</td>
                    <td style="padding: 12px 8px;">Site #014</td>
                    <td style="padding: 12px 8px;"><span id="ann-status" style="color: var(--success); font-weight: bold;">Active</span></td>
                    <td style="padding: 12px 8px; text-align: right;">
                        <button class="btn btn-danger" style="padding: 6px 12px; font-size: 0.8rem;" onclick="toggleStatus(this)">Disable</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    </div>
    <script>
        function toggleStatus(btn) {
            const row = btn.closest('tr');
            const statusCell = row.cells[2].querySelector('span');
            
            if (statusCell.innerText === 'Active') {
                statusCell.innerText = 'Disabled';
                statusCell.style.color = 'var(--danger)';
                btn.innerText = 'Activate';
                btn.className = 'btn btn-success';
            } else {
                statusCell.innerText = 'Active';
                statusCell.style.color = 'var(--success)';
                btn.innerText = 'Disable';
                btn.className = 'btn btn-danger';
            }
        }
    </script>
</body>
</html>