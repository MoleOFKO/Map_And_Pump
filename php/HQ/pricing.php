<?php
require_once __DIR__ . '/hq_guard.php';
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';

$addMessage = '';
$updateMessage = '';
$errorMessage = '';
$hqId = map_pump_current_hq_id($pdo);

function normalize_search_tokens(?string $value): array
{
    if ($value === null) {
        return [];
    }

    $normalized = strtolower(trim((string) $value));
    $normalized = str_replace([',', '.'], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_filter($tokens, static fn ($token) => $token !== ''));
}

function normalize_search_text(?string $value): string
{
    if ($value === null) {
        return '';
    }

    $normalized = strtolower(trim((string) $value));
    $normalized = str_replace([',', '.'], ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    return trim($normalized);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_grade') {
        $gradeName = trim($_POST['fuel_grade'] ?? '');
        $startingPrice = trim($_POST['starting_price'] ?? '0');

        if ($gradeName === '') {
            $errorMessage = 'Please enter a fuel grade name.';
        } else {
            $gradeCountStmt = $pdo->query('SELECT COUNT(*) FROM oil_types');
            if ((int)$gradeCountStmt->fetchColumn() >= 4) {
                $errorMessage = 'Only the 4 configured fuel types are allowed.';
            } else {
            $stmt = $conn->prepare('SELECT COUNT(*) AS existing FROM oil_types WHERE LOWER(oil_name) = LOWER(?)');
            if ($stmt) {
                $stmt->bind_param('s', $gradeName);
                $stmt->execute();
                $stmt->bind_result($existing);
                $stmt->fetch();
                $stmt->close();

                if ($existing > 0) {
                    $errorMessage = 'This fuel grade already exists.';
                } else {
                    $insert = $conn->prepare('INSERT INTO oil_types (oil_name) VALUES (?)');
                    if ($insert) {
                        $insert->bind_param('s', $gradeName);
                        if ($insert->execute()) {
                            $addMessage = 'Fuel grade "' . htmlspecialchars($gradeName, ENT_QUOTES) . '" added successfully.';
                        } else {
                            $errorMessage = 'Unable to add fuel grade: ' . $conn->error;
                        }
                        $insert->close();
                    } else {
                        $errorMessage = 'Unable to prepare insert statement: ' . $conn->error;
                    }
                }
            } else {
                $errorMessage = 'Unable to prepare select statement: ' . $conn->error;
            }
            }
        }
    }

    if ($action === 'update_price') {
        $stationId = filter_input(INPUT_POST, 'station_id', FILTER_VALIDATE_INT);
        $oilTypeId = filter_input(INPUT_POST, 'oil_type_id', FILTER_VALIDATE_INT);
        $newPrice = trim($_POST['fuel_price'] ?? '');

        if (!$stationId || !$oilTypeId || $newPrice === '') {
            $errorMessage = 'Please select a station, fuel grade, and enter a price.';
        } elseif (!is_numeric($newPrice) || (int) $newPrice < 0) {
            $errorMessage = 'Please enter a valid non-negative price.';
        } elseif (!map_pump_station_in_hq($pdo, (int)$stationId, $hqId)) {
            $errorMessage = 'You can only update prices for stations under your own HQ.';
        } else {
            $priceValue = (int) $newPrice;
            try {
                map_pump_append_station_price($pdo, (int)$stationId, (int)$oilTypeId, $priceValue);
                $updateMessage = 'New station fuel price added at ' . number_format($priceValue) . ' MMK. Previous price records were kept.';
            } catch (Throwable $e) {
                $errorMessage = 'Unable to add price record: ' . $e->getMessage();
            }
        }
    }
}

