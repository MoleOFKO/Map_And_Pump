<?php
require_once __DIR__ . '/../conn.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: /php/Login/login.php');
    exit;
}

function car_license_page_schema_ready(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS car_license (
                car_license_id int NOT NULL AUTO_INCREMENT,
                car_plate_number varchar(50) NOT NULL,
                car_owner_name varchar(100) DEFAULT NULL,
                car_license_front_image varchar(255) DEFAULT NULL,
                car_license_back_image varchar(255) DEFAULT NULL,
                model_year int NOT NULL,
                vehicle_name varchar(100) DEFAULT NULL,
                vehicle_type varchar(100) NOT NULL,
                chassis_number varchar(20) DEFAULT NULL,
                location varchar(255) NOT NULL,
                filled_gas int NOT NULL DEFAULT 0,
                issue_date date DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (car_license_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'car_license_front_image' => "ALTER TABLE car_license ADD COLUMN car_license_front_image varchar(255) NULL AFTER car_owner_name",
            'car_license_back_image' => "ALTER TABLE car_license ADD COLUMN car_license_back_image varchar(255) NULL AFTER car_license_front_image",
        ] as $column => $sql) {
            $columnStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'car_license'
                   AND COLUMN_NAME = ?"
            );
            $columnStmt->execute([$column]);
            if ((int) $columnStmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_car (
                user_car_id int NOT NULL AUTO_INCREMENT,
                user_id int NOT NULL,
                car_id int NOT NULL,
                PRIMARY KEY (user_car_id),
                KEY fk_user_car_user_id_idx (user_id),
                KEY fk_user_car_car_id_idx (car_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('Could not prepare car license page schema: ' . $e->getMessage());
    }
}

car_license_page_schema_ready($pdo);

$userStmt = $pdo->prepare('SELECT car_license_id FROM users WHERE user_id = ? LIMIT 1');
$userStmt->execute([$userId]);
$activeCarId = (int) ($userStmt->fetchColumn() ?: 0);

$carsStmt = $pdo->prepare(
    'SELECT c.*
     FROM user_car uc
     INNER JOIN car_license c ON c.car_license_id = uc.car_id
     WHERE uc.user_id = ?
     ORDER BY CASE WHEN c.car_license_id = ? THEN 0 ELSE 1 END, c.updated_at DESC, c.car_license_id DESC'
);
$carsStmt->execute([$userId, $activeCarId]);
$cars = $carsStmt->fetchAll(PDO::FETCH_ASSOC);

$requestedCarId = (int) ($_GET['car_id'] ?? 0);
$selectedCar = [];
foreach ($cars as $car) {
    if (($requestedCarId > 0 && (int) $car['car_license_id'] === $requestedCarId) || (!$selectedCar && (int) $car['car_license_id'] === $activeCarId)) {
        $selectedCar = $car;
        break;
    }
}
if (!$selectedCar && $cars) {
    $selectedCar = $cars[0];
}

$hasCars = count($cars) > 0;
$showForm = !$hasCars || isset($_GET['add']) || isset($_GET['update']);
$isAdding = isset($_GET['add']) || !$hasCars;
$formCar = $isAdding ? [] : $selectedCar;
$currentYear = (int) date('Y');
$today = date('Y-m-d');
$vehicleTypes = ['Sedan', 'SUV', 'Truck', 'Van', 'Motorcycle', 'Bus', 'Taxi', 'Other'];
$plateValue = strtoupper((string) ($formCar['car_plate_number'] ?? ''));

include 'share_header.php';
?>

<style>
    .car-license-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
    }
    .car-license-fact {
        border: 1px solid rgba(13, 110, 253, 0.16);
        border-radius: 14px;
        background: rgba(4, 9, 21, 0.34);
        padding: 14px;
        min-height: 84px;
    }
    .car-license-fact span {
        display: block;
        color: var(--customer-muted);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 6px;
    }
    .car-license-fact strong {
        color: #fff;
        font-size: 14px;
        word-break: break-word;
    }
    .car-active-shell {
        border: 1px solid rgba(13, 110, 253, 0.18);
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.10), rgba(4, 9, 21, 0.42));
        padding: 18px;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 14px;
        align-items: center;
    }
    .car-active-main {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }
    .car-active-icon {
        width: 54px;
        height: 54px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #7dd3fc;
        background: rgba(13, 110, 253, 0.14);
        border: 1px solid rgba(13, 110, 253, 0.24);
        flex: 0 0 auto;
        line-height: 1;
    }
    .car-active-main strong {
        display: block;
        color: #fff;
        font-family: 'Space Grotesk', 'Noto Sans Myanmar', sans-serif;
        font-size: 24px;
        line-height: 1;
        overflow-wrap: anywhere;
    }
    .car-active-main > div > span:not(.status-pill) {
        display: block;
        color: var(--customer-muted);
        font-size: 13px;
        margin-top: 7px;
    }
    .car-active-icon i {
        display: block;
        line-height: 1;
    }
    .car-switch-modal {
        z-index: 11000;
    }
    .modal-backdrop {
        z-index: 10990;
    }
    .car-switch-modal .modal-content {
        background: rgba(6, 12, 24, 0.98);
        border: 1px solid rgba(13, 110, 253, 0.22);
        border-radius: 20px;
        color: #fff;
    }
    .car-switch-modal .modal-header,
    .car-switch-modal .modal-footer {
        border-color: rgba(255, 255, 255, 0.08);
    }
    .car-switch-modal .modal-footer {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 10px;
    }
    .car-switch-modal .car-switch-grid {
        grid-template-columns: 1fr;
    }
    @media (max-width: 575.98px) {
        .car-active-shell { grid-template-columns: 1fr; }
        .car-active-shell .btn { width: 100%; }
    }
    .car-switch-shell {
        border: 1px solid rgba(13, 110, 253, 0.18);
        border-radius: 18px;
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.10), rgba(4, 9, 21, 0.42));
        padding: 18px;
    }
    .car-switch-summary {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 14px;
        align-items: center;
        margin-bottom: 16px;
    }
    .car-switch-title {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .car-switch-icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #7dd3fc;
        background: rgba(13, 110, 253, 0.14);
        border: 1px solid rgba(13, 110, 253, 0.22);
        flex: 0 0 auto;
    }
    .car-switch-title strong {
        display: block;
        color: #fff;
        font-size: 16px;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }
    .car-switch-title span {
        display: block;
        color: var(--customer-muted);
        font-size: 12px;
        margin-top: 3px;
    }
    .car-switch-count {
        color: #bfffe5;
        border: 1px solid rgba(27, 228, 148, 0.22);
        background: rgba(27, 228, 148, 0.08);
        border-radius: 999px;
        padding: 7px 11px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }
    .car-switch-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 10px;
    }
    .car-switch-option {
        position: relative;
        display: block;
        min-height: 128px;
        cursor: pointer;
    }
    .car-switch-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .car-switch-card {
        height: 100%;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 16px;
        background: rgba(4, 9, 21, 0.42);
        padding: 15px;
        transition: transform .18s ease, border-color .18s ease, background .18s ease;
    }
    .car-switch-option:hover .car-switch-card,
    .car-switch-option input:focus-visible + .car-switch-card {
        transform: translateY(-2px);
        border-color: rgba(13, 110, 253, 0.42);
        background: rgba(13, 110, 253, 0.09);
    }
    .car-switch-option input:checked + .car-switch-card {
        border-color: rgba(27, 228, 148, 0.58);
        background: linear-gradient(135deg, rgba(27, 228, 148, 0.12), rgba(13, 110, 253, 0.08));
    }
    .car-switch-card-top {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 14px;
    }
    .car-switch-plate {
        color: #fff;
        font-family: 'Space Grotesk', 'Noto Sans Myanmar', sans-serif;
        font-size: 20px;
        font-weight: 800;
        line-height: 1;
        overflow-wrap: anywhere;
    }
    .car-switch-badge {
        border-radius: 999px;
        padding: 5px 8px;
        font-size: 10px;
        font-weight: 800;
        color: var(--customer-muted);
        background: rgba(255, 255, 255, 0.06);
        white-space: nowrap;
    }
    .car-switch-option input:checked + .car-switch-card .car-switch-badge {
        color: #bfffe5;
        background: rgba(27, 228, 148, 0.12);
    }
    .car-switch-meta {
        display: grid;
        gap: 7px;
        color: var(--customer-muted);
        font-size: 12px;
    }
    .car-switch-meta span {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }
    .car-switch-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
    }
    .car-switch-hint {
        color: var(--customer-muted);
        font-size: 12px;
        min-width: 0;
        max-width: 100%;
        overflow-wrap: anywhere;
        line-height: 1.45;
        padding: 9px 11px;
        border: 1px solid rgba(13, 110, 253, 0.16);
        border-radius: 12px;
        background: rgba(13, 110, 253, 0.06);
    }
    @media (max-width: 575.98px) {
        .car-switch-summary { grid-template-columns: 1fr; }
        .car-switch-count { justify-self: start; }
        .car-switch-actions .btn { width: 100%; }
        .car-switch-modal .modal-footer { grid-template-columns: 1fr; }
        .car-switch-modal .modal-footer .btn { width: 100%; }
    }
    .car-license-image-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #7dd3fc;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
    }
    .car-license-image-link:hover {
        color: #bae6fd;
    }
