<?php
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_access.php';
require_once __DIR__ . '/../shared/privacy_display.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../Login/login.php');
    exit;
}

$stmt = $pdo->prepare(
    'SELECT l.*
     FROM users u
     LEFT JOIN user_license l ON l.license_id = u.license_id
     WHERE u.user_id = ?
     LIMIT 1'
);
$stmt->execute([$userId]);
$license = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$hasLicense = !empty($license['license_id']);
$customerLicenseImageUrl = static function (?string $imagePath): string {
    $imagePath = trim(str_replace('\\', '/', (string) $imagePath));
    if ($imagePath === '' || preg_match('#^(https?:)?//#i', $imagePath) || str_starts_with($imagePath, 'data:')) {
        return $imagePath;
    }
    $imagePath = preg_replace('#^(?:\.\./)+#', '', ltrim($imagePath, '/')) ?? $imagePath;
    if (str_starts_with($imagePath, 'Customer/uploads/licenses/')) {
        return '/php/' . $imagePath;
    }
    if (str_starts_with($imagePath, 'uploads/')) {
        return '/php/Customer/' . $imagePath;
    }
    return '/' . ltrim($imagePath, '/');
};
$licenseImageUrl = $customerLicenseImageUrl((string) ($license['license_image'] ?? ''));
$faceImageUrl = $customerLicenseImageUrl((string) ($license['face_image'] ?? ''));
$licenseAccess = customer_license_access($pdo, $userId);
$licenseStatus = (string) ($license['status'] ?? $licenseAccess['status'] ?? 'pending');
$isApproved = (bool) ($licenseAccess['is_approved'] ?? false);
$statusLabel = customer_license_status_label($licenseStatus);
$showForm = !$hasLicense || isset($_GET['update']);
$statusPillClass = match (strtolower($licenseStatus)) {
    'approved', 'confirmed', 'active' => 'active',
    'expired' => 'danger',
    'rejected' => 'danger',
    default => 'pending',
};

$licenseNumberValue = strtoupper((string) ($license['license_number'] ?? ''));
$licenseParts = ['type' => '', 'serial' => '', 'year' => ''];
if (preg_match('/^([LABCDET])\/(\d{5})\/(\d{2})$/', $licenseNumberValue, $matches)) {
    $licenseParts = ['type' => $matches[1], 'serial' => $matches[2], 'year' => $matches[3]];
}

$phoneValue = (string) ($license['phone_number'] ?? '');

$nrcValue = strtoupper((string) ($license['nrc_number'] ?? ''));
$nrcParts = ['state' => '', 'township' => '', 'type' => 'N', 'number' => ''];
if (preg_match('/^(1[0-4]|[1-9])\/([A-Z]{3,9})\(([A-Z])\)(\d{6})$/', $nrcValue, $matches)) {
    $nrcParts = ['state' => $matches[1], 'township' => $matches[2], 'type' => $matches[3], 'number' => $matches[4]];
}