$grades = [];
$stations = [];
$priceRows = [];
try {
    $gradeStmt = $pdo->query('SELECT oil_type_id, oil_name FROM oil_types ORDER BY oil_type_id LIMIT 4');
    $grades = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);

    $stationStmt = $pdo->prepare(
        "SELECT s.branch_id AS station_id, s.branch_name AS station_name
         FROM branches s
         INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
         WHERE sbs.headquarter_id = ?
           AND COALESCE(s.status, 'Active') <> 'Decommissioned'
         ORDER BY s.branch_name"
    );
    $stationStmt->execute([$hqId]);
    $stations = $stationStmt->fetchAll(PDO::FETCH_ASSOC);

    $latestInventorySql = map_pump_latest_station_inventory_sql();
    $priceStmt = $pdo->prepare(
        "SELECT s.branch_id AS station_id, s.branch_name AS station_name, ot.oil_type_id, ot.oil_name,
                li.price_per_litre AS price_per_liter, li.is_active, li.updated_at
         FROM branches s
         INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
         CROSS JOIN (SELECT oil_type_id, oil_name FROM oil_types ORDER BY oil_type_id LIMIT 4) ot
         LEFT JOIN ({$latestInventorySql}) li
            ON li.station_id = s.branch_id AND li.oil_type_id = ot.oil_type_id
         WHERE sbs.headquarter_id = ?
           AND COALESCE(s.status, 'Active') <> 'Decommissioned'
         ORDER BY s.branch_name, ot.oil_type_id"
    );
    $priceStmt->execute([$hqId]);
    $priceRows = $priceStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMessage = $errorMessage ?: 'Could not retrieve pricing data: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="../../js/hq-theme.js"></script>
