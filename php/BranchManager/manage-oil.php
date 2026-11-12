<?php
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../conn.php';
require_branch_manager();

$DEFAULT_FUEL_NAMES = ['Octane 92', 'Octane 95', 'Diesel', 'Premium Diesel'];

function normalizeFuelName(string $name): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($name));
    return strtolower((string) $normalized);
}

function isDefaultFuelName(string $name): bool
{
    global $DEFAULT_FUEL_NAMES;

    $normalized = normalizeFuelName($name);
    foreach ($DEFAULT_FUEL_NAMES as $defaultName) {
        if (normalizeFuelName($defaultName) === $normalized) {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ctx = current_context($pdo);
    $branch = $ctx['branch'] ?? null;
    $branchId = (int)($branch['branch_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        switch ($action) {
            case 'get_fuel_type':
                $tankId = (int)($_POST['tank_id'] ?? 0);
                if ($tankId <= 0) {
                    json_out(['ok' => false, 'error' => 'Invalid tank id.'], 400);
                }

                $stmt = $pdo->prepare('SELECT tank_id, fuel_price FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id LIMIT 1');
                $stmt->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);
                $tank = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$tank) {
                    json_out(['ok' => false, 'error' => 'Fuel record not found in this branch.'], 404);
                }

                json_out([
                    'ok' => true,
                    'fuel' => [
                        'tank_id' => (int)($tank['tank_id'] ?? 0),
                        'fuel_price' => (string)($tank['fuel_price'] ?? '0'),
                    ],
                ]);
                break;

            case 'save_limits':
                foreach ($_POST as $key => $value) {
                    if (strpos($key, 'alert_fuel_') !== 0 && strpos($key, 'alert_tank_') !== 0) continue;
                    $tankId = (int) str_replace(['alert_fuel_', 'alert_tank_'], '', $key);
                    $pct = max(5, min(80, (int)$value));
                    if ($tankId > 0) {
                        $stmt = $pdo->prepare('UPDATE tanks SET alert_pct = :pct WHERE tank_id = :tank_id AND branch_id = :branch_id');
                        $stmt->execute([':pct' => $pct, ':tank_id' => $tankId, ':branch_id' => $branchId]);
                    }
                }
                json_out(['ok' => true]);
                break;

            case 'add_fuel_type':
                $fuelName = trim((string)($_POST['fuel_name'] ?? ''));
                $capacityL = max(1, (int)($_POST['capacity_l'] ?? 0));
                $alertPct = max(5, min(80, (int)($_POST['alert_pct'] ?? 25)));
                if ($fuelName === '') {
                    json_out(['ok' => false, 'error' => 'Fuel name is required.'], 400);
                }

                if (isDefaultFuelName($fuelName)) {
                    json_out(['ok' => false, 'error' => 'This default fuel type is already available to the branch.', 409]);
                }

                $dupCheck = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM tanks t
                     INNER JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                     WHERE t.branch_id = :branch_id AND LOWER(ot.oil_name) = LOWER(:name)'
                );
                $dupCheck->execute([':branch_id' => $branchId, ':name' => $fuelName]);
                if ((int)$dupCheck->fetchColumn() > 0) {
                    json_out(['ok' => false, 'error' => 'A fuel type with this name already exists in this branch.'], 409);
                }

                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO oil_types (oil_name) VALUES (:name)');
                    $ins->execute([':name' => $fuelName]);
                    $oilTypeId = (int)$pdo->lastInsertId();

                    $stmt = $pdo->prepare('INSERT INTO tanks (branch_id, oil_type_id, fuel_price, capacity_l, current_l, alert_pct, is_enabled) VALUES (:branch_id, :oil_type_id, :price, :capacity_l, 0, :alert_pct, 1)');
                    $stmt->execute([
                        ':branch_id' => $branchId,
                        ':oil_type_id' => $oilTypeId,
                        ':price' => '0',
                        ':capacity_l' => $capacityL,
                        ':alert_pct' => $alertPct,
                    ]);
                    $pdo->commit();
                    json_out(['ok' => true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;

            case 'edit_fuel_type':
                $tankId = (int)($_POST['tank_id'] ?? 0);
                $fuelName = trim((string)($_POST['fuel_name'] ?? ''));
                $capacityL = max(1, (int)($_POST['capacity_l'] ?? 0));
                $fuelPriceRaw = trim((string)($_POST['fuel_price'] ?? ''));
                if ($tankId <= 0 || $fuelName === '') {
                    json_out(['ok' => false, 'error' => 'Invalid fuel record.'], 400);
                }
                if ($fuelPriceRaw === '' || !is_numeric($fuelPriceRaw) || (float)$fuelPriceRaw < 0) {
                    json_out(['ok' => false, 'error' => 'Please enter a valid price.'], 400);
                }
                $fuelPrice = (string)round((float)$fuelPriceRaw, 2);

                $tankStmt = $pdo->prepare('SELECT tank_id, oil_type_id FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id LIMIT 1');
                $tankStmt->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);
                $tank = $tankStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tank) {
                    json_out(['ok' => false, 'error' => 'Fuel record not found in this branch.'], 404);
                }

                $oldOilTypeId = (int)($tank['oil_type_id'] ?? 0);
                $curNameStmt = $pdo->prepare('SELECT oil_name FROM oil_types WHERE oil_type_id = :oid LIMIT 1');
                $curNameStmt->execute([':oid' => $oldOilTypeId]);
                $currentOilName = (string)$curNameStmt->fetchColumn();
                $nameChanged = (normalizeFuelName($fuelName) !== normalizeFuelName($currentOilName));

                if ($nameChanged && isDefaultFuelName($currentOilName)) {
                    json_out(['ok' => false, 'error' => 'Default fuel names cannot be renamed.', 409]);
                }

                if ($nameChanged && isDefaultFuelName($fuelName)) {
                    json_out(['ok' => false, 'error' => 'Default fuel names are reserved and cannot be assigned to custom fuel types.', 409]);
                }

                if (!$nameChanged) {
                    $updTank = $pdo->prepare('UPDATE tanks SET capacity_l = :capacity_l, fuel_price = :fuel_price WHERE tank_id = :tank_id AND branch_id = :branch_id');
                    $updTank->execute([
                        ':capacity_l' => $capacityL,
                        ':fuel_price' => $fuelPrice,
                        ':tank_id' => $tankId,
                        ':branch_id' => $branchId,
                    ]);
                    json_out(['ok' => true]);
                }

                $dupCheck = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM tanks t
                     INNER JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
                     WHERE t.branch_id = :branch_id
                       AND t.tank_id != :tank_id
                       AND LOWER(ot.oil_name) = LOWER(:name)'
                );
                $dupCheck->execute([':branch_id' => $branchId, ':tank_id' => $tankId, ':name' => $fuelName]);
                if ((int)$dupCheck->fetchColumn() > 0) {
                    json_out(['ok' => false, 'error' => 'A fuel type with this name already exists in this branch.'], 409);
                }

                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare('INSERT INTO oil_types (oil_name) VALUES (:name)');
                    $ins->execute([':name' => $fuelName]);
                    $newOilTypeId = (int)$pdo->lastInsertId();

                    $updTank = $pdo->prepare('UPDATE tanks SET oil_type_id = :new_oil_type_id, capacity_l = :capacity_l, fuel_price = :fuel_price WHERE tank_id = :tank_id AND branch_id = :branch_id');
                    $updTank->execute([
                        ':new_oil_type_id' => $newOilTypeId,
                        ':capacity_l' => $capacityL,
                        ':fuel_price' => $fuelPrice,
                        ':tank_id' => $tankId,
                        ':branch_id' => $branchId,
                    ]);

                    $refCheck = $pdo->prepare('SELECT COUNT(*) FROM tanks WHERE oil_type_id = :old_oil_type_id');
                    $refCheck->execute([':old_oil_type_id' => $oldOilTypeId]);
                    if ((int)$refCheck->fetchColumn() === 0) {
                        $deleteOld = $pdo->prepare('DELETE FROM oil_types WHERE oil_type_id = :old_oil_type_id');
                        $deleteOld->execute([':old_oil_type_id' => $oldOilTypeId]);
                    }

                    $pdo->commit();
                    json_out(['ok' => true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;

            case 'toggle_fuel':
                $tankId = (int)($_POST['tank_id'] ?? 0);
                $enabled = (int)($_POST['enabled'] ?? 1) ? 1 : 0;
                if ($tankId <= 0) {
                    json_out(['ok' => false, 'error' => 'Invalid tank id.'], 400);
                }

                $stmt = $pdo->prepare('UPDATE tanks SET is_enabled = :enabled WHERE tank_id = :tank_id AND branch_id = :branch_id');
                $stmt->execute([':enabled' => $enabled, ':tank_id' => $tankId, ':branch_id' => $branchId]);
                json_out(['ok' => true, 'enabled' => $enabled]);
                break;

            case 'delete_fuel_type':
                $tankId = (int)($_POST['tank_id'] ?? 0);
                if ($tankId <= 0) {
                    json_out(['ok' => false, 'error' => 'Invalid tank id.'], 400);
                }

                $tankStmt = $pdo->prepare('SELECT t.oil_type_id, ot.oil_name FROM tanks t LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id WHERE t.tank_id = :tank_id AND t.branch_id = :branch_id LIMIT 1');
                $tankStmt->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);
                $tank = $tankStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tank) {
                    json_out(['ok' => false, 'error' => 'Fuel record not found in this branch.'], 404);
                }

                $oilTypeId = (int)($tank['oil_type_id'] ?? 0);
                $deletedOilName = (string)($tank['oil_name'] ?? '');
                if (isDefaultFuelName($deletedOilName)) {
                    json_out(['ok' => false, 'error' => 'Default fuel types cannot be removed.', 409]);
                }

                $pdo->beginTransaction();
                try {
                    $deleteRefill = $pdo->prepare('DELETE FROM tank_refill WHERE tank_id = :tank_id AND branch_id = :branch_id');
                    $deleteRefill->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);

                    $stmt = $pdo->prepare('DELETE FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id');
                    $stmt->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);

                    $refCheck = $pdo->prepare('SELECT COUNT(*) FROM tanks WHERE oil_type_id = :oil_type_id');
                    $refCheck->execute([':oil_type_id' => $oilTypeId]);
                    if ((int)$refCheck->fetchColumn() === 0) {
                        $deleteOilType = $pdo->prepare('DELETE FROM oil_types WHERE oil_type_id = :oil_type_id');
                        $deleteOilType->execute([':oil_type_id' => $oilTypeId]);
                    }

                    $pdo->commit();
                    json_out(['ok' => true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;

            case 'add_refill':
                $tankId = (int)($_POST['tank_id'] ?? 0);
                $quantity = (float)($_POST['quantity_l'] ?? 0);
                if ($tankId <= 0 || $quantity <= 0) {
                    json_out(['ok' => false, 'error' => 'Quantity is required.'], 400);
                }

                $tankCheck = $pdo->prepare('SELECT tank_id, current_l, capacity_l FROM tanks WHERE tank_id = :tank_id AND branch_id = :branch_id LIMIT 1');
                $tankCheck->execute([':tank_id' => $tankId, ':branch_id' => $branchId]);
                $tank = $tankCheck->fetch(PDO::FETCH_ASSOC);
                if (!$tank) {
                    json_out(['ok' => false, 'error' => 'Fuel tank not found in this branch.'], 404);
                }

                // Capacity overflow check
                if (((float)$tank['current_l'] + $quantity) > (float)$tank['capacity_l']) {
                    json_out(['ok' => false, 'error' => 'The refill amount cannot exceed the available tank capacity.'], 400);
                }

                $pdo->beginTransaction();
                try {
                    $refill = $pdo->prepare('INSERT INTO tank_refill (branch_id, tank_id, quantity_l) VALUES (:branch_id, :tank_id, :quantity_l)');
                    $refill->execute([':branch_id' => $branchId, ':tank_id' => $tankId, ':quantity_l' => $quantity]);
                    $update = $pdo->prepare('UPDATE tanks SET current_l = current_l + :quantity_l WHERE tank_id = :tank_id AND branch_id = :branch_id');
                    $update->execute([':quantity_l' => $quantity, ':tank_id' => $tankId, ':branch_id' => $branchId]);
                    $pdo->commit();
                    json_out(['ok' => true]);
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                break;

            case 'delete_refill':
                $refillId = (int)($_POST['refill_id'] ?? $_POST['tank_id'] ?? 0);
                if ($refillId <= 0) {
                    json_out(['ok' => false, 'error' => 'Invalid refill record.'], 400);
                }
                $stmt = $pdo->prepare('DELETE FROM tank_refill WHERE tank_refill_id = :refill_id AND branch_id = :branch_id');
                $stmt->execute([':refill_id' => $refillId, ':branch_id' => $branchId]);
                json_out(['ok' => true]);
                break;

            default:
                json_out(['ok' => false, 'error' => 'Unsupported action.'], 400);
        }
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

$ctx = current_context($pdo);
$user = $ctx['user'];
$branch = $ctx['branch'];
$branchId = (int)($branch['branch_id'] ?? 0);
$alerts = notif_recent($pdo, (int)($user['user_id'] ?? 0), 5);
$unread_count = notif_unread_count($pdo, (int)($user['user_id'] ?? 0));
$has_unread = $unread_count > 0;
// --- ADD THIS AUTO-PROVISIONING BLOCK HERE ---
$defaultFuels = ['Octane 92', 'Octane 95', 'Diesel', 'Premium Diesel'];
foreach ($defaultFuels as $defaultName) {
    $otStmt = $pdo->prepare('SELECT oil_type_id FROM oil_types WHERE LOWER(oil_name) = LOWER(:name) LIMIT 1');
    $otStmt->execute([':name' => $defaultName]);
    $oilTypeId = $otStmt->fetchColumn();

    if (!$oilTypeId) {
        $insOt = $pdo->prepare('INSERT INTO oil_types (oil_name) VALUES (:name)');
        $insOt->execute([':name' => $defaultName]);
        $oilTypeId = $pdo->lastInsertId();
    }

    $chkTank = $pdo->prepare('SELECT COUNT(*) FROM tanks WHERE branch_id = :branch_id AND oil_type_id = :oil_type_id');
    $chkTank->execute([':branch_id' => $branchId, ':oil_type_id' => $oilTypeId]);
    
    if ((int)$chkTank->fetchColumn() === 0) {
        $insTank = $pdo->prepare('INSERT INTO tanks (branch_id, oil_type_id, fuel_price, capacity_l, current_l, alert_pct, is_enabled) VALUES (:branch_id, :oil_type_id, 0, 10000, 0, 25, 1)');
        $insTank->execute([
            ':branch_id' => $branchId,
            ':oil_type_id' => $oilTypeId
        ]);
    }
}
$stmt = $pdo->prepare(
    'SELECT t.tank_id, t.branch_id, t.oil_type_id, t.capacity_l, t.fuel_price, t.current_l, t.alert_pct, t.is_enabled,
            COALESCE(ot.oil_name, CONCAT("Fuel ", t.tank_id)) AS fuel_name
     FROM tanks t
     LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
     WHERE t.branch_id = :branch_id
     ORDER BY t.tank_id ASC'
);
$stmt->execute([':branch_id' => $branchId]);
$tanks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tankCounts = ['ok' => 0, 'mid' => 0, 'low' => 0];
foreach ($tanks as &$tank) {
    $tank['pct'] = $tank['capacity_l'] > 0 ? ((float)$tank['current_l'] / (float)$tank['capacity_l']) * 100 : 0;
    if ($tank['pct'] <= (float)$tank['alert_pct']) {
        $tank['status_class'] = 'low';
    } elseif ($tank['pct'] < 60) {
        $tank['status_class'] = 'mid';
    } else {
        $tank['status_class'] = 'ok';
    }
    if (isset($tankCounts[$tank['status_class']])) {
        $tankCounts[$tank['status_class']]++;
    }
}
unset($tank);

$branchCreatedDate = '';
$branchCreatedStmt = $pdo->prepare('SELECT DATE(created_at) AS created_date FROM branches WHERE branch_id = :branch_id LIMIT 1');
$branchCreatedStmt->execute([':branch_id' => $branchId]);
$rawBranchCreated = $branchCreatedStmt->fetchColumn();
if (is_string($rawBranchCreated) && trim($rawBranchCreated) !== '') {
    $branchCreatedDate = date('Y-m-d', strtotime($rawBranchCreated));
}

$refillStart = trim((string) ($_GET['refill_start_date'] ?? ''));
$refillEnd = trim((string) ($_GET['refill_end_date'] ?? ''));
$validRefillDate = static function (string $date): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
};
$todayDate = (new DateTimeImmutable('now'))->format('Y-m-d');
if (!$validRefillDate($refillStart)) {
    $refillStart = $branchCreatedDate !== '' ? $branchCreatedDate : '';
}
if (!$validRefillDate($refillEnd)) {
    $refillEnd = $todayDate;
}
if ($branchCreatedDate !== '' && $refillStart < $branchCreatedDate) {
    $refillStart = $branchCreatedDate;
}
if ($refillEnd > $todayDate) {
    $refillEnd = $todayDate;
}
if ($refillStart > $refillEnd) {
    $refillStart = $branchCreatedDate !== '' ? $branchCreatedDate : $refillEnd;
}

$refillSql =
    'SELECT tr.tank_refill_id, tr.quantity_l, tr.update_at, t.tank_id,
            COALESCE(ot.oil_name, CONCAT("Fuel ", t.tank_id)) AS fuel_name
     FROM tank_refill tr
     LEFT JOIN tanks t ON t.tank_id = tr.tank_id
     LEFT JOIN oil_types ot ON ot.oil_type_id = t.oil_type_id
     WHERE tr.branch_id = :branch_id';
$refillParams = [':branch_id' => $branchId];
if ($refillStart !== '') {
    $refillSql .= ' AND DATE(tr.update_at) >= :start_date';
    $refillParams[':start_date'] = $refillStart;
}
if ($refillEnd !== '') {
    $refillSql .= ' AND DATE(tr.update_at) <= :end_date';
    $refillParams[':end_date'] = $refillEnd;
}
$refillSql .= ' ORDER BY tr.update_at DESC, tr.tank_refill_id DESC';
$refillStmt = $pdo->prepare($refillSql);
$refillStmt->execute($refillParams);
$refills = $refillStmt->fetchAll(PDO::FETCH_ASSOC);

$initials = initials((string)($user['full_name'] ?? ($_SESSION['user_name'] ?? '')));
$managerDisplayName = map_pump_user_display_name($user['full_name'] ?? ($_SESSION['user_name'] ?? ''), '');
$managerAvatarUrl   = map_pump_user_avatar_url($user['avatar_url'] ?? '', '');
$branchLabel = $branch['branch_name'] ?? 'Branch';
$branchCode = $branch['branch_code'] ?? ('Branch ' . $branchId);
?>
<html lang="en"><head><style>body {transition: opacity ease-in 0.2s; } 
body[unresolved] {opacity: 0; display: block; overflow: hidden; position: relative; } 
</style>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MAP &amp; PUMP — Manage Fuel Stock</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" type="image/png" href="/images/Logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&amp;family=Noto+Sans+Myanmar:wght@400;500;600;700&amp;display=swap" rel="stylesheet">

<style>
  :root{
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399;
    --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  body{background:var(--navy-950); color:var(--slate-50); font-family:'Inter','Space Grotesk',sans-serif; overflow-x:hidden;}
  .disp{font-family:'Space Grotesk','Noto Sans Myanmar',sans-serif;}
  a{color:inherit; text-decoration:none;}
  button{font-family:inherit;}
  @media (prefers-reduced-motion: reduce){ *{animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition: none !important;} }

  .app-shell{display:flex; min-height:100vh;}
  
  /* ---------- sidebar ---------- */
  .sidebar{
    width:252px; flex-shrink:0; background:var(--navy-900); border-right:1px solid rgba(255,255,255,0.06); 
    display:flex; flex-direction:column; padding:22px 16px; position:sticky; top:0; height:100vh; z-index:60;
  }
  .nav-logo {
    width: 32px;        
    height: auto;
    object-fit: contain;
  }
  .side-brand{display:flex; align-items:center; gap:10px; font-weight:700; font-size:17px; padding:6px 8px 0;}
  .side-role{font-size:11px; color:var(--slate-400); font-weight:500; margin:2px 0 0 46px;}
  .side-branch{
    margin:20px 8px 0; padding:10px 12px; background:var(--navy-800); border-radius:12px; 
    border:1px solid rgba(255,255,255,0.06); font-size:12.5px;
    transition: background 0.3s, border-color 0.3s;
    display:flex; align-items:center; gap:10px;
  }
  .side-branch:hover{
    background: var(--navy-700);
    border-color: rgba(251,191,36,0.25);
  }
  .side-branch .hq-logo{
    width:36px; height:36px; border-radius:8px; object-fit:cover; flex-shrink:0;
    background:var(--navy-950); border:1px solid rgba(255,255,255,0.08);
  }
  .side-branch .txt{min-width:0; flex:1;}
  .side-branch b{display:block; font-size:14px; font-family:'Space Grotesk'; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .side-branch span{color:var(--slate-400);}
  .side-nav{margin-top:22px; display:flex; flex-direction:column; gap:4px; flex:1;}
  .side-link{
    display:flex; align-items:center; gap:12px; padding:11px 12px; border-radius:10px; font-size:14px; 
    color:var(--slate-300); font-weight:500;
    transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease;
  }
  .side-link:hover{
    background:rgba(255,255,255,0.045); color:var(--slate-50);
    transform: translateX(4px);
  }
  .side-link.active{
    background:linear-gradient(135deg, rgba(251,191,36,0.16), rgba(29,78,216,0.08)); 
    color:var(--slate-50); box-shadow:inset 0 0 0 1px rgba(251,191,36,0.32);
  }
  .side-link.active:hover{transform: none;}
  .side-link .ic{font-size:16px; width:18px; text-align:center; transition: transform 0.2s ease;}
  .side-link:hover .ic{transform: scale(1.15);}
  .side-logout{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:10px; font-size:14px; 
    color:var(--red-400); font-weight:600; margin-top:14px; border-top:1px solid rgba(255,255,255,0.06); padding-top:18px;
    transition: background 0.25s, color 0.25s, padding-left 0.2s;
  }
  .side-logout:hover{background:rgba(248,113,113,0.08); padding-left: 16px;}

  /* ---------- main ---------- */
  .main{flex:1; min-width:0;}
  .topbar{
    position:sticky; top:0; z-index:50; display:flex; align-items:center; justify-content:space-between; 
    padding:16px 32px; background:rgba(7,11,20,0.72); backdrop-filter:blur(14px); border-bottom:1px solid rgba(255,255,255,0.06);
  }
  .topbar h1{font-size:18px; font-weight:700;}
  .topbar .date{font-size:12.5px; color:var(--slate-400); margin-top:2px; font-variant-numeric: tabular-nums;}
  .topbar-right{display:flex; align-items:center; gap:16px; position:relative;}
  
  .bell{
    width:36px; height:36px; border-radius:50%; background:var(--navy-800); display:flex; align-items:center; 
    justify-content:center; border:1px solid rgba(255,255,255,0.08); position:relative; font-size:15px; cursor:pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .bell:hover{
    background: var(--navy-700);
    transform: scale(1.05);
  }
  .bell .ping{position:absolute; top:4px; right:4px; width:10px; height:10px; border-radius:50%; background:#ef4444; box-shadow:0 0 0 2px var(--navy-800);}
  
  label.staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  label.staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  label.staff-theme-control span { font-size: 1.05rem; }
  label.staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-400); }

  .avatar-chip{display:flex; align-items:center; gap:10px; font-size:13px; cursor: pointer; padding: 4px 8px; border-radius: 99px; transition: background 0.2s;}
  .avatar-chip:hover{background: rgba(255,255,255,0.05);}
  .avatar-circle{width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--sky-400),var(--blue-700)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; font-family:'Space Grotesk';}

  /* ---------- Notification Popup Dropdown (dashboard style) ---------- */
  .notif-popup {
    position: absolute;
    top: 48px;
    right: 60px;
    width: 320px;
    max-height: 420px;
    overflow: hidden;
    background: var(--navy-800);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    box-shadow: 0 16px 32px rgba(0, 0, 0, 0.4);
    padding: 12px;
    z-index: 100;
    display: none;
    animation: fadeInDown 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .notif-popup.show,
  .notif-popup.active { display: block; opacity: 1; pointer-events: auto; transform: translateY(0); }
  .notif-header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom:10px; margin-bottom:10px; }
  .notif-header h4 { font-size: 14px; font-weight:700; margin:0; }
  .notif-list {
    max-height: 330px;
    overflow-y: auto;
    overflow-x: auto;
    padding-right: 4px;
    scrollbar-width: thin;
    scrollbar-color: rgba(56,189,248,0.35) transparent;
  }
  .notif-list::-webkit-scrollbar { width: 8px; height: 8px; }
  .notif-list::-webkit-scrollbar-track { background: transparent; }
  .notif-list::-webkit-scrollbar-thumb { background: rgba(56,189,248,0.35); border-radius: 999px; }
  .notif-list::-webkit-scrollbar-thumb:hover { background: rgba(56,189,248,0.55); }
  .notif-item {
    padding: 10px 8px; border-radius: 10px; display: flex; gap: 10px; align-items: flex-start; transition: background-color 0.2s; border: 1px solid transparent; cursor: pointer;
  }
  .notif-item:hover { background-color: rgba(255,255,255,0.04); }
  .notif-item.unread { border-color: rgba(56,189,248,0.2); }
  .notif-item-warning .icon,
  .notif-item.error .icon,
  .notif-item.warn .icon,
  .notif-item .icon {
    display: none;
  }
  .notif-item-body {
    min-width: 0;
    flex: 1;
  }
  .notif-item-body b { display:block; font-size:12.5px; color:var(--slate-50); margin-bottom:2px; }
  .notif-item-body span { color:var(--slate-400); font-size:11.5px; display:block; white-space:normal; line-height:1.5; }
  .notif-item-body .notif-time { font-size:10px; color:var(--slate-400); margin-top:4px; }
  .notif-empty { text-align:center; padding:12px 0; color:var(--slate-400); font-size:12px; }
  @keyframes fadeInDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

  /* Content Entrance Fade */
  .content{
    padding:30px 32px 60px;
    opacity: 0;
    transform: translateY(10px);
    animation: layoutFadeIn 0.5s ease forwards;
  }
  .page-foot{
    text-align:center; margin-top:34px; padding-top:18px;
    border-top:1px solid rgba(255,255,255,0.06);
    font-size:12px; color:var(--slate-400);
  }
  @keyframes layoutFadeIn {
    to { opacity: 1; transform: translateY(0); }
  }

  .page-head{display:flex; align-items:center; justify-content:space-between; margin-bottom:26px; flex-wrap:wrap; gap:14px;}
  .page-head h2{font-size:20px; font-weight:700;}
  .page-head p{font-size:13px; color:var(--slate-400); margin-top:4px;}
  
  .btn-primary{
    background:linear-gradient(135deg, var(--amber-400), #d97706); color:#2b1704; font-weight:700; font-size:13.5px; 
    padding:11px 18px; border-radius:11px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:8px; 
    box-shadow:0 10px 24px -10px rgba(251,191,36,0.5);
    transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s;
  }
  .btn-primary:hover{
    transform: translateY(-2px);
    box-shadow:0 12px 28px -8px rgba(251,191,36,0.65);
  }
  .page-head-actions{display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap;}
  .page-head-actions .btn-primary{min-width:180px; justify-content:center; height:46px; padding:0 18px; border-radius:12px;}
  .action-btn-blue{background:linear-gradient(135deg, #3b8dff, #1d4ed8)!important; color:#fff!important; box-shadow:0 12px 26px -12px rgba(59,141,255,0.75)!important;}
  .action-btn-amber{background:linear-gradient(135deg, #164E63, #0f3d4d)!important; color:#f8fbff!important; box-shadow:0 12px 26px -12px rgba(22,78,99,0.72)!important;}
  .action-btn-refill{background:linear-gradient(135deg, rgba(59,141,255,0.18), rgba(29,78,216,0.18))!important; border:1px solid rgba(59,141,255,0.32)!important; color:#dfefff!important; box-shadow:none!important;}

  .stat-strip{display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:26px;}
  /* ---------- Fuel Stocks Overview ---------- */
  .stock-overview{display:flex; align-items:flex-end; justify-content:space-between; gap:16px; margin-bottom:18px; flex-wrap:wrap;}
  .stock-overview h2{font-size:20px; font-weight:700; margin:0;}
  .stock-overview h2 span{display:inline-block; vertical-align:baseline;}
  .stock-subtitle{font-size:14px; color:var(--slate-400); margin-top:2px;}
  .stock-pills{display:flex; align-items:center; gap:8px; flex-wrap:wrap;}
  .stock-pill{display:inline-flex; align-items:center; justify-content:center; min-width:88px; border-radius:8px; padding:5px 10px; font-size:12px; font-weight:700; border:1px solid transparent;}
  .stock-pill.sufficient{background:rgba(15,118,110,0.22); color:#5eead4; border-color:rgba(94,234,212,0.25);}
  .stock-pill.watch{background:rgba(180,83,9,0.2); color:#fbbf24; border-color:rgba(251,191,36,0.25);}
  .stock-pill.low{background:rgba(127,29,29,0.22); color:#fca5a5; border-color:rgba(252,165,165,0.24);}

  /* ---------- Fuel Tanks Grid ---------- */
  .tank-grid{display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; margin:0 0 26px; align-items:stretch;}
  @media (max-width:980px){.tank-grid{grid-template-columns:1fr;}}
  
  .tank{
    background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); 
    border-radius:14px; padding:14px 16px 12px; transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    min-height: 128px; display:flex; flex-direction:column; justify-content:space-between;
  }
  .tank.disabled-tank {
    opacity: 0.35;
    filter: grayscale(0.5);
    transform: scale(0.98);
    pointer-events: none;
  }
  .tank:hover:not(.disabled-tank){
    transform: translateY(-2px);
    border-color: rgba(251,191,36,0.25);
    box-shadow: 0 10px 24px -15px rgba(0,0,0,0.5);
  }
  .tank .top{display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; gap:10px;}
  .tank .fuel-name{font-weight:700; font-size:15px; line-height:1.3;}
  .tank .cap{font-size:11px; color:var(--slate-400); margin-top:2px;}
  .tank .pct{font-family:'Space Grotesk'; font-size:22px; font-weight:700; transition: color 0.3s; line-height:1;}
  
  .gauge-track{height:8px; border-radius:999px; background:rgba(255,255,255,0.06); overflow:hidden; margin-bottom:8px;}
  .gauge-track i{display:block; height:100%; border-radius:999px; transition: width 0.8s cubic-bezier(0.34, 1.56, 0.64, 1), background-color 0.4s;}
  
  .tank .liters{font-size:12px; color:var(--slate-300); display:flex; justify-content:space-between; gap:8px; margin-top:2px;}
  
  .tank-flag{display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:4px 8px; border-radius:999px; margin-top:8px; transition: all 0.3s;}
  .tank-flag.ok{background:rgba(52,211,153,0.14); color:var(--mint-400);}
  .tank-flag.low{background:rgba(248,113,113,0.14); color:var(--red-400); animation: pulseAlert 2s infinite;}
  .tank-flag.mid{background:rgba(251,191,36,0.14); color:var(--amber-300);}
  .tank-flag.inactive{background:rgba(139,150,173,0.14); color:var(--slate-400);}

  .fuel-table-scroll, .refill-table-scroll{
    overflow-x: hidden;
    overflow-y: hidden;
    max-height: none;
    scrollbar-width: thin;
    scrollbar-color: rgba(148, 163, 184, 0.45) transparent;
  }
  .fuel-table-scroll.has-scroll, .refill-table-scroll.has-scroll {
    overflow-y: auto;
  }
  .fuel-table-scroll::-webkit-scrollbar, .refill-table-scroll::-webkit-scrollbar { width: 8px; }
  .fuel-table-scroll::-webkit-scrollbar-track, .refill-table-scroll::-webkit-scrollbar-track { background: transparent; }
  .fuel-table-scroll::-webkit-scrollbar-thumb, .refill-table-scroll::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.38);
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: content-box;
  }
  .fuel-table-scroll::-webkit-scrollbar-thumb:hover, .refill-table-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.62); }
  .fuel-table-scroll table{width:100%; border-collapse:collapse;}
  .refill-table-scroll table{width:100%; border-collapse:collapse;}
  .fuel-table-scroll thead th{position:sticky; top:0; background:rgba(15,23,42,0.94); z-index:2;}
  .refill-table-scroll thead th{position:sticky; top:0; background:rgba(15,23,42,0.94); z-index:2;}
  
  /* ---------- Refill History panel (matches Recent Staff Activity) ---------- */
  #tab-refill-history > .panel{background:linear-gradient(160deg, var(--navy-800), var(--navy-900)); border:1px solid rgba(255,255,255,0.07); border-radius:18px; overflow:hidden; padding:24px;}
  #tab-refill-history .refill-history-toolbar{display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:20px;}
  #tab-refill-history .refill-history-heading{flex:1 1 280px; min-width:0;}
  #tab-refill-history .refill-history-heading h3{margin:0 0 10px; font-size:16px; font-weight:700; line-height:1.4; color:var(--slate-50);}
  #tab-refill-history .refill-history-heading p{margin:0; font-size:13px; line-height:1.6; color:var(--slate-300);}
  .logs-modal-filters{display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0; margin-left:auto;}
  .logs-modal-filters label{display:flex; align-items:center; gap:6px; font-size:12px; color:var(--slate-300); margin:0;}
  .logs-modal-filters input[type="date"]{background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:7px 10px; color:var(--slate-50); font-size:12.5px; color-scheme:dark;}
  .logs-modal-btn{background:#0d5fdb; color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:13px; font-weight:700; cursor:pointer; transition:background 0.15s;}
  .logs-modal-btn:hover{background:#1d6ff0;}
  #tab-refill-history .refill-table-scroll{border:1px solid rgba(255,255,255,0.1); border-radius:10px; padding:0 12px;}
  body.branch-manager-theme.light-mode #tab-refill-history .refill-table-scroll { border-color:var(--lm-line-strong); }
  body.branch-manager-theme.light-mode .logs-modal-filters input[type="date"] { background:#ffffff; border-color:var(--lm-line-strong); color:var(--slate-50); color-scheme:light; }
  
  .fuel-action-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .fuel-action-table thead th {
    text-align: left;
    font-size: 11.5px;
    color: var(--slate-400);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    white-space: nowrap;
  }
  .fuel-action-table tbody tr {
    transition: opacity 0.2s ease, background-color 0.2s ease;
  }
  .fuel-action-table tbody tr:hover {
    background: rgba(255,255,255,0.02);
  }
  .fuel-action-table tbody td {
    padding: 14px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
  }
  .fuel-action-table tbody tr:last-child td {
    border-bottom: none;
  }
  .fuel-name-cell {
    color: var(--slate-50);
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .fuel-stock-meta {
    color: var(--slate-300);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .fuel-action-cell {
    text-align: right;
  }
  .fuel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    white-space: nowrap;
  }
  .fuel-row-btn {
    height: 34px;
    padding: 0 14px;
    border: 1px solid rgba(148,163,184,0.45);
    background: transparent;
    color: var(--slate-50);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    font-family: inherit;
    line-height: 1;
    white-space: nowrap;
    transition: background 0.2s ease, box-shadow 0.2s ease;
  }
  .fuel-row-btn.edit-btn { color: var(--slate-50); border-color: rgba(148,163,184,0.45); }
  .fuel-row-btn.toggle-btn.on { color: #fb923c; border-color: rgba(245,158,11,0.75); }
  .fuel-row-btn.toggle-btn.off { color: var(--mint-400); border-color: rgba(52,211,153,0.6); }
  .fuel-row-btn.delete-btn { color: var(--red-400); border-color: rgba(220,38,38,0.8); }
  .fuel-row-btn:hover,
  .fuel-row-btn:focus-visible {
    background: rgba(255,255,255,0.06);
  }
  .fuel-row-btn.edit-btn:hover { background: rgba(148,163,184,0.14); }
  .fuel-row-btn.toggle-btn.on:hover { background: rgba(245,158,11,0.12); }
  .fuel-row-btn.toggle-btn.off:hover { background: rgba(52,211,153,0.12); }
  .fuel-row-btn.delete-btn:hover { background: rgba(220,38,38,0.12); }
  .fuel-row-btn:focus-visible { outline: 2px solid rgba(148,163,184,0.6); outline-offset: 2px; }
  .fuel-action-table thead th:last-child,
  .fuel-action-table tbody td:last-child {
    width: 270px;
    min-width: 270px;
    max-width: 270px;
    text-align: right;
    white-space: nowrap;
    padding-left: 10px;
    padding-right: 10px;
  }
  .fuel-types-table thead th:last-child,
  .fuel-types-table tbody td:last-child {
    width: auto;
    min-width: 250px;
    max-width: none;
  }
  
  /* ---------- Tabs ---------- */
  .tabs{display:flex; gap:8px; margin-bottom:22px; border-bottom:1px solid rgba(255,255,255,0.08); flex-wrap:wrap;}
  .tab-btn{
    background:none; border:none; color:var(--slate-400); font-family:inherit; font-size:13.5px; font-weight:600; 
    padding:11px 4px; cursor:pointer; position:relative; margin-right:22px;
    transition: color 0.25s ease;
  }
  .tab-btn:hover{color: var(--slate-50);}
  .tab-btn.active{color:var(--slate-50);}
  .tab-btn.active::after{
    content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:var(--amber-400); border-radius:2px;
    animation: slideLine 0.25s ease-out;
  }
  @keyframes slideLine {
    from { transform: scaleX(0); }
    to { transform: scaleX(1); }
  }

  .tab-panel{display:none;}
  .tab-panel.active{
    display:block;
    animation: panelFadeIn 0.3s ease forwards;
  }
  @keyframes panelFadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ---------- Fuel Type Active Toggle Rows ---------- */
  .type-row{display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.05); transition: background-color 0.2s;}
  .type-row:hover{background-color: rgba(255,255,255,0.015);}
  .type-row:last-child{border-bottom:none;}
  .type-row .info{display:flex; align-items:center; gap:14px;}
 
  .type-row .swatch{width:12px; height:36px; border-radius:4px;}
  .type-row b{font-size:14px; display:block;}
  .type-row span{font-size:11.5px; color:var(--slate-400);}
  
  .toggle{position:relative; width:44px; height:24px; border-radius:999px; background:rgba(255,255,255,0.1); cursor:pointer; border:none; flex-shrink:0;}
  .toggle::after{content:""; position:absolute; top:3px; left:3px; width:18px; height:18px; border-radius:50%; background:var(--slate-400); transition:all .2s;}
   .toggle.on{background:rgba(52,211,153,0.28);}
  .toggle.on::after{ left:23px; background:var(--mint-400);}

  /* ---------- Modals ---------- */
  .modal-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(7, 11, 20, 0.85);
    backdrop-filter: blur(8px); z-index: 100; display: flex; align-items: center; justify-content: center;
    opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
  }
  .modal-overlay.active { opacity: 1; pointer-events: auto; }
  
  .modal-card-custom {
    background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
    border: 1px solid rgba(255,255,255,0.1); border-radius: 20px;
    width: 100%; max-width: 480px; padding: 28px; transform: scale(0.95);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .modal-overlay.active .modal-card-custom { transform: scale(1); }
  
  .modal-header-custom { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
  .modal-header-custom h3 { font-size: 18px; font-weight: 700; margin: 0; }
  .modal-close { background: none; border: none; color: var(--slate-400); font-size: 20px; cursor: pointer; transition: color 0.2s; }
  .modal-close:hover { color: var(--slate-50); }

  .limit-modal-head { display:flex; align-items:center; gap:12px; margin-bottom:0; }
  .limit-modal-head h3 {
    font-size: 18px; font-weight: 700; margin: 0;
  }
  .limit-modal-head .limit-modal-icon {
    display: none;
  }
  .limit-modal-sub {
    font-size: 12.5px;
    color: var(--slate-400);
    margin: 0 0 18px;
    line-height: 1.6;
  }
  .limit-form-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
    margin-top: 6px;
  }
  .limit-form-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 10px;
    padding: 14px 14px 12px;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    background: rgba(255,255,255,0.015);
    min-height: 80px;
  }
  .limit-name-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    width: 100%;
  }
  .limit-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #f7d66a;
    box-shadow: 0 0 0 4px rgba(247, 214, 106, 0.18);
    flex-shrink: 0;
  }
  .limit-form-item label {
    display:block; margin:0; font-size:13px; font-weight:700; color:var(--slate-50); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .limit-input-wrap { position:relative; width:100%; }
  .limit-input-wrap input {
    width:100%; padding:10px 24px 10px 10px; border-radius:10px; border:1px solid rgba(255,255,255,0.1);
    background:rgba(7,11,20,0.8); color:var(--slate-50); font-weight:600; font-size:13px; outline:none;
  }
  .limit-input-wrap input:focus { border-color: rgba(251,191,36,0.45); box-shadow:0 0 0 1px rgba(251,191,36,0.25); }
  .limit-input-wrap .pct-suffix {
    position:absolute; right:9px; top:50%; transform:translateY(-50%); color:var(--slate-400); font-size:11px; font-weight:700;
  }

  @media (max-width: 560px) {
    .limit-form-list {
      grid-template-columns: 1fr;
    }
  }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .form-control-custom {
    width: 100%; background: var(--navy-950); border: 1px solid rgba(255,255,255,0.08);
    border-radius: 10px; padding: 10px 14px; color: var(--slate-50); font-size: 13.5px; outline: none;
    transition: border-color 0.2s;
  }
  .form-control-custom:focus { border-color: var(--amber-400); }
  
  .modal-actions {

    display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;
  }
  .btn-secondary-custom {
    background: transparent; border: 1px solid rgba(255,255,255,0.15); color: var(--slate-300);
    padding: 10px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
    transition: background 0.2s, color 0.2s;
  }
  .btn-secondary-custom:hover { background: rgba(255,255,255,0.05); color: var(--slate-50); }

  .refill-modal-sub { font-size:12.5px; color:var(--slate-400); margin:-6px 0 18px; line-height:1.6; }
  .refill-field { margin-bottom: 16px; }
  .refill-field label { display: block; font-size: 12px; color: var(--slate-400); margin-bottom: 6px; font-weight: 500; }
  .refill-actions {
    display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;
  }

  .refill-modal-card { max-width: 480px; padding: 28px; }

  /* ---------- Delete Confirmation Modal ---------- */
  .delete-modal-card {
    background: var(--navy-900); border: 1px solid rgba(255,255,255,0.1); border-radius: 18px;
    padding: 24px; display: flex; flex-direction: column; align-items: center;
  }
  .delete-modal-icon {
    width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    background: rgba(248,113,113,0.15); color: var(--red-400); margin-bottom: 16px;
    font-size: 24px;
  }
  .delete-modal-title {
    font-size: 18px; font-weight: 700; margin: 0 0 12px;
    text-align: center;
  }
  .delete-modal-message {
    font-size: 14px; color: var(--slate-400); margin-bottom: 24px;
    text-align: center;
  }
  .delete-modal-card .modal-actions {
    width: 100%; display: flex; justify-content: space-between; gap: 12px;
  }
  .delete-modal-card .btn-secondary-custom {
    flex: 1; padding: 10px 0; border-radius: 10px;
  }
  .delete-modal-card #delete-confirm-btn {
    flex: 1; padding: 10px 0; border-radius: 10px;
    background: linear-gradient(135deg, #f87171, #b91c1c); color: #fff; font-weight: 700;
    transition: background 0.2s;
  }
  .delete-modal-card #delete-confirm-btn:hover {
    background: linear-gradient(135deg, #dc2626, #991b1b);
  }

  /* ---------- Success Toast (mini-popup style) ---------- */
  .oil-toast {
    position: fixed;
    top: 22px;
    left: 50%;
    transform: translateX(-50%) translateY(-8px);
    background: rgba(7, 11, 20, 0.97);
    border: 1px solid rgba(52, 211, 153, 0.35);
    color: #6ee7b7;
    padding: 10px 14px;
    border-radius: 10px;
    z-index: 9999;
    min-width: 260px;
    max-width: 92%;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.6);
    opacity: 0;
    pointer-events: none;
    visibility: hidden;
    transition: opacity 0.25s ease, transform 0.25s ease, visibility 0.25s;
  }
  .oil-toast.show {
    opacity: 1;
    pointer-events: auto;
    visibility: visible;
    transform: translateX(-50%) translateY(0);
  }
  .oil-toast .toast-msg { flex: 1; font-size: 13px; line-height: 1.2; }
  .oil-toast .toast-close { background: none; border: none; color: inherit; font-weight: 700; cursor: pointer; padding: 4px 6px; font-size: 14px; }
  .oil-toast.error { border-color: rgba(248, 113, 113, 0.4); color: #fca5a5; }
  /* ---------- Pagination Controls ---------- */
  .pg-controls {
    display: flex; align-items: center; justify-content: center;
    gap: 6px; margin-top: 18px; flex-wrap: wrap;
  }
  .pg-btn {
    background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.10);
    color: var(--slate-300); border-radius: 8px; padding: 6px 13px;
    font-size: 12.5px; font-weight: 600; cursor: pointer;
    transition: background 0.18s, color 0.18s, border-color 0.18s;
    min-width: 34px; text-align: center;
  }
  .pg-btn:hover:not(:disabled) { background: rgba(255,255,255,0.10); color: var(--slate-50); }
  .pg-btn.active { background: var(--amber-400); border-color: var(--amber-400); color: #1d1306; }
  .pg-btn:disabled { opacity: 0.35; cursor: default; }
  /* All Fuel Types pagination: blue like the Add Fuel Type button */
  #tank-grid-pg-controls .pg-btn.active,
  #tank-grid-pg-controls .pg-btn.active:hover:not(:disabled) { background:linear-gradient(135deg, #3b8dff, #1d4ed8); border-color:#2f6feb; color:#fff; box-shadow:0 8px 18px -10px rgba(59,141,255,0.75); }

  body.branch-manager-theme {
    --navy-950:#070b14; --navy-900:#0b1220; --navy-800:#101a30; --navy-700:#16213f; --navy-600:#1a2438;
    --blue-700:#1d4ed8; --blue-500:#2f6feb; --sky-400:#38bdf8;
    --amber-400:#fbbf24; --amber-300:#fcd34d; --mint-400:#34d399; --red-400:#f87171;
    --slate-50:#f8fafc; --slate-300:#c9d2e3; --slate-400:#8b96ad;
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode {
    --navy-950:#f3f6fb; --navy-900:#ffffff; --navy-800:#f7f9fc; --navy-700:#edf3fb; --navy-600:#e5edf8;
    --blue-700:#286be0; --blue-500:#3c82ff; --sky-400:#3f8cf5; --amber-400:#d98400; --amber-300:#e2a72d; --mint-400:#128e6b; --red-400:#cf3d3d;
    --slate-50:#172337; --slate-300:#3c4d68; --slate-400:#58677e;
    background: var(--navy-950); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .sidebar,
  body.branch-manager-theme.light-mode .topbar,
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .stat-card,
  body.branch-manager-theme.light-mode .module-card,
  body.branch-manager-theme.light-mode .notif-popup,
  body.branch-manager-theme.light-mode .bell,
  body.branch-manager-theme.light-mode .side-branch,
  body.branch-manager-theme.light-mode .avatar-chip,
  body.branch-manager-theme.light-mode .color-modal,
  body.branch-manager-theme.light-mode .sales-panel,
  body.branch-manager-theme.light-mode .fuel-color-table tbody tr,
  body.branch-manager-theme.light-mode .form-control-custom,
  body.branch-manager-theme.light-mode .modal-card-custom,
  body.branch-manager-theme.light-mode .account-profile-card,
  body.branch-manager-theme.light-mode .account-sidecard {
    background: var(--navy-900); border-color: rgba(17,24,39,0.12); color: var(--slate-50);
  }
  body.branch-manager-theme.light-mode .side-link,
  body.branch-manager-theme.light-mode .side-branch span,
  body.branch-manager-theme.light-mode .topbar .date,
  body.branch-manager-theme.light-mode .notif-item-body span,
  body.branch-manager-theme.light-mode .alert-row span,
  body.branch-manager-theme.light-mode .queue-row .pump,
  body.branch-manager-theme.light-mode .module-card p,
  body.branch-manager-theme.light-mode .stat-card .lbl,
  body.branch-manager-theme.light-mode .panel h3,
  body.branch-manager-theme.light-mode .report-row p,
  body.branch-manager-theme.light-mode .field label,
  body.branch-manager-theme.light-mode .account-password-hint {
    color: var(--slate-300);
  }
  body.branch-manager-theme.light-mode .side-link.active,
  body.branch-manager-theme.light-mode .side-link:hover,
  body.branch-manager-theme.light-mode .avatar-chip:hover,
  body.branch-manager-theme.light-mode .bell:hover {
    background: rgba(63, 140, 245, 0.10);
    color: var(--slate-50);
  }
  .staff-theme-control {
    position: relative; display: grid; width: 28px; height: 32px; place-items: center; margin: 0; color: var(--slate-300); cursor: pointer;
  }
  .staff-theme-control input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .staff-theme-control span { font-size: 1.05rem; }
  .staff-theme-control:focus-within span { outline: 2px solid var(--sky-400); outline-offset: 4px; border-radius: 3px; }
  body.branch-manager-theme.light-mode .staff-theme-control { color: var(--slate-400); }

  /* ---------- Light mode refinements (light mode only; dark mode untouched) ---------- */
  body.branch-manager-theme.light-mode {
    color-scheme: light;
    --amber-300:#c28a0a;
    --lm-line:rgba(15,23,42,0.10); --lm-line-soft:rgba(15,23,42,0.06); --lm-line-strong:rgba(15,23,42,0.18);
    --lm-hover:rgba(15,23,42,0.045);
    --lm-shadow:0 1px 2px rgba(15,23,42,0.04), 0 10px 26px -16px rgba(15,23,42,0.16);
    --lm-shadow-pop:0 18px 40px -14px rgba(15,23,42,0.25);
  }
  /* Shell */
  body.branch-manager-theme.light-mode .sidebar { background:#fff; border-right-color:var(--lm-line); box-shadow:1px 0 0 rgba(15,23,42,0.02); }
  body.branch-manager-theme.light-mode .topbar { background:rgba(255,255,255,0.86); backdrop-filter:blur(14px); border-bottom-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .sidebar > a .disp { color:var(--slate-50) !important; }
  body.branch-manager-theme.light-mode .side-role { color:var(--slate-400); }
  body.branch-manager-theme.light-mode .side-branch { background:var(--navy-800); border-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .side-branch .hq-logo { background:#fff; border-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .side-link:hover { background:var(--lm-hover); color:var(--slate-50); }
  body.branch-manager-theme.light-mode .side-logout { border-top-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .side-logout:hover { background:rgba(207,61,61,0.08); }
  body.branch-manager-theme.light-mode .bell { color:var(--slate-300); border-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .bell:hover { background:var(--navy-700); }
  body.branch-manager-theme.light-mode .bell .ping { box-shadow:0 0 0 2px #fff; }
  body.branch-manager-theme.light-mode .avatar-chip { background:transparent; border-color:transparent; }
  body.branch-manager-theme.light-mode .avatar-chip:hover { background:var(--lm-hover); }
  body.branch-manager-theme.light-mode .avatar-circle,
  body.branch-manager-theme.light-mode .avatar-circle a { color:#fff; }
  body.branch-manager-theme.light-mode .page-foot { border-top-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .staff-theme-control:hover { color:var(--slate-50); }

  /* Notifications popup */
  body.branch-manager-theme.light-mode .notif-popup {
    --slate-50:#0f172a; --slate-300:#334155; --slate-400:#5b6b82;
    border-color:rgba(15,23,42,0.09); box-shadow:0 18px 40px -14px rgba(15,23,42,0.28);
  }
  body.branch-manager-theme.light-mode .notif-header { border-bottom-color:rgba(15,23,42,0.09); }
  body.branch-manager-theme.light-mode .notif-item { border-bottom-color:transparent; }
  body.branch-manager-theme.light-mode .notif-item.unread { border-color:rgba(2,132,199,0.28); }
  body.branch-manager-theme.light-mode .notif-item:hover { background:var(--lm-hover); }
  body.branch-manager-theme.light-mode .notif-item .ic,
  body.branch-manager-theme.light-mode .notif-item .icon { background:rgba(88,103,126,0.12); color:var(--slate-300); }
  body.branch-manager-theme.light-mode .notif-item[data-kind="warn"] .ic,
  body.branch-manager-theme.light-mode .notif-item.warn .ic { background:rgba(217,132,0,0.14); color:#a96800; }
  body.branch-manager-theme.light-mode .notif-item[data-kind="err"] .ic,
  body.branch-manager-theme.light-mode .notif-item.err .ic,
  body.branch-manager-theme.light-mode .notif-item.warning .icon { background:rgba(207,61,61,0.12); color:var(--red-400); }
  body.branch-manager-theme.light-mode .notif-item[data-kind="ok"] .ic,
  body.branch-manager-theme.light-mode .notif-item.ok .ic,
  body.branch-manager-theme.light-mode .notif-item.success .icon { background:rgba(18,142,107,0.12); color:var(--mint-400); }
  body.branch-manager-theme.light-mode .notif-item[data-kind="info"] .ic,
  body.branch-manager-theme.light-mode .notif-item.info .ic,
  body.branch-manager-theme.light-mode .notif-item.info .icon { background:rgba(63,140,245,0.12); color:var(--sky-400); }

  /* Cards & panels */
  body.branch-manager-theme.light-mode .panel,
  body.branch-manager-theme.light-mode .stat-card { border-color:var(--lm-line); box-shadow:var(--lm-shadow); }
  body.branch-manager-theme.light-mode .side-link.active { background:rgba(217,132,0,0.10); box-shadow:inset 0 0 0 1px rgba(217,132,0,0.34); color:var(--slate-50); }
  body.branch-manager-theme.light-mode .side-branch:hover { background:var(--navy-700); border-color:rgba(217,132,0,0.35); }

  /* Header action buttons */
  body.branch-manager-theme.light-mode .action-btn-refill { background:rgba(60,130,255,0.10) !important; border:1px solid rgba(60,130,255,0.42) !important; color:#1d4ed8 !important; }
  body.branch-manager-theme.light-mode .action-btn-refill:hover { background:rgba(60,130,255,0.17) !important; }

  /* Stock overview pills */
  body.branch-manager-theme.light-mode .stock-pill.sufficient { background:rgba(18,142,107,0.12); color:#0b6b52; border-color:rgba(18,142,107,0.30); }
  body.branch-manager-theme.light-mode .stock-pill.watch { background:rgba(217,132,0,0.13); color:#9a5b00; border-color:rgba(217,132,0,0.34); }
  body.branch-manager-theme.light-mode .stock-pill.low { background:rgba(207,61,61,0.11); color:#b32d2d; border-color:rgba(207,61,61,0.30); }

  /* Tank cards */
  body.branch-manager-theme.light-mode .tank { background:var(--navy-900); border-color:var(--lm-line); box-shadow:var(--lm-shadow); }
  body.branch-manager-theme.light-mode .tank:hover:not(.disabled-tank) { border-color:rgba(217,132,0,0.45); box-shadow:0 14px 30px -18px rgba(15,23,42,0.28); }
  body.branch-manager-theme.light-mode .gauge-track { background:rgba(15,23,42,0.08); }
  body.branch-manager-theme.light-mode .tank-flag.ok { background:rgba(18,142,107,0.12); }
  body.branch-manager-theme.light-mode .tank-flag.mid { background:rgba(217,132,0,0.13); color:#9a5b00; }
  body.branch-manager-theme.light-mode .tank-flag.low { background:rgba(207,61,61,0.11); }
  body.branch-manager-theme.light-mode .tank-flag.inactive { background:rgba(88,103,126,0.12); }

  /* Tabs */
  body.branch-manager-theme.light-mode .tabs { border-bottom-color:var(--lm-line); }

  /* Tables */
  body.branch-manager-theme.light-mode .fuel-table-scroll thead th,
  body.branch-manager-theme.light-mode .refill-table-scroll thead th { background:#fff; }
  body.branch-manager-theme.light-mode .fuel-action-table thead th { border-bottom-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .fuel-action-table tbody td { border-bottom-color:var(--lm-line-soft); }
  body.branch-manager-theme.light-mode .fuel-action-table tbody tr:hover { background:var(--lm-hover); }
  body.branch-manager-theme.light-mode .fuel-row-btn { border-color:var(--lm-line-strong); background:#fff; }
  body.branch-manager-theme.light-mode .fuel-row-btn.edit-btn { color:var(--slate-50); border-color:var(--lm-line-strong); }
  body.branch-manager-theme.light-mode .fuel-row-btn.toggle-btn.on { color:#b45309; border-color:rgba(217,119,6,0.60); }
  body.branch-manager-theme.light-mode .fuel-row-btn.toggle-btn.off { color:var(--mint-400); border-color:rgba(18,142,107,0.55); }
  body.branch-manager-theme.light-mode .fuel-row-btn.delete-btn { color:var(--red-400); border-color:rgba(207,61,61,0.60); }
  body.branch-manager-theme.light-mode .fuel-row-btn.edit-btn:hover { background:var(--lm-hover); }
  body.branch-manager-theme.light-mode .fuel-row-btn.toggle-btn.on:hover { background:rgba(217,119,6,0.10); }
  body.branch-manager-theme.light-mode .fuel-row-btn.toggle-btn.off:hover { background:rgba(18,142,107,0.10); }
  body.branch-manager-theme.light-mode .fuel-row-btn.delete-btn:hover { background:rgba(207,61,61,0.09); }
  body.branch-manager-theme.light-mode .fuel-row-btn:focus-visible { outline-color:rgba(60,130,255,0.55); }

  /* Modals */
  body.branch-manager-theme.light-mode .modal-overlay { background:rgba(15,23,42,0.45); }
  body.branch-manager-theme.light-mode .modal-card-custom { border-color:var(--lm-line); box-shadow:var(--lm-shadow-pop); }
  body.branch-manager-theme.light-mode .limit-form-item { background:var(--navy-800); border-color:var(--lm-line); }
  body.branch-manager-theme.light-mode .limit-form-item label { color:var(--slate-50); }
  body.branch-manager-theme.light-mode .limit-input-wrap input { background:#fff; border-color:var(--lm-line-strong); color:var(--slate-50); }
  body.branch-manager-theme.light-mode .limit-input-wrap input:focus { border-color:var(--amber-400); box-shadow:0 0 0 1px rgba(217,132,0,0.30); }
  body.branch-manager-theme.light-mode .form-control-custom { border-color:var(--lm-line-strong); color-scheme:light; }
  body.branch-manager-theme.light-mode .form-control-custom:focus { border-color:var(--amber-400); }
  body.branch-manager-theme.light-mode .btn-secondary-custom { border-color:var(--lm-line-strong); color:var(--slate-300); }
  body.branch-manager-theme.light-mode .btn-secondary-custom:hover { background:var(--lm-hover); color:var(--slate-50); }
  body.branch-manager-theme.light-mode .delete-modal-card { background:var(--navy-900); }
  body.branch-manager-theme.light-mode .delete-modal-icon { background:rgba(207,61,61,0.12); }

  /* Toast */
  body.branch-manager-theme.light-mode .oil-toast { background:#fff; border-color:rgba(18,142,107,0.40); color:#0b6b52; box-shadow:0 12px 30px -10px rgba(15,23,42,0.28); }
  body.branch-manager-theme.light-mode .oil-toast.error { border-color:rgba(207,61,61,0.45); color:#b32d2d; }

  /* Pagination */
  body.branch-manager-theme.light-mode .pg-btn:not(.active) { background:#fff; border-color:var(--lm-line-strong); color:var(--slate-300); }
  body.branch-manager-theme.light-mode .pg-btn:not(.active):hover:not(:disabled) { background:var(--navy-700); color:var(--slate-50); }
</style>
</head>
<body>

<div class="app-shell">
  <aside class="sidebar">
    <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2 text-white fw-bold" style="padding: 6px 8px 0;">
      <img src="/images/Logo.png" alt="Logo" class="nav-logo">
      <span class="disp" style="font-size: 17px; letter-spacing: 0.5px; color: #ffffff; font-weight: 700;">Map &amp; Pump</span>
    </a>
    <div class="side-role">Branch Manager Console</div>

        <div class="side-branch">
          <img src="<?= htmlspecialchars((string)($branch['hq_logo'] ?? 'https://i.ibb.co/BV5zwXMt/19ce33ae0be7782f7e8c7ab034e00812.png')) ?>" alt="HQ Logo" class="hq-logo" onerror="this.style.display='none'">
          <div class="txt">
            <b><?= htmlspecialchars((string)($branch['branch_name'] ?? 'Branch')) ?></b>
            <span>Branch ID: <?= htmlspecialchars((string)($branch['branch_code'] ?? $branchId)) ?></span>
          </div>
        </div>

    <nav class="side-nav">
      <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-house"></i></span> Dashboard</a>
      <a href="manage-staff.php" class="<?= basename($_SERVER['PHP_SELF']) === 'manage-staff.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-users"></i></span> Manage Staff</a>
      <a href="manage-oil.php" class="<?= basename($_SERVER['PHP_SELF']) === 'manage-oil.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-gas-pump"></i></span> Manage Fuel Stock</a>
      <a href="booking-slot.php" class="<?= basename($_SERVER['PHP_SELF']) === 'booking-slot.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-ticket"></i></span> Monitor Booking Slot</a>
      <a href="view-customers.php" class="<?= basename($_SERVER['PHP_SELF']) === 'view-customers.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-address-book"></i></span> View Customers</a>
      <a href="report-hq.php" class="<?= basename($_SERVER['PHP_SELF']) === 'report-hq.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-paper-plane"></i></span> Report to HQ</a>
      <a href="branchprofile.php" class="<?= basename($_SERVER['PHP_SELF']) === 'branchprofile.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-building"></i></span> Branch Profile</a>
      <a href="edit-profile.php" class="<?= basename($_SERVER['PHP_SELF']) === 'edit-profile.php' ? 'side-link active' : 'side-link' ?>"><span class="ic"><i class="fa-solid fa-gear"></i></span> Edit Profile</a>
    </nav>

    <a href="../Login/logout.php" class="side-logout"><span class="ic"><i class="fa-solid fa-right-from-bracket"></i></span> Log Out</a>
  </aside>

  <div class="main">
    <div class="topbar">
      <div>
        <h1>Manage Fuel Stock</h1>
        <div class="date" id="live-clock">Friday, Oct 30, 2026 · 07:43:02 PM</div>
      </div>
      <div class="topbar-right">
        <div class="bell" id="notif-bell"><i class="fa-regular fa-bell"></i><?php if ($has_unread): ?><span class="ping" id="notif-ping"></span><?php endif; ?></div>
        
        <div class="notif-popup" id="notif-popup">
          <div class="notif-header">
            <h4>Notifications</h4>
          </div>
          <div class="notif-list" id="notif-list">
            <?php if (!$alerts): ?>
              <div class="notif-empty">No new notifications</div>
            <?php else:
              foreach ($alerts as $a):
                $meta = notif_ui_meta($a['type'], $a['title']);
                $icon = $meta['icon'];
                $tone = $meta['tone'];
            ?>
              <div class="notif-item <?= h($tone) ?>" data-notif-id="<?= (int)$a['notif_id'] ?>" data-link="<?= h($a['link'] ?? 'dashboard.php') ?>" data-kind="<?= h($tone) ?>">
                <div class="notif-item-body">
                  <b><?= h($a['title']) ?></b>
                  <span><?= h($a['body']) ?></span>
                  <div class="notif-time"><?= h($a['time_ago']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <label class="staff-theme-control" title="Toggle light mode">
          <input class="js-theme-toggle" type="checkbox" aria-label="Toggle light mode">
          <span><i class="fa-solid fa-sun"></i></span>
        </label>

        <div class="avatar-chip">
          <?php if ($managerAvatarUrl !== ''): ?>
          <img src="<?= htmlspecialchars($managerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Profile Picture" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.12)">
          <?php else: ?>
          <div class="avatar-circle"><a href="edit-profile.php"><?= htmlspecialchars(mb_substr($initials, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a></div>
          <?php endif; ?>
          <div><b><a href="edit-profile.php"><?= htmlspecialchars($managerDisplayName, ENT_QUOTES, 'UTF-8') ?></a></b></div>
        </div>
      </div>
    </div>

    <div class="content">
      <div class="page-head">
        <div></div>
        <div class="page-head-actions">
          <button class="btn-primary action-btn-blue" id="open-add-oil-modal">
            <i class="fa-solid fa-plus-circle"></i> Add Fuel Type
          </button>
          <button class="btn-primary action-btn-refill" id="open-refill-modal">
            <i class="fa-solid fa-gas-pump"></i> Refill Fuel
          </button>
          <button class="btn-primary action-btn-amber" id="open-limit-modal">
            <i class="fa-solid fa-gauge-high"></i> Threshold Settings
          </button>
        </div>
      </div>
      <div class="tabs">
        <button class="tab-btn active" id="tabbtn-amount" onclick="showTab('amount')">Fuel Types</button>
        <button class="tab-btn" id="tabbtn-types" onclick="showTab('types')">Manage Fuel Types</button>
        <button class="tab-btn" id="tabbtn-refill-history" onclick="showTab('refill-history')">Refill History</button>
      </div>

      <!-- TAB 1: MANAGE STOCK LEVELS -->
      <div class="tab-panel active" id="tab-amount">
        <div class="stock-overview">
          <div>
            <h2><span>All Fuel Types</span> <span style="font-size:14px; font-weight:600; color:var(--amber-400); margin-left:6px;">~ <?= count($tanks) ?> total</span></h2>
          </div>
          <div class="stock-pills">
            <span class="stock-pill sufficient"><?= (int)($tankCounts['ok'] ?? 0) ?> Sufficient</span>
            <span class="stock-pill watch"><?= (int)($tankCounts['mid'] ?? 0) ?> Watch</span>
            <span class="stock-pill low"><?= (int)($tankCounts['low'] ?? 0) ?> Low</span>
          </div>
        </div>
        <div id="tank-grid-pg-wrap"><div class="tank-grid" id="tank-grid">
          <?php foreach ($tanks as $tank):
            $pct = (float)($tank['pct'] ?? 0);
            $status = $tank['status_class'] ?? 'ok';
            $statusMeta = [
              'ok' => ['color' => 'var(--mint-400)', 'label' => 'Stock sufficient', 'icon' => 'fa-check', 'class' => 'ok'],
              'mid' => ['color' => 'var(--amber-300)', 'label' => 'Watch level', 'icon' => 'fa-circle-half-stroke', 'class' => 'mid'],
              'low' => ['color' => 'var(--red-400)', 'label' => 'Stock low — delivery needed', 'icon' => 'fa-triangle-exclamation', 'class' => 'low'],
            ][$status] ?? ['color' => 'var(--mint-400)', 'label' => 'Stock sufficient', 'icon' => 'fa-check', 'class' => 'ok'];
            $remainingText = number_format((float)$tank['current_l'], 2, '.', ',') . ' / ' . number_format((int)$tank['capacity_l'], 0, '.', ',') . ' L remaining';
            $isDisabled = empty($tank['is_enabled']) ? 'disabled' : '';
          ?>
            <div class="tank <?= $isDisabled ?>" id="tank-<?= (int)$tank['tank_id'] ?>" data-capacity="<?= (int)$tank['capacity_l'] ?>" data-tankid="<?= (int)$tank['tank_id'] ?>">
              <div class="top">
                <div>
                  <div class="fuel-name"><?= htmlspecialchars($tank['fuel_name']) ?></div>
                </div>
                <div class="pct" id="pct-<?= (int)$tank['tank_id'] ?>" style="color:<?= $statusMeta['color'] ?>"><?= round($pct) ?>%</div>
              </div>
              <div class="gauge-track"><i id="gauge-<?= (int)$tank['tank_id'] ?>" style="width:<?= min(100, max(0, $pct)) ?>%; background:<?= $statusMeta['color'] ?>;"></i></div>
              <div class="liters">
                <span id="rem-<?= (int)$tank['tank_id'] ?>"><?= $remainingText ?></span>
              </div>
              <span class="tank-flag <?= $statusMeta['class'] ?>" id="flag-<?= (int)$tank['tank_id'] ?>"><i class="fa-solid <?= $statusMeta['icon'] ?>"></i> <?= $statusMeta['label'] ?></span>
            </div>
          <?php endforeach; ?>
        </div></div><!-- /#tank-grid-pg-wrap -->
        <div class="pg-controls" id="tank-grid-pg-controls"></div>

      </div>

      <!-- TAB 2: MANAGE FUEL TYPES -->
      <div class="tab-panel" id="tab-types">
        <div class="panel fuel-table-scroll" style="max-height: none; overflow-y: hidden;">
          <table class="fuel-action-table fuel-types-table">
            <thead>
              <tr>
                <th style="width:30%;">Name</th>
                <th style="width:24%;">Stock Level</th>
                <th style="width:18%;">Price</th>
                <th style="width:28%; text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody id="fuel-table-tbody">
              <?php foreach ($tanks as $tank):
                $statusColor = ['ok' => 'var(--mint-400)', 'mid' => 'var(--amber-300)', 'low' => 'var(--red-400)'][$tank['status_class'] ?? 'ok'] ?? 'var(--mint-400)';
                $remainingText = number_format((float)$tank['current_l'], 2, '.', ',') . ' / ' . number_format((int)$tank['capacity_l'], 0, '.', ',') . ' L remaining';
                $enabled = !empty($tank['is_enabled']);
                $fuelPrice = (float)($tank['fuel_price'] ?? 0);
              ?>
                <tr data-tank-id="<?= (int)$tank['tank_id'] ?>" data-fuel-code="fuel_<?= (int)$tank['tank_id'] ?>">
                  <td class="fuel-name-cell"><?= htmlspecialchars($tank['fuel_name']) ?></td>
                  <td class="fuel-stock-meta"><span style="color:<?= $statusColor ?>; font-weight:700; margin-right:6px;"> </span><?= $remainingText ?></td>
                  <td class="fuel-stock-meta fuel-price-cell"><?= number_format($fuelPrice, 2, '.', ',') ?></td>
                  <td class="fuel-action-cell">
                    <div class="fuel-actions">
                      <button type="button" class="fuel-row-btn edit-btn edit-action" data-action="edit" data-id="<?= (int)$tank['tank_id'] ?>" data-fuel="fuel_<?= (int)$tank['tank_id'] ?>" data-name="<?= htmlspecialchars($tank['fuel_name'], ENT_QUOTES, 'UTF-8') ?>" data-capacity="<?= (int)$tank['capacity_l'] ?>" data-price="<?= htmlspecialchars((string)($tank['fuel_price'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" title="Edit fuel" aria-label="Edit fuel">
                        Edit
                      </button>
                      <button type="button" class="fuel-row-btn toggle-btn <?= $enabled ? 'on' : 'off' ?>" data-action="toggle" data-id="<?= (int)$tank['tank_id'] ?>" data-fuel="fuel_<?= (int)$tank['tank_id'] ?>" data-enabled="<?= (int)$enabled ?>" title="<?= $enabled ? 'Disable fuel' : 'Enable fuel' ?>" aria-label="<?= $enabled ? 'Disable fuel' : 'Enable fuel' ?>">
                        <?= $enabled ? 'Disable' : 'Enable' ?>
                      </button>
                      <button type="button" class="fuel-row-btn delete-btn" data-action="delete" data-id="<?= (int)$tank['tank_id'] ?>" data-fuel="fuel_<?= (int)$tank['tank_id'] ?>" title="Delete fuel type" aria-label="Delete fuel type">
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: REFILL HISTORY -->
      <div class="tab-panel" id="tab-refill-history">
        <div class="panel">
          <div class="refill-history-toolbar">
            <div class="refill-history-heading">
              <h3>Refill History</h3>
              <p>Track fuel refills recorded for this branch.</p>
            </div>
            <form class="logs-modal-filters" id="refill-filters" method="get" action="manage-oil.php">
              <label><span>From</span><input type="date" name="refill_start_date" value="<?= h($refillStart) ?>" min="<?= h($branchCreatedDate) ?>" max="<?= h($todayDate) ?>"></label>
              <label><span>To</span><input type="date" name="refill_end_date" value="<?= h($refillEnd) ?>" min="<?= h($branchCreatedDate) ?>" max="<?= h($todayDate) ?>"></label>
              <button type="submit" class="logs-modal-btn">Apply</button>
              <button type="button" class="logs-modal-btn" id="refill-clear-btn">Clear</button>
            </form>
          </div>
          <div class="refill-table-scroll" id="refill-scroll">
          <table class="fuel-action-table">
            <thead>
              <tr>
                <th style="width:35%;">Fuel Name</th>
                <th style="width:25%; text-align:center;">Quantity</th>
                <th style="width:40%; text-align:right;">Refill Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($refills)): ?>
                <tr>
                  <td colspan="3" class="fuel-stock-meta" style="text-align:center; padding:18px;">No refill history is recorded for the selected date range.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($refills as $refill): ?>
                  <tr>
                    <td class="fuel-name-cell"><?= htmlspecialchars($refill['fuel_name']) ?></td>
                    <td class="fuel-stock-meta refill-history-quantity" style="text-align:center;"><?= number_format((float)$refill['quantity_l'], 2, '.', ',') ?> L</td>
                    <td class="fuel-stock-meta refill-history-date" style="text-align:right;"><?= date('d-m-Y', strtotime($refill['update_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </div>
      </div>

      <div class="page-foot">© 2026 MAP &amp; PUMP | All Rights Reserved</div>
    </div>
  </div>
</div>

<!-- ---------- Manage Oil Limit Modal ---------- -->
<div class="modal-overlay" id="limit-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <div class="limit-modal-head">
        <h3>Threshold Settings</h3>
      </div>
      <button class="modal-close" id="limit-modal-close-btn">×</button>
    </div>
    <p class="limit-modal-sub">
      Set the low-stock alert threshold for each fuel type. A tank flags "Low Stock" once its level falls below this percentage.
    </p>
    <form id="limit-form" method="POST" action="manage-oil.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_limits">
      <div class="limit-form-list" id="limit-form-list">
        <?php foreach ($tanks as $tank): ?>
                   <div class="limit-form-item">
            <div class="limit-name-wrap">
              <span class="limit-dot"></span>
              <label for="limit-<?= (int)$tank['tank_id'] ?>"><?= htmlspecialchars($tank['fuel_name']) ?></label>
            </div>
            <div class="limit-input-wrap">
              <input id="limit-<?= (int)$tank['tank_id'] ?>" name="alert_tank_<?= (int)$tank['tank_id'] ?>" type="number" min="5" max="80" value="<?= (int)($tank['alert_pct'] ?? 25) ?>" required="">
              <span class="pct-suffix">%</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="pg-controls" id="limit-pg-controls" style="margin-bottom:8px;"></div>
      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="limit-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px; background:linear-gradient(135deg, #f7d66a, #d89f06); color:#1d1306;">Save Limits</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Add Fuel Type Modal ---------- -->
<div class="modal-overlay" id="add-oil-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Add New Fuel Type</h3>
      <button class="modal-close" id="add-oil-modal-close-btn">×</button>
    </div>
    <form id="add-oil-form" method="POST" action="manage-oil.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_fuel_type">

      <div class="form-group">
        <label for="new-fuel-name">Fuel Display Name</label>
        <input type="text" id="new-fuel-name" name="fuel_name" class="form-control-custom" required="" placeholder="e.g. Octane 98">
      </div>

      <div class="form-group">
        <label for="new-tank-capacity">Tank Capacity (Liters)</label>
        <input type="number" id="new-tank-capacity" name="capacity_l" class="form-control-custom" min="1000" value="10000" required="">
      </div>

      <div class="form-group">
        <label for="new-alert-pct">Low Stock Alert (%)</label>
        <input type="number" id="new-alert-pct" name="alert_pct" class="form-control-custom" min="5" max="80" value="20" required="">
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="add-oil-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Fuel Type</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Edit Fuel Type Modal ---------- -->
<div class="modal-overlay" id="edit-fuel-modal">
  <div class="modal-card-custom">
    <div class="modal-header-custom">
      <h3>Edit Fuel Type</h3>
      <button class="modal-close" id="edit-fuel-close-btn">×</button>
    </div>
    <form id="edit-fuel-form" method="POST" action="manage-oil.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="edit_fuel_type">
      <input type="hidden" name="tank_id" id="edit-fuel-tank-id">
      <input type="hidden" name="fuel_code" id="edit-fuel-code">

      <div class="form-group">
        <label for="edit-fuel-name">Fuel Name</label>
        <input type="text" id="edit-fuel-name" name="fuel_name" class="form-control-custom" required="">
      </div>

      <div class="form-group">
        <label for="edit-fuel-capacity">Tank Capacity (Liters)</label>
        <input type="number" id="edit-fuel-capacity" name="capacity_l" class="form-control-custom" min="1" max="200000" required="">
      </div>

      <div class="form-group">
        <label for="edit-fuel-price">Price</label>
        <input type="number" id="edit-fuel-price" name="fuel_price" class="form-control-custom" min="0" step="0.01" required="">
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary-custom" id="edit-fuel-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" style="box-shadow: none; padding: 10px 18px;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Refill Fuel Modal ---------- -->
<div class="modal-overlay" id="refill-modal">
  <div class="modal-card-custom refill-modal-card">
    <div class="modal-header-custom" style="margin-bottom: 10px;">
      <h3 class="refill-modal-title">Refill Fuel</h3>
      <button class="modal-close" id="refill-modal-close-btn">×</button>
    </div>

    <form id="refill-form" method="POST" action="manage-oil.php">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_refill">

      <p class="refill-modal-sub">Add fuel directly to an existing tank.</p>

      <div class="refill-field">
        <label for="refill-fuel-type">Fuel Type</label>
        <select id="refill-fuel-type" name="tank_id" class="form-control-custom">
          <?php foreach ($tanks as $tank): ?>
            <option value="<?= (int)$tank['tank_id'] ?>"><?= htmlspecialchars($tank['fuel_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="refill-field">
        <label for="refill-quantity">Quantity (Liters)</label>
        <input type="number" id="refill-quantity" name="quantity_l" class="form-control-custom" min="1" step="0.01" value="5000" required="">
      </div>

      <div class="refill-actions">
        <button type="button" class="btn-secondary-custom" id="refill-modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary" id="refill-submit-btn" style="box-shadow: none; padding: 10px 18px;">Refill Fuel</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------- Delete Confirmation Modal ---------- -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-card-custom delete-modal-card">
    <div class="delete-modal-icon">
      <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <h3 class="delete-modal-title">Delete Fuel Record?</h3>
    <div id="delete-modal-msg" class="delete-modal-message">
           <p>Confirm this is the correct record before continuing.</p>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn-secondary-custom" id="delete-cancel-btn">Cancel</button>
      <button type="button" id="delete-confirm-btn" style="background:linear-gradient(135deg,#f87171,#b91c1c); color:#fff; font-weight: 700; font-size:13px; padding:10px 18px; border-radius:10px; border:none; cursor:pointer;">Delete Permanently</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
   // Tab Switching Controller

  function showTab(name){



    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('tabbtn-' + name).classList.add('active');
    localStorage.setItem('map_pump_oil_tab', name);
  }

  const savedTab = localStorage.getItem('map_pump_oil_tab');
  if (savedTab && document.getElementById('tab-' + savedTab)) {
    showTab(savedTab);
  }

  document.addEventListener('DOMContentLoaded', () => {

    // ---- Toast Notification Helper ----
    const oilToast = document.getElementById('oil-toast');
    function showToast(msg, type) {
      if (!oilToast) return;
      const msgEl = oilToast.querySelector('.toast-msg');
      if (msgEl) msgEl.textContent = msg;
      oilToast.classList.toggle('error', type === 'error');
      oilToast.classList.add('show');
      oilToast.setAttribute('aria-hidden', 'false');
      setTimeout(() => {
        oilToast.classList.remove('show');
        oilToast.setAttribute('aria-hidden', 'true');
      }, 4000);
    }
    const toastCloseBtn = oilToast ? oilToast.querySelector('.toast-close') : null;
    if (toastCloseBtn) {
      toastCloseBtn.addEventListener('click', () => {
        oilToast.classList.remove('show');
        oilToast.setAttribute('aria-hidden', 'true');
      });
    }
    const pendingToast = sessionStorage.getItem('oil_toast_msg');
    if (pendingToast) {
      sessionStorage.removeItem('oil_toast_msg');
      setTimeout(() => showToast(pendingToast), 150);
    }

    // 1. Digital Clock Component
    function updateClock() {
      const now = new Date();
      const options = { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' };
      const dateString = now.toLocaleDateString('en-US', options);
      const timeString = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
      document.getElementById('live-clock').textContent = `${dateString} · ${timeString}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Notification Popup Controller
    const bellBtn = document.getElementById('notif-bell');
    const notifPopup = document.getElementById('notif-popup');
    const notifList = document.getElementById('notif-list');
    const notifPing = document.getElementById('notif-ping');

    function postDismiss(payload) {
      return fetch('api_dismiss_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then((r) => r.json()).catch(() => ({ ok: false }));
    }

    if (bellBtn && notifPopup) {
      bellBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const ping = bellBtn.querySelector('.ping');
        if (ping) {
          ping.style.transform = 'scale(0)';
          setTimeout(() => ping.remove(), 180);
        }
        notifPopup.classList.toggle('show');
      });

      document.addEventListener('click', (e) => {
        const clickedBell = bellBtn.contains(e.target);
        const clickedPopup = notifPopup.contains(e.target);
        if (!clickedBell && !clickedPopup) {
          notifPopup.classList.remove('show');
        }
      });
    }

    if (notifList) {
      notifList.addEventListener('click', (e) => {
        const item = e.target.closest('.notif-item');
        if (!item) return;

        const id = Number(item.dataset.notifId || 0);
        const link = item.dataset.link || 'dashboard.php';

        if (id > 0) {
          postDismiss({ notif_id: id }).then((res) => {
            if (res.ok && notifPing && Number(res.unread || 0) <= 0) {
              notifPing.remove();
            }
          });
        }

        if (link) {
          window.location.href = link;
        }
      });
    }

    const deleteModal = document.getElementById('delete-modal');
    const deleteModalTitle = deleteModal ? deleteModal.querySelector('.delete-modal-title') : null;
    const deleteModalMessage = deleteModal ? deleteModal.querySelector('.delete-modal-message') : null;
    const deleteCancelBtn = document.getElementById('delete-cancel-btn');
    const deleteConfirmBtn = document.getElementById('delete-confirm-btn');
    let pendingDeleteHandler = null;

    function closeDeleteModal() {
      if (deleteModal) deleteModal.classList.remove('active');
      if (deleteConfirmBtn) deleteConfirmBtn.disabled = false;
      pendingDeleteHandler = null;
    }

    function openDeleteModal(title, message, handler) {
      if (!deleteModal || !deleteModalTitle || !deleteModalMessage || !deleteConfirmBtn) return;
      deleteModalTitle.textContent = title;
      deleteModalMessage.innerHTML = '';
      const msg = document.createElement('p');
      msg.textContent = message;
      deleteModalMessage.appendChild(msg);
      pendingDeleteHandler = handler;
      deleteConfirmBtn.disabled = false;
      deleteModal.classList.add('active');
    }

    if (deleteCancelBtn) deleteCancelBtn.addEventListener('click', closeDeleteModal);
    if (deleteModal) deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });
    if (deleteConfirmBtn) {
      deleteConfirmBtn.addEventListener('click', async () => {
        if (typeof pendingDeleteHandler !== 'function') {
          closeDeleteModal();
          return;
        }
        deleteConfirmBtn.disabled = true;
        try {
          await pendingDeleteHandler();
        } finally {
          closeDeleteModal();
        }
      });
    }

    const addFuelModal = document.getElementById('add-oil-modal');
    const addFuelForm = document.getElementById('add-oil-form');
    const openAddFuelBtn = document.getElementById('open-add-oil-modal');
    const closeAddFuelBtn = document.getElementById('add-oil-modal-close-btn');
    const cancelAddFuelBtn = document.getElementById('add-oil-modal-cancel-btn');

    function getCsrfToken() {
      const candidates = Array.from(document.querySelectorAll('input[name="csrf"]'))
        .map((input) => String(input.value || '').trim())
        .filter(Boolean);
      return candidates[0] || '';
    }

    if (openAddFuelBtn && addFuelModal) {
      openAddFuelBtn.addEventListener('click', () => {
        if (addFuelForm) addFuelForm.reset();
        addFuelModal.classList.add('active');
      });
    }

    const closeAddFuelModal = () => addFuelModal && addFuelModal.classList.remove('active');
    if (closeAddFuelBtn) closeAddFuelBtn.addEventListener('click', closeAddFuelModal);
    if (cancelAddFuelBtn) cancelAddFuelBtn.addEventListener('click', closeAddFuelModal);
    if (addFuelModal) addFuelModal.addEventListener('click', (e) => { if (e.target === addFuelModal) closeAddFuelModal(); });

    if (addFuelForm) {
      addFuelForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(addFuelForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            showToast('Add fuel type failed: ' + (data.error || 'unknown'), 'error');
            return;
          }
          closeAddFuelModal();
          sessionStorage.setItem('oil_toast_msg', 'Successfully Added.');
          window.location.reload();
        } catch (err) {
          showToast('Network error: ' + err.message, 'error');
        }
      });
    }

    const editFuelModal = document.getElementById('edit-fuel-modal');
    const editFuelForm = document.getElementById('edit-fuel-form');
    const editFuelCloseBtn = document.getElementById('edit-fuel-close-btn');
    const editFuelCancelBtn = document.getElementById('edit-fuel-cancel-btn');

    function openEditFuelModal(button) {
      if (!button || !editFuelModal || !editFuelForm) return;
      const id = button.dataset.id || '';
      const fuelCode = button.dataset.fuel || '';
      const name = button.dataset.name || '';
      const capacity = button.dataset.capacity || '';

      document.getElementById('edit-fuel-tank-id').value = id;
      document.getElementById('edit-fuel-code').value = fuelCode;
      document.getElementById('edit-fuel-name').value = name;
      document.getElementById('edit-fuel-capacity').value = capacity;

      refreshFuelPriceForEdit(button);
      editFuelModal.classList.add('active');
    }

    async function refreshFuelPriceForEdit(button) {
      const tankId = button?.dataset?.id || '';
      if (!tankId) return;

      const formData = new FormData();
      formData.append('action', 'get_fuel_type');
      formData.append('tank_id', String(tankId));
      formData.append('csrf', getCsrfToken());

      try {
        const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.ok || !data.fuel || data.fuel.fuel_price === undefined) {
          return;
        }

        const latestPrice = String(data.fuel.fuel_price);
        button.dataset.price = latestPrice;
        const priceInput = document.getElementById('edit-fuel-price');
        if (priceInput) {
          priceInput.value = latestPrice;
        }
      } catch (err) {
        console.error('Failed to fetch latest fuel price:', err);
      }
    }

    if (editFuelForm) {
      editFuelForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(editFuelForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            showToast('Edit fuel type failed: ' + (data.error || 'unknown'), 'error');
            return;
          }

          const tankId = String(formData.get('tank_id') || '');
          const row = document.querySelector(`tr[data-tank-id="${CSS.escape(tankId)}"]`);
          const newPrice = String(formData.get('fuel_price') || '0');
          if (row) {
            const priceCell = row.querySelector('.fuel-price-cell');
            if (priceCell) {
              priceCell.textContent = Number(newPrice).toFixed(2);
            }
            const editBtn = row.querySelector('.edit-btn');
            if (editBtn) {
              editBtn.dataset.price = newPrice;
            }
          }

          closeEditFuelModal();
          sessionStorage.setItem('oil_toast_msg', 'Successfully Edited.');
          // Keep the current page in sync without a full reload so the Price cell updates immediately.
        } catch (err) {
          showToast('Network error: ' + err.message, 'error');
        }
      });
    }

    document.querySelectorAll('.edit-action').forEach((btn) => {
      btn.addEventListener('click', async () => {
        await openEditFuelModal(btn);
      });
    });

    document.querySelectorAll('.toggle-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const formData = new FormData();
        formData.append('action', 'toggle_fuel');
        formData.append('tank_id', btn.dataset.id || '');
        formData.append('enabled', btn.dataset.enabled === '1' ? '0' : '1');
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.append('csrf', csrfValue);

        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            showToast('Failed to update fuel status: ' + (data.error || 'unknown'), 'error');
            return;
          }
          window.location.reload();
        } catch (err) {
          showToast('Network error: ' + err.message, 'error');
        }
      });
    });

    function bindDeleteButtons() {
      document.querySelectorAll('.delete-btn').forEach((btn) => {
        btn.onclick = async () => {
          const row = btn.closest('tr');
          const targetName = row?.querySelector('.fuel-name-cell')?.textContent?.trim() || 'this fuel record';
          const action = btn.dataset.action || 'delete';
          const isFuelType = action === 'delete' && btn.dataset.fuel;
          const title = isFuelType ? 'Delete Fuel Type?' : 'Delete Fuel Record?';
          const message = isFuelType
            ? `Delete ${targetName}? This will remove the fuel type from the database.`
            : `Delete ${targetName}? This will remove the refill record from the database.`;

          openDeleteModal(title, message, async () => {
            const formData = new FormData();
            formData.append('action', isFuelType ? 'delete_fuel_type' : 'delete_refill');
            formData.append('tank_id', btn.dataset.id || '');
            const csrfValue = getCsrfToken();
            if (csrfValue) formData.append('csrf', csrfValue);

            try {
              const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
              const data = await res.json();
              if (!data.ok) {
                showToast((isFuelType ? 'Delete fuel type failed: ' : 'Delete refill record failed: ') + (data.error || 'unknown'), 'error');
                return;
              }
              sessionStorage.setItem('oil_toast_msg', 'Successfully Deleted.');
              window.location.reload();
            } catch (err) {
              showToast('Network error: ' + err.message, 'error');
            }
          });
        };
      });
    }

    bindDeleteButtons();

    const limitModal = document.getElementById('limit-modal');
    const openLimitBtn = document.getElementById('open-limit-modal');
    const closeLimitBtn = document.getElementById('limit-modal-close-btn');
    const cancelLimitBtn = document.getElementById('limit-modal-cancel-btn');
    const limitForm = document.getElementById('limit-form');
    const closeLimitModal = () => limitModal && limitModal.classList.remove('active');

    if (openLimitBtn && limitModal) {
      openLimitBtn.addEventListener('click', () => limitModal.classList.add('active'));
    }
    if (closeLimitBtn) closeLimitBtn.addEventListener('click', closeLimitModal);
    if (cancelLimitBtn) cancelLimitBtn.addEventListener('click', closeLimitModal);
    if (limitModal) limitModal.addEventListener('click', (e) => { if (e.target === limitModal) closeLimitModal(); });

    if (limitForm) {
      limitForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(limitForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            showToast('Save limits failed: ' + (data.error || 'unknown'), 'error');
            return;
          }
          closeLimitModal();
          sessionStorage.setItem('oil_toast_msg', 'Successfully Edited.');
          window.location.reload();
        } catch (err) {
          showToast('Network error: ' + err.message, 'error');
        }
      });
    }

    const refillModal = document.getElementById('refill-modal');
    const openRefillBtn = document.getElementById('open-refill-modal');
    const closeRefillBtn = document.getElementById('refill-modal-close-btn');
    const cancelRefillBtn = document.getElementById('refill-modal-cancel-btn');
    const refillForm = document.getElementById('refill-form');
    const closeRefillModal = () => refillModal && refillModal.classList.remove('active');

    if (openRefillBtn && refillModal) {
      openRefillBtn.addEventListener('click', () => {
        if (refillForm) refillForm.reset();
        refillModal.classList.add('active');
      });
    }
    if (closeRefillBtn) closeRefillBtn.addEventListener('click', closeRefillModal);
    if (cancelRefillBtn) cancelRefillBtn.addEventListener('click', closeRefillModal);
    if (refillModal) refillModal.addEventListener('click', (e) => { if (e.target === refillModal) closeRefillModal(); });

    if (refillForm) {
      refillForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(refillForm);
        const csrfValue = getCsrfToken();
        if (csrfValue) formData.set('csrf', csrfValue);
        try {
          const res = await fetch('manage-oil.php', { method: 'POST', body: formData });
          const data = await res.json();
          if (!data.ok) {
            const errMsg = (data.error && data.error.toLowerCase().includes('capac'))
              ? 'The refill amount cannot exceed the available tank capacity.'
              : ('Refill fuel failed: ' + (data.error || 'unknown'));
            showToast(errMsg, 'error');
            return;
          }
          closeRefillModal();
          sessionStorage.setItem('oil_toast_msg', 'Successfully Refilled.');
          window.location.reload();
        } catch (err) {
          showToast('Network error: ' + err.message, 'error');
        }
      });
    }

    function getThresholdStatus(percent, threshold) {
      const alert = Number(threshold ?? 25);
      if (percent <= alert) {
        return { status: 'low', label: 'Low', className: 'low', icon: 'fa-triangle-exclamation', text: 'Stock low — delivery needed' };
      }
      if (percent < 60) {
        return { status: 'watch', label: 'Watch', className: 'mid', icon: 'fa-circle-half-stroke', text: 'Watch level' };
      }
      return { status: 'sufficient', label: 'Sufficient', className: 'ok', icon: 'fa-check', text: 'Stock sufficient' };
    }

    // ===== PAGINATION HELPER =====
    function makePaginator(items, perPage, controlsEl, onPageChange, storageKey, hideNav) {
      let currentPage = 1;
      // Keep the current page after add/edit/delete/toggle (they reload the page); render() clamps it if it no longer exists.
      if (storageKey) {
        try {
          const nav = performance.getEntriesByType('navigation')[0];
          const saved = parseInt(sessionStorage.getItem(storageKey), 10);
          if (nav && nav.type === 'reload' && saved > 0) currentPage = saved;
        } catch (e) {}
      }
      function totalPages() { return Math.max(1, Math.ceil(items.length / perPage)); }
      function render() {
        const tp = totalPages();
        // clamp currentPage
        if (currentPage > tp) currentPage = tp;
        if (currentPage < 1) currentPage = 1;
        if (storageKey) { try { sessionStorage.setItem(storageKey, String(currentPage)); } catch (e) {} }
        // call callback with slice
        const start = (currentPage - 1) * perPage;
        onPageChange(items, start, start + perPage, currentPage);
        // build controls
        controlsEl.innerHTML = '';
        if (tp <= 1) return;
        function btn(label, disabled, active, click) {
          const b = document.createElement('button');
          b.className = 'pg-btn' + (active ? ' active' : '');
          b.textContent = label;
          b.disabled = disabled;
          if (!disabled) b.addEventListener('click', click);
          controlsEl.appendChild(b);
        }
        if (!hideNav) btn('Previous', currentPage === 1, false, () => { currentPage--; render(); });
        for (let p = 1; p <= tp; p++) {
          (function(pg) {
            btn(String(pg), false, pg === currentPage, () => { currentPage = pg; render(); });
          })(p);
        }
        if (!hideNav) btn('Next', currentPage === tp, false, () => { currentPage++; render(); });
      }
      return { render, goToPage: (p) => { currentPage = p; render(); }, getPage: () => currentPage, getTotal: totalPages };
    }

    // ===== Refill History: filter clear + scroll after 10 rows =====
    (function() {
      const clearBtn = document.getElementById('refill-clear-btn');
      const form = document.getElementById('refill-filters');
      if (clearBtn && form) {
        clearBtn.addEventListener('click', () => {
          form.querySelectorAll('input[type="date"]').forEach(input => { input.value = ''; });
          form.submit();
        });
      }
      const box = document.getElementById('refill-scroll');
      function updateRefillScrollState() {
        if (!box) return;
        const rows = box.querySelectorAll('tbody tr');
        if (rows.length <= 10) {
          box.classList.remove('has-scroll');
          box.style.maxHeight = 'none';
          box.style.overflowY = 'hidden';
          return;
        }
        let height = box.querySelector('thead').getBoundingClientRect().height;
        for (let i = 0; i < 10; i++) height += rows[i].getBoundingClientRect().height;
        if (!height) return;
        const style = getComputedStyle(box);
        height += parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
        box.classList.add('has-scroll');
        box.style.maxHeight = height + 'px';
        box.style.overflowY = 'auto';
      }
      updateRefillScrollState();
      window.addEventListener('resize', updateRefillScrollState);
      document.getElementById('tabbtn-refill-history')?.addEventListener('click', () => setTimeout(updateRefillScrollState, 0));
    })();

    // ===== TAB 1: Tank Grid Pagination (6 per page, 2-col grid) =====
    (function() {
      const grid = document.getElementById('tank-grid');
      const controls = document.getElementById('tank-grid-pg-controls');
      if (!grid || !controls) return;
      const tanks = Array.from(grid.children);
      if (tanks.length === 0) return;
      const pg = makePaginator(tanks, 6, controls, (items, start, end, page) => {
        items.forEach((el, idx) => {
          el.style.display = (idx >= start && idx < end) ? '' : 'none';
        });
      }, 'oil_pg_fuel_types', true);
      pg.render();
    })();

    // ===== LIMIT MODAL: Threshold Pagination (4 per page, 2x2 grid) =====
    (function() {
      const list = document.getElementById('limit-form-list');
      const controls = document.getElementById('limit-pg-controls');
      if (!list || !controls) return;
      const items = Array.from(list.querySelectorAll('.limit-form-item'));
      if (items.length === 0) return;
      const pg = makePaginator(items, 4, controls, (allItems, start, end, page) => {
        allItems.forEach((el, idx) => {
          el.style.display = (idx >= start && idx < end) ? '' : 'none';
        });
      }, 'oil_pg_threshold', true);
      pg.render();
      // If a hidden (other-page) input fails validation, jump to its page so the browser can show the error.
      list.addEventListener('invalid', (e) => {
        const idx = items.indexOf(e.target.closest('.limit-form-item'));
        if (idx >= 0) {
          const target = Math.floor(idx / 4) + 1;
          if (target !== pg.getPage()) pg.goToPage(target);
        }
      }, true);
    })();
  });
</script>

<!-- Success Toast -->
<div id="oil-toast" class="oil-toast" aria-hidden="true">
  <div class="toast-msg"></div>
  <button type="button" class="toast-close" aria-label="Close">×</button>
</div>

<script>
  (function () {
    const root = document.body;
    const storedTheme = localStorage.getItem('map_pump_branch_manager_theme');
    const toggle = document.querySelector('.js-theme-toggle');
    const icon = document.querySelector('.staff-theme-control span i');

    function applyTheme(isLight) {
      root.classList.toggle('light-mode', isLight);
      if (toggle) toggle.checked = isLight;
      if (icon) {
        icon.className = isLight ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
      }
      const control = document.querySelector('.staff-theme-control');
      if (control) control.title = isLight ? 'Toggle dark mode' : 'Toggle light mode';
      localStorage.setItem('map_pump_branch_manager_theme', isLight ? 'light' : 'dark');
    }

    root.classList.add('branch-manager-theme');
    applyTheme(storedTheme === 'light');

    if (toggle) {
      toggle.addEventListener('change', function () {
        applyTheme(this.checked);
      });
    }
  })();
</script>

</body></html>