$currentYearShort = (int) date('y');
$today = date('Y-m-d');
$nrcTownshipsByState = [
    1 => ['BaMaNa', 'KhaHpaNa', 'DaHpaYa', 'HaPaNa', 'HpaKaNa', 'AhGaYa', 'KaMaTa', 'KaPaTa', 'KhaLaHpa', 'LaGaNa', 'MaKhaBa', 'MaSaNa', 'MaKaTa', 'MaNyaNa', 'MaMaNa', 'MaKaNa', 'MaLaNa', 'NaMaNa', 'PaWaNa', 'PaNaDa', 'PaTaAh', 'SaDaNa', 'YaBaYa', 'YaKaNa', 'SaBaNa', 'SaPaYa', 'TaNaNa', 'TaSaLa', 'WaMaNa'],
    2 => ['BaLaKha', 'DaMaSa', 'HpaSaNa', 'HpaYaSa', 'LaKaNa', 'MaSaNa', 'YaTaNa', 'YaThaNa'],
    3 => ['BaGaLa', 'LaBaNa', 'BaAhNa', 'HpaPaNa', 'BaThaSa', 'KaMaMa', 'KaKaYa', 'KaDaNa', 'KaSaKa', 'KaDaTa', 'LaThaNa', 'MaWaTa', 'PaKaNa', 'YaYaTha', 'SaKaLa', 'ThaTaNa', 'ThaTaKa', 'WaLaMa'],
    4 => ['KaKhaNa', 'HpaLaNa', 'HaKhaNa', 'KaPaLa', 'MaTaPa', 'MaTaNa', 'PaLaWa', 'YaZaNa', 'YaKhaDa', 'SaMaNa', 'TaTaNa', 'HtaTaLa', 'TaZaNa'],
    5 => ['AhYaTa', 'BaMaNa', 'BaTaLa', 'KhaOuTa', 'KhaTaNa', 'HaMaLa', 'AhTaNa', 'KaLaHta', 'KaLaWa', 'KaBaLa', 'KaNaNa', 'KaThaNa', 'KaLaTa', 'KhaOuNa', 'KaLaNa', 'LaHaNa', 'LaYaNa', 'MaLaNa', 'MaKaNa', 'MaYaNa', 'MaMaNa', 'MaMaTa', 'NaYaNa', 'NgaZaNa', 'PaLaNa', 'HpaPaNa', 'PaLaBa', 'SaKaNa', 'SaLaKa', 'YaBaNa', 'DaPaYa', 'TaMaNa', 'TaSaNa', 'HtaKhaNa', 'WaLaNa', 'WaThaNa', 'YaOuNa', 'YaMaPa', 'KaMaNa', 'KhaPaNa'],
    6 => ['BaPaNa', 'HtaWaNa', 'KaLaAh', 'KaThaNa', 'KaSaNa', 'LaLaNa', 'MaMaNa', 'PaLaNa', 'TaThaYa', 'ThaYaKha', 'YaHpaNa', 'KhaMaNa', 'MaTaNa', 'PaLaTa', 'KaYaYa'],
    7 => ['DaOuNa', 'KaPaKa', 'KaWaNa', 'KaKaNa', 'KaTaKha', 'LaPaTa', 'MaLaNa', 'MaNyaNa', 'NaTaLa', 'NyaLaPa', 'AhHpaNa', 'AhTaNa', 'PaTaNa', 'PaKhaTa', 'PaKhaNa', 'PaTaTa', 'PaNaKa', 'HpaMaNa', 'PaMaNa', 'YaTaNa', 'YaKaNa', 'HtaTaPa', 'TaNgaNa', 'ThaNaPa', 'ThaWaTa', 'ThaKaNa', 'ThaSaNa', 'WaMaNa', 'YaTaYa', 'ZaKaNa', 'PaTaSa'],
    8 => ['AhLaNa', 'KhaMaNa', 'GaGaNa', 'KaMaNa', 'MaKaNa', 'MaBaNa', 'MaTaNa', 'MaLaNa', 'MaMaNa', 'MaHtaNa', 'MaThaNa', 'NaMaNa', 'NgaHpaNa', 'PaKhaKa', 'PaMaNa', 'PaHpaNa', 'SaLaNa', 'SaMaNa', 'SaHpaNa', 'SaTaYa', 'SaPaWa', 'TaTaKa', 'ThaYaNa', 'HtaLaNa', 'YaNaKha', 'YaSaKa', 'KaHtaNa'],
    9 => ['AhMaYa', 'AhMaZa', 'KhaAhZa', 'KhaMaSa', 'KaPaTa', 'KaSaNa', 'MaTaYa', 'MaHaMa', 'MaLaNa', 'MaHtaLa', 'MaKaNa', 'MaKhaNa', 'MaThaNa', 'NaHtaKa', 'NgaThaYa', 'NgaZaNa', 'NyaOuNa', 'PaThaKa', 'PaBaNa', 'PaKaKha', 'PaOuLa', 'SaKaNa', 'SaKaTa', 'ThaPaKa', 'TaTaOu', 'TaThaNa', 'ThaSaNa', 'WaTaNa', 'YaMaTha', 'TaKaTa', 'MaMaNa', 'DaKhaTha', 'LaWaNa', 'OuTaTha', 'PaBaTha', 'PaMaNa', 'TaKaNa', 'ZaBaTha', 'ZaYaTha'],
    10 => ['BaLaNa', 'KhaSaNa', 'KhaZaNa', 'KaMaYa', 'KaHtaNa', 'LaMaNa', 'MaLaMa', 'MaDaNa', 'PaMaNa', 'ThaHpaYa', 'ThaHtaNa', 'YaMaNa'],
    11 => ['AhMaNa', 'BaThaTa', 'GaMaNa', 'KaHpaNa', 'KaTaNa', 'MaAhTa', 'MaTaNa', 'MaPaNa', 'MaAhNa', 'MaOuNa', 'MaPaTa', 'PaTaNa', 'PaNaTa', 'YaBaNa', 'YaThaTa', 'SaTaNa', 'ThaTaNa', 'TaKaNa', 'KaTaLa', 'TaPaWa', 'BaTaHta'],
    12 => ['AhLaNa', 'BaHaNa', 'KaKaKa', 'DaGaYa', 'DaGaMa', 'DaGaSa', 'DaGaTa', 'DaGaNa', 'DaLaNa', 'DaPaNa', 'LaThaYa', 'LaMaNa', 'LaKaNa', 'MaBaNa', 'HtaTaPa', 'AhSaNa', 'KaMaYa', 'KaMaNa', 'KhaYaNa', 'KaKhaKa', 'KaTaTa', 'KaTaNa', 'KaMaTa', 'LaMaTa', 'LaThaNa', 'MaYaKa', 'MaGaDa', 'MaGaTa', 'OuKaMa', 'PaBaTa', 'PaZaTa', 'SaKhaNa', 'SaKaKha', 'SaKaNa', 'YaPaTha', 'OuKaTa', 'TaTaHta', 'TaKaNa', 'TaMaNa', 'ThaKaTa', 'ThaLaNa', 'ThaGaKa', 'ThaKhaNa', 'TaTaNa', 'YaKaNa', 'OuKaNa'],
    13 => ['AhKhaNa', 'KhaYaHa', 'KhaMaNa', 'HaTaNa', 'HaPaNa', 'HaPaTa', 'SaHpaNa', 'ThaNaNa', 'SaSaNa', 'ThaPaNa', 'KaLaHpa', 'KaLaNa', 'KaLaDa', 'KaMaSa', 'KaTaNa', 'KaYaNa', 'KaTaTa', 'KaHaNa', 'KaLaTa', 'KaKhaNa', 'KaMaNa', 'KaTaLa', 'KaThaNa', 'LaKhaNa', 'LaKhaTa', 'LaYaNa', 'LaKaNa', 'LaHaNa', 'LaLaNa', 'MaBaNa', 'MaMaSa', 'MaTaNa', 'MaTaTa', 'MaMaNa', 'MaHpaNa', 'MaKaNa', 'MaPaNa', 'MaSaNa', 'MaYaNa', 'MaKhaNa', 'MaLaNa', 'MaMaTa', 'MaNaNa', 'MaYaTa', 'MaSaTa', 'NaKhaWa', 'NaTaNa', 'NaKhaNa', 'NaMaTa', 'NaHpaNa', 'NaSaNa', 'NaKaNa', 'NaWaNa', 'NaPhaNa', 'NaKhaTa', 'NyaYaNa', 'PaKhaNa', 'PaYaNa', 'PaSaNa', 'PaWaNa', 'HpaKhaNa', 'PaTaYa', 'PaLaNa', 'TaKhaLa', 'TaYaNa', 'TaKaNa', 'YaLaNa', 'YaSaNa', 'YaHpaNa', 'YaNgaNa', 'NaTaYa', 'PaLaTa', 'KhaLaNa', 'MaHaYa', 'PaPaKa', 'TaMaNya', 'MaBaTa', 'MaNgaNa', 'AhTaNa', 'TaLaNa'],
    14 => ['AhIMaTa', 'BaKaLa', 'DaNaHpa', 'DaDaYa', 'AhMaNa', 'HaKaKa', 'HaThaTa', 'AhGaPa', 'KaKaHta', 'KaLaNa', 'KaKhaNa', 'KaKaNa', 'KaPaNa', 'LaPaTa', 'LaMaNa', 'MaAhPa', 'MaMaKa', 'MaAhNa', 'MaMaNa', 'NgaPaTa', 'NgaThaKha', 'NgaYaKa', 'NgaSaNa', 'NgaThaYa', 'NyaTaNa', 'PaTaNa', 'PaThaNa', 'HpaPaNa', 'PaSaLa', 'YaThaYa', 'ThaPaNa', 'WaKhaMa', 'YaKaNa', 'ZaLaNa'],
];
include 'share_header.php';
?>