<link rel="stylesheet" href="../../css/HQstyle.css">
    <style>
        .pricing-cards {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 24px;
            margin-bottom: 24px;
            align-items: stretch;
        }

        .pricing-cards .card {
            margin-bottom: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .pricing-card-inner {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .form-select {
            background-color: rgba(255, 255, 255, 0.04);
            color: var(--slate-50);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: none;
            font-size: 1.02rem;
            font-weight: 600;
            min-height: 50px;
        }

        .form-select:focus {
            outline: none;
            border-color: rgba(56, 189, 248, 0.4);
            background-color: rgba(255, 255, 255, 0.06);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
            color: var(--slate-50);
        }

        .form-select option {
            background-color: var(--navy-900);
            color: var(--slate-50);
            font-size: 1rem;
            font-weight: 500;
        }

        .pricing-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 0;
            background: transparent;
            color: var(--slate-50);
        }

        .pricing-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--card-bg, #0f172a);
            text-align: left;
            font-size: 12px;
            padding: 10px 12px;
            color: var(--slate-300);
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .pricing-table tbody td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            color: var(--slate-50);
            vertical-align: middle;
            background: transparent;
        }

        .pricing-table .price-header,
        .pricing-table .price-cell {
            white-space: nowrap;
            line-height: 1.2;
            vertical-align: middle;
        }

        .pricing-table thead th.price-header,
        .pricing-table tbody td.price-cell {
            white-space: nowrap;
            line-height: 1.2;
        }

        .pricing-table tbody tr:hover td {
            background: rgba(255, 255, 255, 0.03);
        }

        .pricing-table tbody tr:last-child td {
            border-bottom: none;
        }

        .pricing-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.4;
            white-space: nowrap;
        }

        .pricing-status-badge.bg-success {
            background: rgba(34, 197, 94, 0.18);
            color: #86efac;
        }

        .pricing-status-badge.bg-warning {
            background: rgba(250, 204, 21, 0.18);
            color: #facc15;
        }

        .price-input-wrap {
            position: relative;
        }

        .price-input-wrap .form-control {
            padding-right: 120px;
            min-height: 50px;
            font-size: 1.03rem;
            font-weight: 600;
            padding-top: 12px;
            padding-bottom: 12px;
        }

        .price-input-wrap .form-control::placeholder {
            color: var(--slate-400);
            opacity: 1;
        }

        .price-input-wrap .form-control:focus {
            background-color: rgba(255, 255, 255, 0.04);
            color: var(--slate-50);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .price-input-wrap .input-suffix {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-400);
            font-size: 0.88rem;
            font-weight: 700;
            pointer-events: none;
            white-space: nowrap;
            background: var(--card-bg);
            padding-left: 8px;
        }

        .pricing-form-stack {
            display: grid;
            gap: 18px;
            max-width: 520px;
            width: 100%;
            flex: 1;
            margin-top: 8px;
        }

        .pricing-form-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .pricing-form-field .form-label {
            margin-bottom: 0;
            font-weight: 700;
            font-size: 1.02rem;
            color: var(--slate-200);
        }

        .pricing-form-submit {
            margin-top: auto;
            padding-top: 8px;
        }

        .pricing-form-submit .btn {
            min-width: 180px;
            min-height: 48px;
            font-size: 1.02rem;
            font-weight: 700;
            padding: 0.8rem 1.2rem;
        }

        /* Pricing search bar styling */
        .pricing-search {
            position: relative;
            flex: 0 0 auto;
            width: 260px;
            max-width: 100%;
        }

        .pricing-search .pricing-search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--slate-300);
            font-size: 0.82rem;
            line-height: 1;
            opacity: 0.85;
            pointer-events: none;
        }

        .pricing-search #pricingSearch {
            display: block;
            width: 100%;
            height: 36px;
            padding: 0 36px 0 34px;
            font-size: 0.85rem;
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 999px;
            box-shadow: none;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            box-sizing: border-box;
        }

        .pricing-search #pricingSearch::placeholder {
            color: var(--slate-300);
            opacity: 0.7;
        }

        .pricing-search #pricingSearch:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(56, 189, 248, 0.45);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.16);
        }

        .pricing-search #pricingSearch::-webkit-search-cancel-button,
        .pricing-search #pricingSearch::-webkit-search-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        .pricing-search .pricing-search-clear {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
            width: 22px;
            height: 22px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: var(--slate-300);
            font-size: 0.7rem;
            line-height: 1;
            cursor: pointer;
        }

        .pricing-search .pricing-search-clear:hover {
            color: var(--slate-50);
            background: rgba(255, 255, 255, 0.08);
        }

        .pricing-search.has-value .pricing-search-clear {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Pricing table scrollable container */
        .pricing-table-container {
            max-height: 420px;
            overflow-y: auto;
            border-radius: 6px;
        }

        .pricing-table-container::-webkit-scrollbar {
            width: 8px;
        }

        .pricing-table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }

        .pricing-table-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .pricing-table-container::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        @media (max-width: 900px) {
            .pricing-cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <h2 class="title">Oil Types & Pricing Matrix</h2>
    <p class="subtitle">Configure corporate product grades and establish localized/global base pricing.</p>

    <?php
if ($addMessage || $updateMessage || $errorMessage): ?>
        <div class="alert <?php
echo $errorMessage ? 'alert-danger' : 'alert-success'; ?>" role="alert">
            <?php
echo htmlspecialchars($errorMessage ?: $addMessage ?: $updateMessage); ?>
        </div>
    <?php
endif; ?>

    <!-- <div class="card mb-4 p-4">
        <h3>Define Standard Oil Grade</h3>
        <p class="subtitle" style="margin-bottom:12px;">Declare a new standard fuel class in the system directory.</p>
        <form method="POST">
            <input type="hidden" name="action" value="create_grade">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Product Fuel Grade</label>
                    <input type="text" name="fuel_grade" class="form-control" placeholder="e.g., Premium Diesel Plus" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Starting Price (MMK)</label>
                    <input type="number" name="starting_price" class="form-control" min="0" step="100" value="0">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Fuel Grade</button>
            </div>
        </form>
    </div> -->

    <div class="pricing-cards">
        <div class="card mb-4 p-4">
            <div class="pricing-card-inner">
                <h3>Active Live Base Price</h3>
                <p class="subtitle" style="margin-bottom:12px;">Push direct modifications down to electronic station nodes.</p>
                <form method="POST" class="pricing-form-stack">
                    <input type="hidden" name="action" value="update_price">
                    <div class="pricing-form-field">
                        <label class="form-label">Select Station</label>
                        <select name="station_id" class="form-select" required>
                            <option value="">Select station</option>
                            <?php
foreach ($stations as $station): ?>
                                <option value="<?php
echo (int)$station['station_id']; ?>"><?php
echo htmlspecialchars($station['station_name']); ?></option>
                            <?php
endforeach; ?>
                        </select>
                    </div>

                    <div class="pricing-form-field">
                        <label class="form-label">Select Product Grade</label>
                        <select name="oil_type_id" class="form-select" required>
                            <option value="">Select grade</option>
                            <?php
foreach ($grades as $grade): ?>
                                <option value="<?php
echo (int)$grade['oil_type_id']; ?>"><?php
echo htmlspecialchars($grade['oil_name']); ?></option>
                            <?php
endforeach; ?>
                        </select>
                    </div>

                    <div class="pricing-form-field">
                        <label class="form-label">Active Base Pump Rate</label>
                        <div class="price-input-wrap">
                            <input type="number" name="fuel_price" class="form-control" step="1" min="0" placeholder="e.g., 3500" required>
                            <span class="input-suffix">(Kyats/L)</span>
                        </div>
                    </div>

                    <div class="pricing-form-submit">
                        <button type="submit" class="btn btn-success">Publish Base Price</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="mb-0">Current Fuel Grades</h3>
                <div class="pricing-search" id="pricingSearchWrap">
                    <i class="fa-solid fa-magnifying-glass pricing-search-icon"></i>
                    <input type="search" id="pricingSearch" class="form-control" placeholder="Search,,," autocomplete="off" aria-label="Search fuel grades and prices">
                    <button type="button" class="pricing-search-clear" id="pricingSearchClear" title="Clear search" aria-label="Clear search">
                        <i class="fa-solid fa-mark"></i>
                    </button>
                </div>
            </div>
            <div class="pricing-table-container">
                <table id="pricingGradesTable" class="pricing-table table-borderless align-middle">
                    <thead>
                        <tr>
                            <th>Station</th>
                            <th>Fuel Grade</th>
                            <th class="price-header">Price / L</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
if (count($priceRows) > 0): ?>
                            <?php
foreach ($priceRows as $grade): ?>
                                <tr data-search="<?= htmlspecialchars(strtolower(trim(
                                    ($grade['station_name'] ?? '') . ' ' .
                                    ($grade['oil_name'] ?? '') . ' ' .
                                    ($grade['price_per_liter'] ?? '') . ' ' .
                                    ((int)($grade['is_active'] ?? 0) === 1 ? 'Available' : 'Unavailable')
                                )), ENT_QUOTES) ?>">
                                    <td><?php
