<?php
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/car_access.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../Login/login.php');
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
                filled_gas decimal(10,2) NOT NULL DEFAULT 0.00,
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
customer_car_schema_ready($pdo);

$activeCarId = customer_active_car_id($pdo, $userId);

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
    .image-dropzone {
        border: 2px dashed rgba(13, 110, 253, 0.35);
        border-radius: 14px;
        background: rgba(4, 9, 21, 0.34);
        padding: 16px;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s ease, background 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .image-dropzone:hover, .image-dropzone.dragover {
        border-color: rgba(13, 110, 253, 0.8);
        background: rgba(13, 110, 253, 0.08);
    }
    .image-dropzone input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    .image-dropzone-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        color: var(--customer-muted);
        font-size: 12px;
        pointer-events: none;
    }
    .image-dropzone-content i {
        font-size: 24px;
        color: #7dd3fc;
    }
    .image-preview-thumb {
        max-height: 90px;
        border-radius: 8px;
        object-fit: contain;
        margin-top: 6px;
        display: none;
    }
    .car-license-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }
    .car-license-fact {
        border: 1px solid rgba(13, 110, 253, 0.16);
        border-radius: 12px;
        background: rgba(4, 9, 21, 0.34);
        padding: 12px;
    }
    .car-license-fact span {
        display: block;
        color: var(--customer-muted);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 4px;
    }
    .car-license-fact strong {
        color: #fff;
        font-size: 13px;
        word-break: break-word;
    }
    .car-active-shell {
        border: 1px solid rgba(13, 110, 253, 0.18);
        border-radius: 16px;
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.10), rgba(4, 9, 21, 0.42));
        padding: 16px;
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
    .car-switch-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
    }
    .car-switch-option {
        position: relative;
        display: block;
        cursor: pointer;
    }
    .car-switch-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .car-switch-card {
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        background: rgba(4, 9, 21, 0.42);
        padding: 12px 15px;
        transition: all .18s ease;
    }
    .car-switch-option input:checked + .car-switch-card {
        border-color: rgba(27, 228, 148, 0.58);
        background: linear-gradient(135deg, rgba(27, 228, 148, 0.12), rgba(13, 110, 253, 0.08));
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

<div class="container-fluid py-3">
    <div class="row g-4">
        <!-- Sidebar Quick Info / Active Car Overview -->
        <div class="col-lg-4">
            <div class="dashboard-panel-card h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="status-pill <?php echo $hasCars ? 'active' : 'pending'; ?>">
                            <i class="bi bi-car-front-fill"></i> <?php echo $hasCars ? 'Registered' : 'Action Required'; ?>
                        </span>
                        <?php if ($hasCars && !$showForm): ?>
                            <a href="car_license.php?update=1&car_id=<?php echo (int) ($selectedCar['car_license_id'] ?? 0); ?>" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold">Edit Details</a>
                        <?php endif; ?>
                    </div>
                    <h4 class="text-white fw-bold mb-1">Car Management</h4>
                    <p class="text-secondary small mb-4">Manage your official vehicle registration details and documents for fuel bookings.</p>

                    <?php if ($hasCars): ?>
                        <div class="car-active-shell mb-3">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="p-3 rounded-3 bg-info bg-opacity-10 text-info border border-info border-opacity-25">
                                    <i class="bi bi-car-front-fill fs-4"></i>
                                </div>
                                <div>
                                    <span class="text-muted small d-block">Active Plate</span>
                                    <h5 class="text-white fw-bold mb-0"><?php echo htmlspecialchars((string) ($selectedCar['car_plate_number'] ?? '')); ?></h5>
                                </div>
                            </div>
                            <div class="small text-secondary mb-3">
                                <div><strong>Vehicle:</strong> <?php echo htmlspecialchars((string) ($selectedCar['vehicle_name'] ?? '')); ?> (<?php echo htmlspecialchars((string) ($selectedCar['vehicle_type'] ?? '')); ?>)</div>
                                <div><strong>Model Year:</strong> <?php echo htmlspecialchars((string) ($selectedCar['model_year'] ?? '')); ?></div>
                            </div>
                            
                            <?php if (count($cars) > 1): ?>
                                <button type="button" class="btn btn-outline-info w-100 btn-sm rounded-3 fw-bold py-2" data-bs-toggle="modal" data-bs-target="#carSwitchModal">
                                    <i class="bi bi-arrow-left-right me-1"></i> Switch Active Car
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3">
                    <?php if ($hasCars && !$showForm): ?>
                        <a href="car_license.php?add=1" class="btn btn-info w-100 rounded-3 fw-bold py-2 shadow-sm">
                            <i class="bi bi-plus-lg me-1"></i> Register Another Car
                        </a>
                    <?php elseif ($showForm && $hasCars): ?>
                        <a href="car_license.php?car_id=<?php echo (int) ($selectedCar['car_license_id'] ?? 0); ?>" class="btn btn-outline-secondary w-100 rounded-3 fw-bold py-2">
                            Cancel & Return
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Workspace Panel -->
        <div class="col-lg-8">
            <div class="dashboard-panel-card">
                <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom border-secondary border-opacity-10">
                    <h5 class="text-white fw-bold mb-0">
                        <?php echo $showForm ? ($isAdding ? '<i class="bi bi-plus-circle text-info me-2"></i>Add Car License Form' : '<i class="bi bi-pencil-square text-info me-2"></i>Update Car License') : '<i class="bi bi-card-checklist text-info me-2"></i>Vehicle Overview'; ?>
                    </h5>
                    <span class="text-muted small">Secure Record</span>
                </div>

                <div id="car-license-status" role="alert" class="mb-4 d-none rounded-3 px-3 py-3" style="font-size:13px;"></div>

                <!-- Read-Only View Grid -->
                <?php if ($hasCars && !$showForm): ?>
                    <div class="car-license-grid mb-3">
                        <div class="car-license-fact"><span>Plate Number</span><strong><?php echo htmlspecialchars((string) $selectedCar['car_plate_number']); ?></strong></div>
                        <div class="car-license-fact"><span>Owner Name</span><strong><?php echo htmlspecialchars((string) $selectedCar['car_owner_name']); ?></strong></div>
                        <div class="car-license-fact"><span>Model Year</span><strong><?php echo htmlspecialchars((string) $selectedCar['model_year']); ?></strong></div>
                        <div class="car-license-fact"><span>Vehicle Name</span><strong><?php echo htmlspecialchars((string) $selectedCar['vehicle_name']); ?></strong></div>
                        <div class="car-license-fact"><span>Vehicle Type</span><strong><?php echo htmlspecialchars((string) $selectedCar['vehicle_type']); ?></strong></div>
                        <div class="car-license-fact"><span>Chassis Number</span><strong><?php echo htmlspecialchars((string) $selectedCar['chassis_number']); ?></strong></div>
                        <div class="car-license-fact"><span>Location</span><strong><?php echo htmlspecialchars((string) $selectedCar['location']); ?></strong></div>
                        <div class="car-license-fact"><span>Issued Date</span><strong><?php echo htmlspecialchars((string) $selectedCar['issue_date']); ?></strong></div>
                    </div>
                    
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <div class="car-license-fact h-150">
                                <span>Front License Image</span>
                                <div class="mt-2">
                                    <?php if (!empty($selectedCar['car_license_front_image'])): ?>
                                        <a class="car-license-image-link d-inline-flex align-items-center gap-2" href="<?php echo htmlspecialchars((string) $selectedCar['car_license_front_image']); ?>" target="_blank" rel="noopener">
                                            <img src="<?php echo htmlspecialchars((string) $selectedCar['car_license_front_image']); ?>" alt="Front License" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
                                            <span><i class="bi bi-image-fill"></i> View Full Front Document</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Not uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="car-license-fact h-150">
                                <span>Back License Image</span>
                                <div class="mt-2">
                                    <?php if (!empty($selectedCar['car_license_back_image'])): ?>
                                        <a class="car-license-image-link d-inline-flex align-items-center gap-2" href="<?php echo htmlspecialchars((string) $selectedCar['car_license_back_image']); ?>" target="_blank" rel="noopener">
                                            <img src="<?php echo htmlspecialchars((string) $selectedCar['car_license_back_image']); ?>" alt="Back License" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
                                            <span><i class="bi bi-image-fill"></i> View Full Back Document</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Not uploaded</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Editable Form View -->
                <form id="car-license-form" enctype="multipart/form-data" class="<?php echo $showForm ? '' : 'd-none'; ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="car_license_id" value="<?php echo (int) ($formCar['car_license_id'] ?? 0); ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE FRONT IMAGE</label>
                            <div class="image-dropzone" id="frontImageDropzone">
                                <input type="file" name="car_license_front_image" id="frontImageInput" accept="image/jpeg,image/png,image/webp" <?php echo !empty($formCar['car_license_front_image']) ? '' : 'required'; ?>>
                                <div class="image-dropzone-content" id="frontDropzoneContent">
                                    <i class="bi bi-cloud-arrow-up-fill"></i>
                                    <span><strong>Drag & drop</strong> or browse front image</span>
                                    <small class="text-secondary" style="font-size: 10px;">JPG, PNG, WEBP</small>
                                </div>
                                <img id="frontImagePreview" class="image-preview-thumb" alt="Front Preview">
                            </div>
                            <?php if (!empty($formCar['car_license_front_image'])): ?>
                                <div class="mt-2">
                                    <a class="car-license-image-link d-inline-flex align-items-center gap-2" href="<?php echo htmlspecialchars((string) $formCar['car_license_front_image']); ?>" target="_blank" rel="noopener">
                                        <img src="<?php echo htmlspecialchars((string) $formCar['car_license_front_image']); ?>" alt="Front License" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
                                        <span><i class="bi bi-image-fill"></i> Current front image saved[cite: 1]</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE BACK IMAGE</label>
                            <div class="image-dropzone" id="backImageDropzone">
                                <input type="file" name="car_license_back_image" id="backImageInput" accept="image/jpeg,image/png,image/webp" <?php echo !empty($formCar['car_license_back_image']) ? '' : 'required'; ?>>
                                <div class="image-dropzone-content" id="backDropzoneContent">
                                    <i class="bi bi-cloud-arrow-up-fill"></i>
                                    <span><strong>Drag & drop</strong> or browse back image</span>
                                    <small class="text-secondary" style="font-size: 10px;">JPG, PNG, WEBP</small>
                                </div>
                                <img id="backImagePreview" class="image-preview-thumb" alt="Back Preview">
                            </div>
                            <?php if (!empty($formCar['car_license_back_image'])): ?>
                                <div class="mt-2">
                                    <a class="car-license-image-link d-inline-flex align-items-center gap-2" href="<?php echo htmlspecialchars((string) $formCar['car_license_back_image']); ?>" target="_blank" rel="noopener">
                                        <img src="<?php echo htmlspecialchars((string) $formCar['car_license_back_image']); ?>" alt="Back License" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(255,255,255,0.2);">
                                        <span><i class="bi bi-image-fill"></i> Current back image saved[cite: 1]</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-bold" style="font-size:11px;">CAR LICENSE PLATE NUMBER</label>
                            <input type="text" name="car_plate_number" id="carPlateNumber" class="form-control custom-form-field" maxlength="7" placeholder="1A/1234" value="<?php echo htmlspecialchars($plateValue); ?>" inputmode="text" autocomplete="off" required>
                            <small class="text-secondary d-block mt-1" style="font-size: 11px;">Example: 1A/1234. Formatted automatically.</small>
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
                        <div class="col-md-12">
                            <label class="form-label text-secondary fw-bold" style="font-size:11px;">ISSUED DATE</label>
                            <input type="date" name="issue_date" class="form-control custom-form-field" max="<?php echo htmlspecialchars($today); ?>" value="<?php echo htmlspecialchars((string) ($formCar['issue_date'] ?? $today)); ?>" required>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top border-secondary border-opacity-10">
                        <?php if ($showForm && $hasCars): ?>
                            <a href="car_license.php?car_id=<?php echo (int) ($selectedCar['car_license_id'] ?? 0); ?>" class="btn btn-outline-secondary px-4 py-2 fw-bold rounded-3" style="font-size:13px;">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" id="car-license-submit" class="btn btn-info px-4 py-2 fw-bold rounded-3 shadow-sm" style="font-size:13px;">
                            <?php echo $isAdding ? 'Save Car License' : 'Update Car License'; ?> <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function setupImageDropzone(dropzoneId, inputId, contentId, previewId) {
        const dropzone = document.getElementById(dropzoneId);
        const input = document.getElementById(inputId);
        const content = document.getElementById(contentId);
        const preview = document.getElementById(previewId);

        if (!dropzone || !input) return;

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                input.files = files;
                updatePreview(files[0]);
            }
        });

        input.addEventListener('change', () => {
            if (input.files.length > 0) {
                updatePreview(input.files[0]);
            }
        });

        function updatePreview(file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                preview.src = e.target.result;
                preview.style.display = 'block';
                content.style.display = 'none';
            };
            reader.readAsDataURL(file);
        }
    }

    setupImageDropzone('frontImageDropzone', 'frontImageInput', 'frontDropzoneContent', 'frontImagePreview');
    setupImageDropzone('backImageDropzone', 'backImageInput', 'backDropzoneContent', 'backImagePreview');

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
            carPlateNumber.setCustomValidity(match && numberPart >= 1111 && numberPart <= 9999 ? '' : 'Use the format 1A/1234.');
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
                carSwitchHint.textContent = hasChanged ? 'Ready to switch.' : 'Choose another saved car.';
            }
        }

        carSwitchInputs.forEach((input) => input.addEventListener('change', refreshCarSwitchState));
        refreshCarSwitchState();

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
                            <h5 class="modal-title fw-bold" id="carSwitchModalLabel"><i class="bi bi-car-front-fill text-info me-2"></i>Choose Active Car</h5>
                            <p class="text-white-50 small mb-0 mt-1">This car will be used for your next fuel booking.</p>
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
                                    <span class="car-switch-card d-block">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-white fw-bold fs-5"><?php echo htmlspecialchars((string) $car['car_plate_number']); ?></span>
                                            <span class="badge <?php echo $isActiveCar ? 'bg-success bg-opacity-25 text-success' : 'bg-secondary bg-opacity-25 text-white-50'; ?>"><?php echo $isActiveCar ? 'Active' : 'Saved'; ?></span>
                                        </div>
                                        <div class="text-secondary small">
                                            <div><i class="bi bi-car-front me-1"></i><?php echo htmlspecialchars($vehicleLabel . ' / ' . $typeLabel); ?></div>
                                            <div><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($locationLabel); ?></div>
                                        </div>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer px-4 pb-4">
                        <span class="car-switch-hint text-secondary small flex-grow-1" id="car-switch-hint">Select a car to switch.</span>
                        <button type="button" class="btn btn-outline-light rounded-3 px-3 btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info rounded-3 px-3 fw-bold btn-sm" id="car-switch-submit" disabled>
                            <i class="bi bi-check2-circle me-1"></i>Switch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'share_footer.php'; ?>