<style>
    .license-dropzone {
        border: 2px dashed rgba(13, 110, 253, 0.35);
        border-radius: 14px;
        background: rgba(4, 9, 21, 0.3);
        padding: 24px;
        text-align: center;
        position: relative;
        cursor: pointer;
        transition: all 0.2s ease-in-out;
    }
    .license-dropzone:hover, .license-dropzone.dragover {
        border-color: rgba(13, 110, 253, 0.8);
        background: rgba(13, 110, 253, 0.05);
    }
    .license-dropzone input[type="file"] {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }
    .license-dropzone-preview {
        max-height: 140px;
        border-radius: 8px;
        margin-top: 12px;
        display: none;
        object-fit: contain;
    }
    .license-saved-documents { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr)); gap: 16px; }
    .license-saved-document { display: block; min-width: 0; padding: 12px; border: 1px solid rgba(13, 110, 253, 0.2); border-radius: 10px; background: rgba(10, 22, 44, 0.4); color: inherit; text-decoration: none; }
    .license-saved-document img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: contain; border-radius: 6px; background: rgba(0, 0, 0, 0.3); }
    .license-saved-document span { display: block; margin-top: 9px; color: #d7e3ef; font-size: 12px; font-weight: 600; }
    /* .license-face-capture {
        border: 1px solid var(--customer-border);
        border-radius: 18px;
        background: rgba(4, 9, 21, 0.46);
        padding: 18px;
    }
    .license-face-frame {
        position: relative;
        width: min(100%, 360px);
        aspect-ratio: 1;
        margin: 0 auto;
        border-radius: 50%;
        overflow: hidden;
        background: rgba(13, 110, 253, 0.08);
        border: 2px solid rgba(13, 110, 253, 0.22);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04), 0 18px 42px rgba(0, 0, 0, 0.25);
    }
    .license-face-video,
    .license-face-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transform: scaleX(-1);
    }
    .license-face-preview {
        display: none;
    }
    .license-face-frame.has-capture .license-face-video,
    .license-face-frame.has-capture .license-face-guide {
        display: none;
    }
    .license-face-frame.has-capture .license-face-preview {
        display: block;
    }
    .license-face-guide {
        pointer-events: none;
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background:
            linear-gradient(to bottom, transparent 49%, rgba(13, 110, 253, 0.95) 49%, rgba(13, 110, 253, 0.95) 50%, transparent 50%),
            radial-gradient(ellipse at center, transparent 54%, rgba(4, 9, 21, 0.46) 55%);
    }
    .license-face-guide::before,
    .license-face-guide::after {
        content: "";
        position: absolute;
        top: 47%;
        width: 44px;
        height: 20px;
        border-top: 2px solid rgba(27, 228, 148, 0.95);
        border-radius: 50%;
    }
    .license-face-guide::before {
        left: 27%;
    }
    .license-face-guide::after {
        right: 27%;
    }
    .license-face-placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--customer-muted);
        font-size: 13px;
        text-align: center;
        padding: 28px;
    } */
    .license-face-capture {
        border: 1px solid rgba(13, 110, 253, 0.2);
        border-radius: 20px;
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.05) 0%, rgba(4, 9, 21, 0.6) 100%);
        padding: 24px;
        backdrop-filter: blur(10px);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
    }
    .license-face-frame {
        position: relative;
        width: min(100%, 260px);
        aspect-ratio: 1;
        margin: 0 auto;
        border-radius: 50%;
        overflow: hidden;
        background: rgba(4, 9, 21, 0.8);
        /* Modern multi-layered glowing border effect */
        border: 3px solid rgba(13, 110, 253, 0.4);
        box-shadow: 
            0 0 0 6px rgba(13, 110, 253, 0.08),
            0 12px 35px rgba(0, 0, 0, 0.4),
            inset 0 0 20px rgba(13, 110, 253, 0.15);
    }
    .license-face-video,
    .license-face-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transform: scaleX(-1);
    }
    .license-face-preview {
        display: none;
    }
    .license-face-frame.has-capture .license-face-video,
    .license-face-frame.has-capture .license-face-guide {
        display: none;
    }
    .license-face-frame.has-capture .license-face-preview {
        display: block;
    }
    .license-face-guide {
        pointer-events: none;
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 2px dashed rgba(27, 228, 148, 0.6);
        animation: facePulse 3s infinite linear;
    }
    @keyframes facePulse {
        0% { transform: scale(0.98); opacity: 0.7; }
        50% { transform: scale(1.02); opacity: 1; }
        100% { transform: scale(0.98); opacity: 0.7; }
    }
    .license-face-placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: var(--customer-muted);
        font-size: 13px;
        text-align: center;
        padding: 24px;
        background: radial-gradient(circle, rgba(13,110,253,0.08) 0%, rgba(4,9,21,0.95) 100%);
    }
    .license-composite-field {
        display: flex;
        align-items: stretch;
        gap: 6px;
    }
    .license-composite-field .license-separator {
        align-self: center;
        color: var(--customer-muted);
        font-weight: 700;
        line-height: 1;
    }
    .license-code-select { flex: 0 0 78px !important; min-width: 78px; }
    .license-serial-input { flex: 0 0 96px !important; min-width: 96px; text-align: center; }
    .license-year-input { flex: 0 0 66px !important; min-width: 66px; text-align: center; }
    .nrc-state-select { flex: 0 0 70px !important; min-width: 70px; }
    .nrc-township-select { flex: 0 0 150px !important; width: 150px; min-width: 150px; }
    .nrc-type-select { flex: 0 0 76px !important; min-width: 76px; }
    .nrc-digits-input { flex: 0 0 108px !important; min-width: 108px; text-align: center; }
    @media (max-width: 575.98px) {
        .license-composite-field { flex-wrap: wrap; }
        .nrc-township-select { flex-basis: 100% !important; }
    }