</style>

<div class="row justify-content-center">
    <div class="col-xl-9">
        <div class="dashboard-panel-card">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
                <div>
                    <span class="status-pill <?php echo $hasCars ? 'active' : 'pending'; ?> mb-3"><i class="bi bi-car-front-fill"></i> <?php echo $hasCars ? 'Car License Added' : 'Car License Required'; ?></span>
                    <h3 class="text-white mb-2"><?php echo $showForm ? ($isAdding ? 'Add Car License' : 'Update Car License') : 'Car License'; ?></h3>
                    <p class="text-secondary mb-0" style="font-size:13px;"><?php echo $showForm ? 'Save the official car registration details and images.' : 'Review the active car used for booking fuel.'; ?></p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($hasCars): ?>
                        <a href="car_license.php?add=1" class="btn btn-info btn-sm rounded-3 px-3 fw-bold"><i class="bi bi-plus-lg me-1"></i>Add Car</a>
                    <?php endif; ?>
                    <?php if ($hasCars && !$showForm): ?>
                        <a href="car_license.php?update=1&car_id=<?php echo (int) ($selectedCar['car_license_id'] ?? 0); ?>" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold">Edit</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($hasCars && !$showForm): ?>
                <section class="car-active-shell mb-4" aria-label="Active car license">
                    <div class="car-active-main">
                        <span class="car-active-icon"><i class="bi bi-car-front-fill fs-4"></i></span>
                        <div>
                            <span class="status-pill active mb-2" style="font-size:10px;"><i class="bi bi-check-circle-fill"></i> Active Car</span>
                            <strong><?php echo htmlspecialchars((string) ($selectedCar['car_plate_number'] ?? 'Car License')); ?></strong>
                            <span><?php echo htmlspecialchars(trim((string) ($selectedCar['vehicle_name'] ?? 'Vehicle') . ' / ' . (string) ($selectedCar['vehicle_type'] ?? 'Type') . ' / ' . (string) ($selectedCar['model_year'] ?? ''))); ?></span>
                        </div>
                    </div>
                    <?php if (count($cars) > 1): ?>
                        <button type="button" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#carSwitchModal">
                            <i class="bi bi-arrow-left-right me-1"></i>Change Active Car
                        </button>
                    <?php else: ?>
                        <a href="car_license.php?add=1" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold"><i class="bi bi-plus-lg me-1"></i>Add Another Car</a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div id="car-license-status" role="alert" class="mb-4 d-none rounded-3 px-3 py-3" style="font-size:13px;"></div>

            <?php if ($hasCars && !$showForm): ?>
                <div class="car-license-grid mb-2">
                    <div class="car-license-fact"><span>Plate Number</span><strong><?php echo htmlspecialchars((string) $selectedCar['car_plate_number']); ?></strong></div>
                    <div class="car-license-fact"><span>Owner Name</span><strong><?php echo htmlspecialchars((string) $selectedCar['car_owner_name']); ?></strong></div>
                    <div class="car-license-fact"><span>Front Image</span><strong><?php if (!empty($selectedCar['car_license_front_image'])): ?><a class="car-license-image-link" href="<?php echo htmlspecialchars((string) $selectedCar['car_license_front_image']); ?>" target="_blank" rel="noopener"><i class="bi bi-image-fill"></i>Open front</a><?php else: ?>Not uploaded<?php endif; ?></strong></div>
                    <div class="car-license-fact"><span>Back Image</span><strong><?php if (!empty($selectedCar['car_license_back_image'])): ?><a class="car-license-image-link" href="<?php echo htmlspecialchars((string) $selectedCar['car_license_back_image']); ?>" target="_blank" rel="noopener"><i class="bi bi-image-fill"></i>Open back</a><?php else: ?>Not uploaded<?php endif; ?></strong></div>
                    <div class="car-license-fact"><span>Model Year</span><strong><?php echo htmlspecialchars((string) $selectedCar['model_year']); ?></strong></div>
                    <div class="car-license-fact"><span>Vehicle Name</span><strong><?php echo htmlspecialchars((string) $selectedCar['vehicle_name']); ?></strong></div>
                    <div class="car-license-fact"><span>Vehicle Type</span><strong><?php echo htmlspecialchars((string) $selectedCar['vehicle_type']); ?></strong></div>
                    <div class="car-license-fact"><span>Chassis Number</span><strong><?php echo htmlspecialchars((string) $selectedCar['chassis_number']); ?></strong></div>
                    <div class="car-license-fact"><span>Location</span><strong><?php echo htmlspecialchars((string) $selectedCar['location']); ?></strong></div>
                    <div class="car-license-fact"><span>Issued Date</span><strong><?php echo htmlspecialchars((string) $selectedCar['issue_date']); ?></strong></div>
                </div>
            <?php endif; ?>

            <form id="car-license-form" enctype="multipart/form-data" class="<?php echo $showForm ? '' : 'd-none'; ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="car_license_id" value="<?php echo (int) ($formCar['car_license_id'] ?? 0); ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE FRONT IMAGE</label>
                        <input type="file" name="car_license_front_image" class="form-control custom-form-field" accept="image/jpeg,image/png,image/webp" <?php echo !empty($formCar['car_license_front_image']) ? '' : 'required'; ?>>
                        <?php if (!empty($formCar['car_license_front_image'])): ?>
                            <small class="d-block mt-2">
                                <a class="car-license-image-link" href="<?php echo htmlspecialchars((string) $formCar['car_license_front_image']); ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-image-fill"></i> Current front image saved
                                </a>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE BACK IMAGE</label>
                        <input type="file" name="car_license_back_image" class="form-control custom-form-field" accept="image/jpeg,image/png,image/webp" <?php echo !empty($formCar['car_license_back_image']) ? '' : 'required'; ?>>
                        <?php if (!empty($formCar['car_license_back_image'])): ?>
                            <small class="d-block mt-2">
                                <a class="car-license-image-link" href="<?php echo htmlspecialchars((string) $formCar['car_license_back_image']); ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-image-fill"></i> Current back image saved
                                </a>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE PLATE NUMBER</label>
                        <input type="text" name="car_plate_number" id="carPlateNumber" class="form-control custom-form-field" maxlength="7" placeholder="1A/1234" value="<?php echo htmlspecialchars($plateValue); ?>" inputmode="text" autocomplete="off" required>
                        <small class="text-secondary d-block mt-2">Example: 1A/1234. Letters are capitalized and the slash is added automatically.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR OWNER NAME</label>
                        <input type="text" name="car_owner_name" class="form-control custom-form-field" maxlength="100" value="<?php echo htmlspecialchars((string) ($formCar['car_owner_name'] ?? ($_SESSION['user_name'] ?? ''))); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">MODEL YEAR</label>
                        <input type="number" name="model_year" class="form-control custom-form-field" min="1950" max="<?php echo $currentYear + 1; ?>" placeholder="2020" value="<?php echo htmlspecialchars((string) ($formCar['model_year'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">VEHICLE NAME</label>
                        <input type="text" name="vehicle_name" class="form-control custom-form-field" maxlength="100" placeholder="Honda Fit" value="<?php echo htmlspecialchars((string) ($formCar['vehicle_name'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">VEHICLE TYPE</label>
                        <select name="vehicle_type" class="form-select custom-form-field" required>
                            <option value="">Select</option>
                            <?php foreach ($vehicleTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo (($formCar['vehicle_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">CHASSIS NUMBER</label>
                        <input type="text" name="chassis_number" class="form-control custom-form-field" maxlength="20" placeholder="JHMGD3840YS000000" value="<?php echo htmlspecialchars((string) ($formCar['chassis_number'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LOCATION</label>
                        <input type="text" name="location" class="form-control custom-form-field" maxlength="255" placeholder="Yangon Region" value="<?php echo htmlspecialchars((string) ($formCar['location'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">ISSUED DATE</label>
                        <input type="date" name="issue_date" class="form-control custom-form-field" max="<?php echo htmlspecialchars($today); ?>" value="<?php echo htmlspecialchars((string) ($formCar['issue_date'] ?? $today)); ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <?php if ($showForm && $hasCars): ?>
                        <a href="car_license.php?car_id=<?php echo (int) ($selectedCar['car_license_id'] ?? 0); ?>" class="btn btn-outline-secondary px-4 py-2 fw-bold rounded-3" style="font-size:13px;">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" id="car-license-submit" class="btn btn-info px-4 py-2 fw-bold rounded-3" style="font-size:13px;">
                        <?php echo $isAdding ? 'Save Car License' : 'Update Car License'; ?> <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const carForm = document.getElementById('car-license-form');
    const carStatus = document.getElementById('car-license-status');
    const carSubmit = document.getElementById('car-license-submit');
    const carSubmitLabel = carSubmit ? carSubmit.innerHTML : '';
    const carPlateNumber = document.getElementById('carPlateNumber');
    const carSwitchModal = document.getElementById('carSwitchModal');

    if (carSwitchModal && carSwitchModal.parentElement !== document.body) {
        document.body.appendChild(carSwitchModal);
    }

    function normalizeCarPlate(value) {
        const cleaned = value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 6);
        const first = cleaned.charAt(0).replace(/[^1-9]/g, '');
        const letter = cleaned.charAt(1).replace(/[^A-Z]/g, '');
        const digits = cleaned.slice(2).replace(/\D/g, '').slice(0, 4);
        return first + letter + (digits ? '/' + digits : '');
    }

    function syncCarPlate() {
        if (!carPlateNumber) return;
        const normalized = normalizeCarPlate(carPlateNumber.value);
        if (carPlateNumber.value !== normalized) {
            carPlateNumber.value = normalized;
        }
        const match = normalized.match(/^([1-9][A-Z])\/(\d{4})$/);
        const numberPart = match ? Number(match[2]) : 0;
        carPlateNumber.setCustomValidity(match && numberPart >= 1111 && numberPart <= 9999 ? '' : 'Use the format 1A/1234. The first number must be 1-9 and the last four digits must be 1111-9999.');
    }

    if (carPlateNumber) {
        carPlateNumber.addEventListener('input', syncCarPlate);
        syncCarPlate();
    }

    const carSwitchForm = document.getElementById('car-switch-form');
    const carSwitchButton = document.getElementById('car-switch-submit');
    const carSwitchHint = document.getElementById('car-switch-hint');
    const carSwitchCurrentId = carSwitchForm ? Number(carSwitchForm.dataset.currentCar || 0) : 0;
    const carSwitchInputs = carSwitchForm ? Array.from(carSwitchForm.querySelectorAll('input[name="car_license_id"]')) : [];

    function refreshCarSwitchState() {
        if (!carSwitchForm || !carSwitchButton) return;
        const selected = carSwitchInputs.find((input) => input.checked);
        const selectedId = selected ? Number(selected.value || 0) : 0;
        const hasChanged = selectedId > 0 && selectedId !== carSwitchCurrentId;
        carSwitchButton.disabled = !hasChanged;
        if (carSwitchHint) {
            carSwitchHint.textContent = hasChanged
                ? 'Ready to make this the car for your next booking.'
                : (carSwitchInputs.length > 1 ? 'Choose another saved car to use it for your next fuel booking.' : 'This is your only saved car.');
        }
    }

    carSwitchInputs.forEach((input) => input.addEventListener('change', refreshCarSwitchState));
    refreshCarSwitchState();

    document.querySelectorAll('input[type="date"]').forEach((field) => {
        field.addEventListener('focus', () => field.showPicker?.());
        field.addEventListener('click', () => field.showPicker?.());
    });

    function showCarStatus(message, ok = false) {
        if (!carStatus) return;
        carStatus.textContent = message;
        carStatus.classList.toggle('d-none', !message);
        carStatus.style.background = ok ? 'rgba(27, 228, 148, 0.1)' : 'rgba(255, 77, 106, 0.1)';
        carStatus.style.border = ok ? '1px solid rgba(27, 228, 148, 0.25)' : '1px solid rgba(255, 77, 106, 0.25)';
        carStatus.style.color = ok ? '#bfffe5' : '#ffd4dc';
    }

    async function submitCarForm(form, submitButton, defaultLabel) {
        syncCarPlate();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        showCarStatus('');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }

        try {
            const response = await fetch('save_car_license.php', {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!result.ok) {
                showCarStatus(result.message || 'Could not save your car license.');
                if (result.redirect) {
                    window.setTimeout(() => window.location.href = result.redirect, 900);
                }
                return;
            }
            showCarStatus(result.message || 'Car license saved.', true);
            window.setTimeout(() => window.location.href = result.redirect || 'profile_page.php', 650);
        } catch (error) {
            showCarStatus('Could not contact the server. Please try again.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = defaultLabel;
            }
        }
    }

    if (carForm) {
        carForm.addEventListener('submit', (event) => {
            event.preventDefault();
            submitCarForm(carForm, carSubmit, carSubmitLabel);
        });
    }
    if (carSwitchForm) {
        carSwitchForm.addEventListener('submit', (event) => {
            event.preventDefault();
            submitCarForm(carSwitchForm, carSwitchForm.querySelector('button[type="submit"]'), 'Switch Car');
        });
    }
});
</script>



                <?php if (count($cars) > 1): ?>
                    <div class="modal fade car-switch-modal" id="carSwitchModal" tabindex="-1" aria-labelledby="carSwitchModalLabel" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <form id="car-switch-form" method="POST" data-current-car="<?php echo (int) $activeCarId; ?>">
                                    <div class="modal-header px-4 pt-4">
                                        <div>
                                            <h5 class="modal-title fw-bold" id="carSwitchModalLabel"><i class="bi bi-car-front-fill txt-neon-cyan me-2"></i>Choose Active Car</h5>
                                            <p class="text-white-50 small mb-0 mt-1">This car will be used for the next fuel booking.</p>
                                        </div>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body px-4">
                                        <input type="hidden" name="action" value="switch">
                                        <div class="car-switch-grid" role="radiogroup" aria-label="Choose active car">
                                            <?php foreach ($cars as $car): ?>
                                                <?php
                                                    $carId = (int) $car['car_license_id'];
                                                    $isActiveCar = $carId === (int) $activeCarId;
                                                    $vehicleLabel = trim((string) ($car['vehicle_name'] ?? 'Vehicle')) ?: 'Vehicle';
                                                    $typeLabel = trim((string) ($car['vehicle_type'] ?? 'Vehicle')) ?: 'Vehicle';
                                                    $locationLabel = trim((string) ($car['location'] ?? 'Location not set')) ?: 'Location not set';
                                                ?>
                                                <label class="car-switch-option">
                                                    <input type="radio" name="car_license_id" value="<?php echo $carId; ?>" <?php echo $isActiveCar ? 'checked' : ''; ?> required>
                                                    <span class="car-switch-card">
                                                        <span class="car-switch-card-top">
                                                            <span class="car-switch-plate"><?php echo htmlspecialchars((string) $car['car_plate_number']); ?></span>
                                                            <span class="car-switch-badge"><?php echo $isActiveCar ? 'Active' : 'Saved'; ?></span>
                                                        </span>
                                                        <span class="car-switch-meta">
                                                            <span><i class="bi bi-car-front"></i><?php echo htmlspecialchars($vehicleLabel . ' / ' . $typeLabel); ?></span>
                                                            <span><i class="bi bi-calendar3"></i><?php echo htmlspecialchars((string) ($car['model_year'] ?? 'Year not set')); ?></span>
                                                            <span><i class="bi bi-geo-alt"></i><?php echo htmlspecialchars($locationLabel); ?></span>
                                                        </span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer px-4 pb-4">
                                        <span class="car-switch-hint" id="car-switch-hint">Choose another saved car to use it for your next fuel booking.</span>
                                        <button type="button" class="btn btn-outline-light rounded-3 px-3" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-info rounded-3 px-3 fw-bold" id="car-switch-submit" disabled>
                                            <i class="bi bi-check2-circle me-1"></i>Use Selected Car
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
<?php include 'share_footer.php'; ?>
