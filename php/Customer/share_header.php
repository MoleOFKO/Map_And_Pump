<?php
session_start();

// Simulated login session check
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'CUST-2026-88';
    $_SESSION['user_name'] = 'Larry';
    $_SESSION['user_email'] = 'larry@example.com';
    $_SESSION['oil_limit'] = 50.0; // Max Allowed Liters
    $_SESSION['oil_used'] = 12.5;  // Already consumed
    $_SESSION['has_business'] = false;
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Dynamic title matching your flat file structure
$viewTitle = "Dashboard Overview";
if ($currentPage == 'track_book.php') $viewTitle = "Active Reservation Hub";
if ($currentPage == 'usage_history.php') $viewTitle = "Usage Reports Log";
if ($currentPage == 'profile_page.php') $viewTitle = "Portal Profile Settings";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map & Pump — <?php echo $viewTitle; ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy-950: #070b14; --navy-900: #0b1220; --navy-800: #101a30; --navy-700: #16213f;
            --blue-700: #1d4ed8; --blue-500: #2f6feb; --sky-400:  #38bdf8; --mint-400: #34d399; 
            --amber-400: #fbbf24; --red-400:  #f87171; --slate-50:  #f8fafc; --slate-300: #c9d2e3; 
            --slate-400: #8b96ad;
        }
        body { background-color: var(--navy-950); color: var(--slate-50); font-family: 'Inter', system-ui, -apple-system, sans-serif; min-height: 100vh; overflow-x: hidden; }
        h1, h2, h3, h4, h5, .disp-brand, .token-no { font-family: 'Space Grotesk', 'Noto Sans Myanmar', sans-serif; font-weight: 700; letter-spacing: -0.02em; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--navy-950); }
        ::-webkit-scrollbar-thumb { background: var(--navy-800); border-radius: 99px; border: 2px solid var(--navy-950); }
        ::-webkit-scrollbar-thumb:hover { background: var(--sky-400); }

        .sidebar-nav { background-color: var(--navy-900); border-right: 1px solid rgba(255, 255, 255, 0.05); width: 280px; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; justify-content: space-between; z-index: 100; }
        .nav-item-link { display: flex; align-items: center; gap: 12px; padding: 14px 20px; color: var(--slate-300); text-decoration: none; border-radius: 12px; font-weight: 500; transition: all 0.2s ease; margin: 4px 16px; }
        .nav-item-link:hover, .nav-item-link.active { background-color: var(--navy-800); color: var(--sky-400); border-left: 3px solid var(--sky-400); }
        
        .dashboard-header { background: rgba(7, 11, 20, 0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255, 255, 255, 0.05); height: 75px; position: sticky; top: 0; z-index: 90; margin-left: 280px; }
        .main-frame { margin-left: 280px; padding: 40px; min-height: calc(100vh - 75px); }
        
        .dashboard-panel-card { background: linear-gradient(145deg, var(--navy-900), var(--navy-800)); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 20px; padding: 28px; transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s ease; }
        .dashboard-panel-card:hover { transform: translateY(-4px); border-color: rgba(56, 189, 248, 0.25); box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4); }
        
        .station-selector-card { background-color: var(--navy-800); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 20px; cursor: pointer; transition: all 0.2s ease; }
        .station-selector-card:hover, .station-selector-card.selected { border-color: var(--sky-400); background-color: var(--navy-700); box-shadow: 0 0 15px rgba(56, 189, 248, 0.15); }
        
        .status-pill { padding: 6px 14px; border-radius: 99px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.03em; }
        .status-pill.active { background: rgba(52, 211, 153, 0.1); color: var(--mint-400); border: 1px solid rgba(52, 211, 153, 0.2); }
        .status-pill.pending { background: rgba(251, 191, 36, 0.1); color: var(--amber-400); border: 1px solid rgba(251, 191, 36, 0.2); }
        .status-pill.danger { background: rgba(248, 113, 113, 0.1); color: var(--red-400); border: 1px solid rgba(248, 113, 113, 0.2); }
        
        .step-circle { width: 38px; height: 38px; border-radius: 50%; border: 2px solid var(--navy-700); background-color: var(--navy-900); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: var(--slate-400); transition: all 0.3s ease; }
        .step-circle.active { border-color: var(--sky-400); color: var(--sky-400); background-color: var(--navy-800); box-shadow: 0 0 10px rgba(56, 189, 248, 0.3); }
        .step-circle.completed { border-color: var(--mint-400); color: var(--mint-400); background-color: rgba(52, 211, 153, 0.15); }
        
        .custom-form-field { background-color: var(--navy-950) !important; border: 1px solid rgba(255, 255, 255, 0.08) !important; color: var(--slate-50) !important; border-radius: 12px !important; padding: 12px 16px !important; transition: border-color 0.2s ease; }
        .custom-form-field:focus { border-color: var(--sky-400) !important; box-shadow: none !important; }
        
        .fuel-tag { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; display: inline-block; }
        .fuel-92 { background-color: #2e1065; color: #d8b4fe; }
        .fuel-95 { background-color: #064e3b; color: #6ee7b7; }
        .fuel-diesel { background-color: #7c2d12; color: #ffedd5; }
        
        @media (max-width: 991px) { .sidebar-nav { display: none; } .dashboard-header, .main-frame { margin-left: 0; } }
    </style>
</head>
<body>

    <aside class="sidebar-nav">
        <div>
            <div class="p-4 d-flex align-items-center gap-3 border-bottom border-secondary border-opacity-10">
                <div class="bg-primary bg-opacity-10 p-2 rounded-3 text-sky-400 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                    <img src="/images/Logo.png" alt="Map & Pump Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                </div>
                <div>
                    <span class="disp-brand fs-5 text-white">MAP & PUMP</span>
                    <small class="d-block text-secondary text-uppercase tracking-wider" style="font-size:9px;">Driver Portal</small>
                </div>
            </div>

            <div class="mt-4">
                <a href="dashboard.php" class="nav-item-link <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>">
                    <i class="bi bi-grid-1x2-fill"></i> Dashboard
                </a>
                <a href="track_book.php" class="nav-item-link <?php echo ($currentPage == 'track_book.php') ? 'active' : ''; ?>">
                    <i class="bi bi-geo-alt-fill"></i> Track & Book
                </a>
                <a href="usage_history.php" class="nav-item-link <?php echo ($currentPage == 'usage_history.php') ? 'active' : ''; ?>">
                    <i class="bi bi-clipboard2-pulse-fill"></i> Usage History
                </a>
                <a href="profile_page.php" class="nav-item-link <?php echo ($currentPage == 'profile_page.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-fill-gear"></i> Portal Profile
                </a>
            </div>
        </div>

        <div class="p-3 border-t border-secondary border-opacity-10 bg-black bg-opacity-20">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rounded-circle bg-info bg-opacity-10 text-info d-flex align-items-center justify-content-center" style="width:40px; height:40px; font-weight:700;">
                    <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                </div>
                <div>
                    <span class="fw-bold d-block text-white" style="font-size: 13px;"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <small class="text-secondary" style="font-size: 11px;"><?php echo htmlspecialchars($_SESSION['user_id']); ?></small>
                </div>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm w-100 py-2 border-opacity-10" style="border-radius:10px;">
                <i class="bi bi-box-arrow-right me-1"></i> Sign Out Session
            </a>
        </div>
    </aside>

    <header class="dashboard-header d-flex align-items-center justify-content-between px-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-dark d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h2 id="view-title" class="mb-0 fs-4 text-white"><?php echo $viewTitle; ?></h2>
        </div>

        <div class="d-flex align-items-center gap-4">
            <div class="position-relative cursor-pointer" id="notif-hub" onclick="toggleAlertBox()" style="cursor: pointer;">
                <i class="bi bi-bell-fill fs-5 text-secondary hover:text-white"></i>
                <span id="notif-pulse-dot" class="position-absolute top-0 start-100 translate-middle p-1.5 bg-danger border border-light rounded-circle d-none"></span>
                
                <div id="notif-alert-panel" class="position-absolute end-0 mt-3 bg-dark border border-secondary border-opacity-20 rounded-4 shadow-lg py-3 d-none" style="width: 320px; z-index: 1000;">
                    <div class="px-3 pb-2 border-bottom border-secondary border-opacity-10 font-bold text-white fs-6">Alert Logs</div>
                    <div id="alert-logs-list" class="max-h-60 overflow-y-auto">
                        <p class="px-3 py-4 text-center text-secondary mb-0" style="font-size:12px;">No notification dispatches registered.</p>
                    </div>
                </div>
            </div>
            <span class="bg-secondary bg-opacity-25" style="width:1px; height:24px;"></span>
            <div class="d-none d-sm-flex align-items-center gap-2">
                <span class="status-pill active" style="font-size:10px;">Online Node</span>
            </div>
        </div>
    </header>

    <main class="main-frame">