</style>

<div class="row justify-content-center">
    <div class="col-xl-9">
        <div class="dashboard-panel-card">
            <div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
                <div>
                    <span class="status-pill <?php echo htmlspecialchars($hasLicense ? $statusPillClass : 'pending'); ?> mb-3"><i class="bi bi-person-vcard-fill"></i> <?php echo htmlspecialchars($hasLicense ? $statusLabel : 'License Required'); ?></span>
                    <h3 class="text-white mb-2"><?php echo $hasLicense ? 'Update Driver License' : 'Add Driver License'; ?></h3>
                    <p class="text-secondary mb-0" style="font-size:13px;">
                        <?php echo $isApproved
                            ? 'Your driver license is confirmed. Updating these details will send it back for Moderator review.'
                            : 'Only this Driver License page is available until a Moderator confirms your license.'; ?>
                    </p>
                </div>
            </div>

            <?php if ($hasLicense): ?>
                <div class="mb-4 rounded-3 px-3 py-3 d-flex align-items-center justify-content-between gap-3 flex-wrap" style="font-size:13px;background:rgba(10,22,44,.55);border:1px solid rgba(13,110,253,.18);">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="status-pill <?php echo htmlspecialchars($statusPillClass); ?>" style="font-size:10px;">
                            <i class="bi <?php echo $isApproved ? 'bi-check-circle-fill' : 'bi-hourglass-split'; ?>"></i>
                            <?php echo htmlspecialchars($statusLabel); ?>
                        </span>
                        <span class="text-white fw-semibold"><?php echo htmlspecialchars((string) ($license['license_number'] ?? 'Driver License'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="text-secondary"><?php echo htmlspecialchars((string) ($license['license_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($showForm): ?>
                        <a href="license.php" class="btn btn-outline-secondary btn-sm rounded-3 px-3">Cancel Update</a>
                    <?php else: ?>
                        <a href="license.php?update=1" class="btn btn-outline-info btn-sm rounded-3 px-3 fw-bold">Update</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hasLicense && ($licenseImageUrl !== '' || $faceImageUrl !== '')): ?>
                <section class="mb-4" aria-label="Saved license documents">
                    <h5 class="text-white mb-3" style="font-size:14px;">Saved license documents</h5>
                    <div class="license-saved-documents">
                        <?php if ($licenseImageUrl !== ''): ?>
                            <a class="license-saved-document" href="<?php echo htmlspecialchars($licenseImageUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($licenseImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Saved driver license" loading="lazy">
                                <span>Driver license image</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($faceImageUrl !== ''): ?>
                            <a class="license-saved-document" href="<?php echo htmlspecialchars($faceImageUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo htmlspecialchars($faceImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Saved face verification photo" loading="lazy">
                                <span>Face verification photo</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div id="license-status" role="alert" class="mb-4 d-none rounded-3 px-3 py-3" style="font-size:13px;"></div>

            <form id="license-form" enctype="multipart/form-data" class="<?php echo $showForm ? '' : 'd-none'; ?>">
                <div class="row g-3">
                    <!-- <div class="col-12">
                        <div class="license-face-capture">
                            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                                <div>
                                    <label class="form-label text-secondary fw-bold mb-1" style="font-size:11px;">LIVE FACE VERIFICATION</label>
                                    <p class="text-secondary mb-0" style="font-size:13px;">Use the camera and align both eyes with the middle guide before taking the photo.</p>
                                </div>
                                <?php if ($hasLicense && !empty($license['face_image'])): ?>
                                    <span class="status-pill pending" style="font-size:10px;"><i class="bi bi-camera-fill"></i> Current photo saved</span>
                                <?php endif; ?>
                            </div>

                            <div class="license-face-frame" id="faceFrame">
                                <video id="faceVideo" class="license-face-video" autoplay playsinline muted></video>
                                <img id="facePreview" class="license-face-preview" alt="Captured face verification photo">
                                <div id="facePlaceholder" class="license-face-placeholder">Camera preview will appear here.</div>
                                <div class="license-face-guide"></div>
                            </div>
                            <canvas id="faceCanvas" class="d-none"></canvas>
                            <input type="hidden" id="licenseImageData" name="license_image_data">

                            <div class="d-flex justify-content-center gap-2 flex-wrap mt-3">
                                <button type="button" id="startCameraBtn" class="btn btn-outline-info btn-sm rounded-3 fw-bold">
                                    <i class="bi bi-camera-video-fill me-1"></i>Start Camera
                                </button>
                                <button type="button" id="captureFaceBtn" class="btn btn-info btn-sm rounded-3 fw-bold" disabled>
                                    <i class="bi bi-camera-fill me-1"></i>Take Photo
                                </button>
                                <button type="button" id="retakeFaceBtn" class="btn btn-outline-secondary btn-sm rounded-3 fw-bold d-none">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Retake
                                </button>
                            </div>
                            <small id="faceCaptureHelp" class="text-secondary d-block text-center mt-2">A real-time camera photo is required before saving.</small>
                        </div>
                    </div> -->
                    <div class="col-12">
                        <div class="license-face-capture">
                            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-info bg-opacity-10 text-info" style="width: 44px; height: 44px; font-size: 18px;">
                                        <i class="bi bi-person-bounding-box"></i>
                                    </div>
                                    <div>
                                        <h5 class="text-white mb-1" style="font-size: 15px;">Live Face Verification</h5>
                                        <p class="text-secondary mb-0" style="font-size: 12px;">Center your face inside the glowing frame to verify identity.</p>
                                    </div>
                                </div>
                                <?php if ($hasLicense && !empty($license['face_image'])): ?>
                                    <span class="status-pill pending" style="font-size:10px;"><i class="bi bi-camera-fill"></i> Current photo saved</span>
                                <?php endif; ?>
                            </div>

                            <div class="license-face-frame mb-3" id="faceFrame">
                                <video id="faceVideo" class="license-face-video" autoplay playsinline muted></video>
                                <img id="facePreview" class="license-face-preview" alt="Captured face verification photo">
                                <div id="facePlaceholder" class="license-face-placeholder">
                                    <i class="bi bi-camera text-info fs-2 mb-2 opacity-75"></i>
                                    <span style="font-size: 12px;">Camera is ready</span>
                                </div>
                                <div class="license-face-guide"></div>
                            </div>
                            <canvas id="faceCanvas" class="d-none"></canvas>
                            <input type="hidden" id="licenseImageData" name="license_image_data">

                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <button type="button" id="startCameraBtn" class="btn btn-outline-info btn-sm rounded-pill px-4 fw-bold shadow-sm">
                                    <i class="bi bi-camera-video-fill me-1"></i>Start Camera
                                </button>
                                <button type="button" id="captureFaceBtn" class="btn btn-info btn-sm rounded-pill px-4 fw-bold shadow-sm" disabled>
                                    <i class="bi bi-camera-fill me-1"></i>Take Photo
                                </button>
                                <button type="button" id="retakeFaceBtn" class="btn btn-outline-secondary btn-sm rounded-pill px-4 fw-bold shadow-sm d-none">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Retake
                                </button>
                            </div>
                            <small id="faceCaptureHelp" class="text-secondary d-block text-center mt-2" style="font-size: 11px;">Make sure your face is well-lit and unobstructed.</small>
                        </div>
                    </div>
                    <!-- <div class="col-12">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">DRIVER LICENSE IMAGE</label>
                        <input type="file" name="license_image" class="form-control custom-form-field" accept="image/jpeg,image/png,image/webp" <?php echo $hasLicense ? '' : 'required'; ?>>
                        <?php if ($hasLicense && !empty($license['license_image'])): ?>
                            <small class="text-secondary d-block mt-2">Current license image saved. Upload a new one only if the license card/document changed.</small>
                        <?php endif; ?>
                    </div> -->
                    <div class="col-12">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">DRIVER LICENSE IMAGE</label>
                        <div class="license-dropzone" id="licenseDropzone">
                            <div id="dropzonePrompt">
                                <i class="bi bi-cloud-arrow-up text-info fs-1 mb-2 d-block"></i>
                                <span class="text-white fw-semibold d-block">Drag & drop your license image here</span>
                                <span class="text-secondary" style="font-size: 12px;">or <span class="text-info text-decoration-underline">browse from your device</span></span>
                            </div>
                            <img id="licenseFilePreview" class="license-dropzone-preview mx-auto" alt="License preview">
                            <input type="file" name="license_image" id="licenseImageInput" accept="image/jpeg,image/png,image/webp" <?php echo $hasLicense ? '' : 'required'; ?>>
                        </div>
                        <?php if ($hasLicense && !empty($license['license_image'])): ?>
                            <small class="text-secondary d-block mt-2">Current license image saved. Upload a new one only if the license card/document changed.</small>
                        <?php else: ?>
                            <small id="fileNameDisplay" class="text-secondary d-block mt-2">Supported formats: JPEG, PNG, WEBP.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE NUMBER</label>
                        <input type="hidden" name="license_number" id="licenseNumber" value="<?php echo htmlspecialchars($licenseNumberValue); ?>">
                        <div class="license-composite-field">
                            <select id="licenseType" class="form-select custom-form-field license-code-select" aria-label="License type" required>
                                <option value="">Type</option>
                                <?php foreach (['L', 'A', 'B', 'C', 'D', 'E', 'T'] as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $licenseParts['type'] === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="license-separator">/</span>
                            <input type="text" id="licenseSerial" class="form-control custom-form-field license-serial-input" inputmode="numeric" pattern="\d{5}" maxlength="5" placeholder="00000" value="<?php echo htmlspecialchars($licenseParts['serial']); ?>" aria-label="Five digit license number" required>
                            <span class="license-separator">/</span>
                            <input type="text" id="licenseYear" class="form-control custom-form-field license-year-input" inputmode="numeric" pattern="\d{2}" maxlength="2" placeholder="<?php echo htmlspecialchars(str_pad((string) $currentYearShort, 2, '0', STR_PAD_LEFT)); ?>" value="<?php echo htmlspecialchars($licenseParts['year']); ?>" aria-label="License year" required>
                        </div>
                        <small class="text-secondary d-block mt-2">Format: type/5 digits/year, for example L/12345/26.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE NAME</label>
                        <input type="text" name="license_name" class="form-control custom-form-field" maxlength="255" value="<?php echo htmlspecialchars((string) ($license['license_name'] ?? ($_SESSION['user_name'] ?? ''))); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">PHONE NUMBER</label>
                        <input type="tel" name="phone_number" id="phoneNumber" class="form-control custom-form-field" maxlength="13" placeholder="09... or +959..." value="<?php echo htmlspecialchars($phoneValue); ?>" required>
                        <small class="text-secondary d-block mt-2">Must start with 09 or +959. Prefix is not counted in the 9 digit limit.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">NRC NUMBER</label>
                        <input type="hidden" name="nrc_number" id="nrcNumber" value="<?php echo htmlspecialchars($nrcValue); ?>">
                        <div class="license-composite-field">
                            <select id="nrcState" class="form-select custom-form-field nrc-state-select" aria-label="NRC state" required>
                                <option value="">No.</option>
                                <?php for ($state = 1; $state <= 14; $state++): ?>
                                    <option value="<?php echo $state; ?>" <?php echo $nrcParts['state'] === (string) $state ? 'selected' : ''; ?>><?php echo $state; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="license-separator">/</span>
                            <select id="nrcTownship" class="form-select custom-form-field nrc-township-select" aria-label="NRC township" required>
                                <option value="">Township</option>
                                <?php foreach ($nrcTownshipsByState[(int) $nrcParts['state']] ?? [] as $township): ?>
                                    <option value="<?php echo htmlspecialchars(strtoupper($township), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $nrcParts['township'] === strtoupper($township) ? 'selected' : ''; ?>><?php echo htmlspecialchars($township, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="nrcType" class="form-select custom-form-field nrc-type-select" aria-label="NRC citizen type" required>
                                <?php foreach (['N', 'E', 'P', 'T'] as $type): ?>
                                    <option value="<?php echo $type; ?>" <?php echo $nrcParts['type'] === $type ? 'selected' : ''; ?>>(<?php echo $type; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="nrcDigits" class="form-control custom-form-field nrc-digits-input" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="123456" value="<?php echo htmlspecialchars($nrcParts['number']); ?>" aria-label="Six digit NRC number" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">DATE OF BIRTH</label>
                        <input type="date" name="date_of_birth" class="form-control custom-form-field" max="<?php echo htmlspecialchars($today); ?>" value="<?php echo htmlspecialchars((string) ($license['date_of_birth'] ?? '')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">BLOOD TYPE</label>
                        <select name="blood_type" class="form-select custom-form-field" required>
                            <option value="">Select</option>
                            <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo (($license['blood_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary fw-bold" style="font-size:11px;">LICENSE LAST VALID DATE</label>
                        <input type="date" name="license_last_valid_date" class="form-control custom-form-field" min="<?php echo htmlspecialchars($today); ?>" value="<?php echo htmlspecialchars((string) ($license['license_last_valid_date'] ?? '')); ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" id="license-submit" class="btn btn-info px-4 py-2 fw-bold rounded-3" style="font-size:13px;">
                        <?php echo $hasLicense ? 'Update License' : 'Save License'; ?> <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const licenseForm = document.getElementById('license-form');
    const licenseStatus = document.getElementById('license-status');
    const licenseSubmit = document.getElementById('license-submit');
    const licenseSubmitLabel = licenseSubmit.innerHTML;
    const faceFrame = document.getElementById('faceFrame');
    const faceVideo = document.getElementById('faceVideo');
    const facePreview = document.getElementById('facePreview');
    const faceCanvas = document.getElementById('faceCanvas');
    const facePlaceholder = document.getElementById('facePlaceholder');
    const licenseImageData = document.getElementById('licenseImageData');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const captureFaceBtn = document.getElementById('captureFaceBtn');
    const retakeFaceBtn = document.getElementById('retakeFaceBtn');
    const faceCaptureHelp = document.getElementById('faceCaptureHelp');
    const licenseNumber = document.getElementById('licenseNumber');
    const licenseType = document.getElementById('licenseType');
    const licenseSerial = document.getElementById('licenseSerial');
    const licenseYear = document.getElementById('licenseYear');
    const phoneNumber = document.getElementById('phoneNumber');
    const nrcNumber = document.getElementById('nrcNumber');
    const nrcState = document.getElementById('nrcState');
    const nrcTownship = document.getElementById('nrcTownship');
    const nrcType = document.getElementById('nrcType');
    const nrcDigits = document.getElementById('nrcDigits');
    const nrcTownshipsByState = <?php echo json_encode($nrcTownshipsByState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const currentLicenseYear = <?php echo (int) $currentYearShort; ?>;
    let faceStream = null;

    function populateNrcTownships(selectedTownship = '') {
        nrcTownship.replaceChildren(new Option('Township', ''));
        (nrcTownshipsByState[nrcState.value] || []).forEach((township) => {
            const code = township.toUpperCase();
            nrcTownship.add(new Option(township, code, false, code === selectedTownship));
        });
    }

    populateNrcTownships(nrcTownship.value);

    function digitsOnly(input, limit) {
        input.value = input.value.replace(/\D/g, '').slice(0, limit);
    }

    function syncLicenseNumber() {
        digitsOnly(licenseSerial, 5);
        digitsOnly(licenseYear, 2);
        const yearValue = licenseYear.value.length === 2 ? Number(licenseYear.value) : NaN;
        licenseYear.setCustomValidity(!Number.isNaN(yearValue) && yearValue > currentLicenseYear ? 'License year cannot be later than the current year.' : '');
        licenseNumber.value = licenseType.value && licenseSerial.value && licenseYear.value
            ? `${licenseType.value}/${licenseSerial.value}/${licenseYear.value}`
            : '';
    }

    function syncPhoneNumber() {
        const cleaned = phoneNumber.value.replace(/(?!^)\+/g, '').replace(/[^\d+]/g, '').slice(0, 13);
        if (phoneNumber.value !== cleaned) {
            phoneNumber.value = cleaned;
        }
        const match = phoneNumber.value.match(/^(09|\+959)(\d*)$/);
        const invalid = phoneNumber.value !== '' && (!match || match[2].length < 1 || match[2].length > 9);
        phoneNumber.setCustomValidity(invalid ? 'Invalid phone number. Use 09 or +959, then up to 9 digits.' : '');
    }

    function syncNrcNumber() {
        digitsOnly(nrcDigits, 6);
        nrcNumber.value = nrcState.value && nrcTownship.value && nrcType.value && nrcDigits.value
            ? `${nrcState.value}/${nrcTownship.value}(${nrcType.value})${nrcDigits.value}`
            : '';
    }


    document.querySelectorAll('input[type="date"]').forEach((field) => {
        field.addEventListener('focus', () => field.showPicker?.());
        field.addEventListener('click', () => field.showPicker?.());
    });
    [licenseType, licenseSerial, licenseYear].forEach((field) => field.addEventListener('input', syncLicenseNumber));
    phoneNumber.addEventListener('input', syncPhoneNumber);
    [nrcState, nrcTownship, nrcType, nrcDigits].forEach((field) => field.addEventListener('input', syncNrcNumber));
    nrcState.addEventListener('change', () => populateNrcTownships());
    [licenseType, nrcState, nrcTownship, nrcType].forEach((field) => field.addEventListener('change', () => {
        syncLicenseNumber();
        syncPhoneNumber();
        syncNrcNumber();
    }));
    syncLicenseNumber();
    syncPhoneNumber();
    syncNrcNumber();

    function showLicenseStatus(message, ok = false, warning = false) {
        licenseStatus.textContent = message;
        licenseStatus.classList.toggle('d-none', !message);
        licenseStatus.style.background = warning ? 'rgba(255, 193, 7, 0.12)' : (ok ? 'rgba(27, 228, 148, 0.1)' : 'rgba(255, 77, 106, 0.1)');
        licenseStatus.style.border = warning ? '1px solid rgba(255, 193, 7, 0.3)' : (ok ? '1px solid rgba(27, 228, 148, 0.25)' : '1px solid rgba(255, 77, 106, 0.25)');
        licenseStatus.style.color = warning ? '#ffe6a3' : (ok ? '#bfffe5' : '#ffd4dc');
    }

    async function startFaceCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            showLicenseStatus('Your browser does not support live camera capture.');
            return;
        }

        try {
            faceStream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {
                    facingMode: 'user',
                    width: { ideal: 720 },
                    height: { ideal: 720 }
                }
            });
            faceVideo.srcObject = faceStream;
            await faceVideo.play();
            facePlaceholder.classList.add('d-none');
            faceFrame.classList.remove('has-capture');
            captureFaceBtn.disabled = false;
            startCameraBtn.classList.add('d-none');
            retakeFaceBtn.classList.add('d-none');
            faceCaptureHelp.textContent = 'Keep your face centered and place your eyes on the middle guide.';
        } catch (error) {
            showLicenseStatus('Camera permission is required to take the live face photo.');
        }
    }

    function stopFaceCamera() {
        if (faceStream) {
            faceStream.getTracks().forEach((track) => track.stop());
            faceStream = null;
        }
        faceVideo.srcObject = null;
    }
    const dropzone = document.getElementById('licenseDropzone');
    const fileInput = document.getElementById('licenseImageInput');
    const filePreview = document.getElementById('licenseFilePreview');
    const dropzonePrompt = document.getElementById('dropzonePrompt');
    const fileNameDisplay = document.getElementById('fileNameDisplay');

    if (dropzone && fileInput) {
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
                fileInput.files = files;
                handleFileSelection(files[0]);
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (fileInput.files.length > 0) {
                handleFileSelection(fileInput.files[0]);
            }
        });
    }

    function handleFileSelection(file) {
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                filePreview.src = e.target.result;
                filePreview.style.display = 'block';
                dropzonePrompt.style.display = 'none';
            }
            reader.readAsDataURL(file);
            if (fileNameDisplay) {
                fileNameDisplay.textContent = `Selected file: ${file.name}`;
            }
        }
    }
    function captureFacePhoto() {
        if (!faceVideo.videoWidth || !faceVideo.videoHeight) {
            showLicenseStatus('Camera is not ready yet. Please wait a moment and try again.');
            return;
        }

        const side = Math.min(faceVideo.videoWidth, faceVideo.videoHeight);
        const sx = (faceVideo.videoWidth - side) / 2;
        const sy = (faceVideo.videoHeight - side) / 2;
        faceCanvas.width = 720;
        faceCanvas.height = 720;
        const context = faceCanvas.getContext('2d');
        context.translate(faceCanvas.width, 0);
        context.scale(-1, 1);
        context.drawImage(faceVideo, sx, sy, side, side, 0, 0, faceCanvas.width, faceCanvas.height);

        const dataUrl = faceCanvas.toDataURL('image/jpeg', 0.9);
        licenseImageData.value = dataUrl;
        facePreview.src = dataUrl;
        faceFrame.classList.add('has-capture');
        captureFaceBtn.disabled = true;
        retakeFaceBtn.classList.remove('d-none');
        startCameraBtn.classList.add('d-none');
        faceCaptureHelp.textContent = 'Photo captured. Retake it if the eyes are not aligned with the middle guide.';
        stopFaceCamera();
    }

    startCameraBtn.addEventListener('click', startFaceCamera);
    captureFaceBtn.addEventListener('click', captureFacePhoto);
    retakeFaceBtn.addEventListener('click', () => {
        licenseImageData.value = '';
        facePreview.removeAttribute('src');
        startFaceCamera();
    });

    licenseForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncLicenseNumber();
        syncPhoneNumber();
        syncNrcNumber();
        if (!licenseForm.checkValidity()) {
            licenseForm.reportValidity();
            return;
        }
        if (licenseImageData.value === '') {
            showLicenseStatus('Please take a live face photo with the camera before saving.');
            return;
        }

        showLicenseStatus('');
        licenseSubmit.disabled = true;
        licenseSubmit.textContent = 'Saving...';

        try {
            const response = await fetch('save_license.php', {
                method: 'POST',
                body: new FormData(licenseForm),
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!result.ok) {
                showLicenseStatus(result.message || 'Could not save your license.');
                if (result.redirect) {
                    window.setTimeout(() => window.location.href = result.redirect, 900);
                }
                return;
            }

            showLicenseStatus(result.message || 'Driver license saved.', true, Boolean(result.license_conflict));
            if (!result.license_conflict) {
                window.setTimeout(() => window.location.href = result.redirect || 'dashboard.php', 700);
            }
        } catch (error) {
            showLicenseStatus('Could not contact the server. Please try again.');
        } finally {
            licenseSubmit.disabled = false;
            licenseSubmit.innerHTML = licenseSubmitLabel;
        }
    });

    window.addEventListener('beforeunload', stopFaceCamera);
</script>

<?php include 'share_footer.php'; ?>