echo htmlspecialchars($grade['station_name']); ?></td>
                                    <td><?php
echo htmlspecialchars($grade['oil_name']); ?></td>
                                    <td><?php
echo $grade['price_per_liter'] === null ? 'Not set' : number_format((int) $grade['price_per_liter']) . ' MMK'; ?></td>
                                    <td><?php
echo ((int)($grade['is_active'] ?? 0) === 1) ? 'Available' : 'Unavailable'; ?></td>
                                </tr>
                            <?php
endforeach; ?>
                        <?php
else: ?>
                            <tr>
                                <td colspan="4">No station pricing records found.</td>
                            </tr>
                        <?php
endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Live search for the pricing table
        (function () {
            const input = document.getElementById('pricingSearch');
            const clearBtn = document.getElementById('pricingSearchClear');
            const searchWrap = document.getElementById('pricingSearchWrap');
            if (!input) return;

            const rows = Array.prototype.slice.call(document.querySelectorAll('.pricing-table tbody tr'));
            const noResults = document.createElement('tr');
            noResults.id = 'pricingNoResults';
            noResults.className = 'd-none';
            noResults.innerHTML = '<td colspan="4" class="text-center text-muted" style="padding: 24px 0;">No prices match your search.</td>';
            document.querySelector('.pricing-table tbody').appendChild(noResults);

            function updateClearButton() {
                if (input.value.trim() !== '') {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }
            }

            function applySearch() {
                const term = input.value.trim().toLowerCase();
                let visible = 0;

                const normalizedTerm = term
                    .replace(/[.,]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                const tokens = normalizedTerm === '' ? [] : normalizedTerm.split(/\s+/).filter(Boolean);

                rows.forEach(function (row) {
                    const haystack = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                    const sanitizedHaystack = haystack
                        .replace(/[.,]/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();

                    const match = tokens.length === 0 || tokens.every(function (token) {
                        return sanitizedHaystack.indexOf(token) !== -1;
                    });

                    row.classList.toggle('d-none', !match);
                    if (match) visible++;
                });

                if (noResults) {
                    noResults.classList.toggle('d-none', !(rows.length > 0 && visible === 0));
                }
                updateClearButton();
            }

            input.addEventListener('input', applySearch);
            input.addEventListener('search', applySearch);
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    input.value = '';
                    applySearch();
                }
            });
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    applySearch();
                    input.focus();
                });
            }

            applySearch();
        })();
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>
