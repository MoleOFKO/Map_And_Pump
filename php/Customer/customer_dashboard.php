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

// Handle simulated actions (like upgrading to Business or sending Feedback)
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upgrade_business') {
        $_SESSION['has_business'] = true;
        $message = "Your profile has been successfully linked and transitioned to a Business Account!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map & Pump — Customer Workspace</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --navy-950: #070b14; 
            --navy-900: #0b1220; 
            --navy-800: #101a30; 
            --navy-700: #16213f;
            --blue-700: #1d4ed8; 
            --blue-500: #2f6feb; 
            --sky-400:  #38bdf8; 
            --mint-400: #34d399; 
            --amber-400: #fbbf24; 
            --red-400:  #f87171; 
            --slate-50:  #f8fafc; 
            --slate-300: #c9d2e3; 
            --slate-400: #8b96ad;
        }

        body {
            background-color: var(--navy-950);
            color: var(--slate-50);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, .disp-brand, .token-no {
            font-family: 'Space Grotesk', 'Noto Sans Myanmar', sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        /* Webkit Scrollbars Custom Theme Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--navy-950);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--navy-800);
            border-radius: 99px;
            border: 2px solid var(--navy-950);
        }
        ::-webkit-scrollbar-thumb:hover {
            background: var(--sky-400);
        }

        /* Sidebar Navigation styling */
        .sidebar-nav {
            background-color: var(--navy-900);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            width: 280px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            z-index: 100;
        }

        .nav-item-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--slate-300);
            text-decoration: none;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.2s ease;
            margin: 4px 16px;
        }

        .nav-item-link:hover, .nav-item-link.active {
            background-color: var(--navy-800);
            color: var(--sky-400);
            border-left: 3px solid var(--sky-400);
        }

        /* Header backdrop blur bar */
        .dashboard-header {
            background: rgba(7, 11, 20, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            height: 75px;
            position: sticky;
            top: 0;
            z-index: 90;
            margin-left: 280px;
        }

        /* Main Body Frame */
        .main-frame {
            margin-left: 280px;
            padding: 40px;
            min-height: calc(100vh - 75px);
        }

        /* Cards and Elements Styling (Inspired by Manage Oil / Manage Staff) */
        .dashboard-panel-card {
            background: linear-gradient(145deg, var(--navy-900), var(--navy-800));
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 28px;
            transition: transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.3s ease;
        }

        .dashboard-panel-card:hover {
            transform: translateY(-4px);
            border-color: rgba(56, 189, 248, 0.25);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
        }

        .station-selector-card {
            background-color: var(--navy-800);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .station-selector-card:hover, .station-selector-card.selected {
            border-color: var(--sky-400);
            background-color: var(--navy-700);
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.15);
        }

        /* Status Glowing Pill Badges */
        .status-pill {
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-pill.active {
            background: rgba(52, 211, 153, 0.1);
            color: var(--mint-400);
            border: 1px solid rgba(52, 211, 153, 0.2);
        }

        .status-pill.pending {
            background: rgba(251, 191, 36, 0.1);
            color: var(--amber-400);
            border: 1px solid rgba(251, 191, 36, 0.2);
        }

        .status-pill.danger {
            background: rgba(248, 113, 113, 0.1);
            color: var(--red-400);
            border: 1px solid rgba(248, 113, 113, 0.2);
        }

        /* Step Progress indicators */
        .step-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 2px solid var(--navy-700);
            background-color: var(--navy-900);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: var(--slate-400);
            transition: all 0.3s ease;
        }

        .step-circle.active {
            border-color: var(--sky-400);
            color: var(--sky-400);
            background-color: var(--navy-800);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
        }

        .step-circle.completed {
            border-color: var(--mint-400);
            color: var(--mint-400);
            background-color: rgba(52, 211, 153, 0.15);
        }

        /* Customized Inputs matching PetroQ aesthetics */
        .custom-form-field {
            background-color: var(--navy-950) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            color: var(--slate-50) !important;
            border-radius: 12px !important;
            padding: 12px 16px !important;
            transition: border-color 0.2s ease;
        }

        .custom-form-field:focus {
            border-color: var(--sky-400) !important;
            box-shadow: none !important;
        }

        .fuel-tag {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-block;
        }

        .fuel-92 { background-color: #2e1065; color: #d8b4fe; }
        .fuel-95 { background-color: #064e3b; color: #6ee7b7; }
        .fuel-diesel { background-color: #7c2d12; color: #ffedd5; }

        @media (max-width: 991px) {
            .sidebar-nav { display: none; }
            .dashboard-header, .main-frame { margin-left: 0; }
        }
    </style>
</head>
<body>

    <aside class="sidebar-nav">
        <div>
            <div class="p-4 d-flex align-items-center gap-3 border-bottom border-secondary border-opacity-10">
                <div class="bg-primary bg-opacity-10 p-2 rounded-3 text-sky-400">
                    <i class="fa-solid fa-gas-pump fs-4"></i>
                </div>
                <div>
                    <span class="disp-brand fs-5 text-white">MAP & PUMP</span>
                    <small class="d-block text-secondary text-uppercase tracking-wider" style="font-size:9px;">User Portal</small>
                </div>
            </div>

            <div class="mt-4">
                <a href="#dashboard" onclick="switchTab('dashboard')" class="nav-item-link active" id="btn-tab-dashboard">
                    <i class="bi bi-grid-1x2-fill"></i> Dashboard
                </a>
                <a href="#booking" onclick="switchTab('booking')" class="nav-item-link" id="btn-tab-booking">
                    <i class="bi bi-geo-alt-fill"></i> Track & Book
                </a>
                <a href="#reports" onclick="switchTab('reports')" class="nav-item-link" id="btn-tab-reports">
                    <i class="bi bi-clipboard2-pulse-fill"></i> Usage History
                </a>
                <a href="#profile" onclick="switchTab('profile')" class="nav-item-link" id="btn-tab-profile">
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
            <h2 id="view-title" class="mb-0 fs-4 text-white">Dashboard Overview</h2>
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
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success rounded-4 p-4 mb-4 d-flex align-items-center gap-3" role="alert">
                <i class="bi bi-check-circle-fill fs-4"></i>
                <span class="fw-semibold" style="font-size:14px;"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <section id="tab-dashboard" class="tab-pane-view">
            <div class="row g-4 mb-5">
                <div class="col-xl-4 col-md-6">
                    <div class="dashboard-panel-card">
                        <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Fuel Monthly Quota</p>
                        <h3 class="text-white fs-1 mb-1"><?php echo $_SESSION['oil_limit']; ?> <span class="fs-5 text-secondary">Liters</span></h3>
                        <p class="text-secondary mb-0" style="font-size:12px;">Total legal quota allowance registered per user account</p>
                    </div>
                </div>

                <div class="col-xl-4 col-md-6">
                    <div class="dashboard-panel-card">
                        <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">Available Allocation</p>
                        <h3 class="text-sky-400 fs-1 mb-1"><?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?> <span class="fs-5 text-secondary">Liters</span></h3>
                        <div class="progress bg-dark bg-opacity-50 rounded-pill mt-3" style="height: 6px;">
                            <div class="progress-bar bg-info" style="width: <?php echo (($_SESSION['oil_limit'] - $_SESSION['oil_used'])/$_SESSION['oil_limit'])*100; ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4 col-md-12">
                    <div class="dashboard-panel-card">
                        <p class="text-secondary text-uppercase tracking-wider mb-2" style="font-size:11px; font-weight:600;">System Classification</p>
                        <h3 class="text-white fs-2 mb-1"><?php echo $_SESSION['has_business'] ? 'Enterprise Owner' : 'Personal Individual'; ?></h3>
                        <p class="text-secondary mb-0" style="font-size:12px;"><?php echo $_SESSION['has_business'] ? 'Connected to station group profiles' : 'Eligible to convert profile classification'; ?></p>
                    </div>
                </div>
            </div>

            <div class="p-5 rounded-5 shadow-lg border border-secondary border-opacity-10 position-relative overflow-hidden mb-5" style="background: linear-gradient(135deg, #101a30 0%, #070b14 100%);">
                <div class="row align-items-center position-relative" style="z-index:10;">
                    <div class="col-lg-8">
                        <span class="status-pill active mb-3"><span class="spinner-grow spinner-grow-sm me-1" role="status"></span> Live Queue Verification active</span>
                        <h2 class="text-white mb-2 fs-1">Track Stations & Book Tokens</h2>
                        <p class="text-secondary mb-4 max-width-md" style="font-size:14px;">Verify vehicle license registries, compute remaining allocations, generate token barcodes, and queue dynamically at localized stations.</p>
                        <button onclick="switchTab('booking')" class="btn btn-info px-4 py-3 fw-bold rounded-4 shadow" style="font-size:13px; font-family:'Space Grotesk';">
                            Begin Reservation Hub <i class="fa-solid fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                <div class="position-absolute" style="width: 300px; height: 300px; background: radial-gradient(circle, rgba(56,189,248,0.1) 0%, rgba(0,0,0,0) 70%); right: -50px; bottom: -50px; pointer-events: none;"></div>
            </div>
        </section>

        <section id="tab-booking" class="tab-pane-view d-none">
            <div class="dashboard-panel-card">
                <div class="d-flex align-items-center justify-content-between pb-4 mb-4 border-bottom border-secondary border-opacity-10">
                    <div>
                        <h3 class="text-white mb-1">Interactive Booking Wizard</h3>
                        <p class="text-secondary mb-0" style="font-size:13px;">Complete the process below to authorize your localized distribution ticket.</p>
                    </div>
                    <span id="label-step-badge" class="px-3 py-1 bg-dark text-sky-400 font-bold rounded-pill text-xs border border-secondary border-opacity-20">Step 1 of 5</span>
                </div>

                <div class="d-flex justify-content-between align-items-center max-width-md mx-auto mb-5 px-3">
                    <div class="d-flex flex-col align-items-center text-center" style="flex:1;">
                        <span id="step-node-1" class="step-circle active">1</span>
                        <span class="d-none d-md-block text-secondary mt-2" style="font-size:11px;">Select Station</span>
                    </div>
                    <div style="height:2px; background:rgba(255,255,255,0.05); flex:1;" id="step-node-line-1"></div>
                    <div class="d-flex flex-col align-items-center text-center" style="flex:1;">
                        <span id="step-node-2" class="step-circle">2</span>
                        <span class="d-none d-md-block text-secondary mt-2" style="font-size:11px;">License Audit</span>
                    </div>
                    <div style="height:2px; background:rgba(255,255,255,0.05); flex:1;" id="step-node-line-2"></div>
                    <div class="d-flex flex-col align-items-center text-center" style="flex:1;">
                        <span id="step-node-3" class="step-circle">3</span>
                        <span class="d-none d-md-block text-secondary mt-2" style="font-size:11px;">Quota Check</span>
                    </div>
                    <div style="height:2px; background:rgba(255,255,255,0.05); flex:1;" id="step-node-line-3"></div>
                    <div class="d-flex flex-col align-items-center text-center" style="flex:1;">
                        <span id="step-node-4" class="step-circle">4</span>
                        <span class="d-none d-md-block text-secondary mt-2" style="font-size:11px;">Token Issued</span>
                    </div>
                </div>

                <div id="pane-step-1" class="step-pane-view">
                    <h4 class="text-white mb-3">Choose localized Station Node</h4>
                    <p class="text-secondary mb-4" style="font-size:14px;">Ensure you choose a node that lists the matching octane profile as currently active.</p>
                    
                    <div class="row g-4">
                        <div class="col-md-6" onclick="triggerStationSelection('Yangon Terminal Center', 'Octane 95 & Diesel Available')">
                            <div class="station-selector-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="text-white mb-0">Yangon Terminal Center</h5>
                                    <span class="status-pill active" style="font-size:9px;">Active Stock</span>
                                </div>
                                <p class="text-secondary mb-3" style="font-size:13px;"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Pyay Road, Mayangone Township</p>
                                <div class="d-flex gap-2">
                                    <span class="fuel-tag fuel-95">Octane 95</span>
                                    <span class="fuel-tag fuel-diesel">Diesel</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6" onclick="triggerStationSelection('Insein Branch Station 04', 'Octane 92 Active')">
                            <div class="station-selector-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h5 class="text-white mb-0">Insein Branch Station 04</h5>
                                    <span class="status-pill active" style="font-size:9px;">Active Stock</span>
                                </div>
                                <p class="text-secondary mb-3" style="font-size:13px;"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Baho Road, Insein Township</p>
                                <div class="d-flex gap-2">
                                    <span class="fuel-tag fuel-92">Octane 92</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="pane-step-2" class="step-pane-view d-none">
                    <h4 class="text-white mb-2">Driver Identity & License Audit</h4>
                    <p class="text-secondary mb-4" style="font-size:14px;">The central gateway registers and validates active status parameters for matching vehicles.</p>
                    
                    <div class="p-4 bg-black bg-opacity-25 rounded-4 border border-secondary border-opacity-10 mb-4 max-width-md">
                        <div class="d-flex align-items-start gap-3">
                            <div class="text-info bg-info bg-opacity-10 p-2.5 rounded-3">
                                <i class="bi bi-shield-lock-fill fs-4"></i>
                            </div>
                            <div>
                                <h5 class="text-white mb-1">State Verification Registry Active</h5>
                                <p class="text-secondary mb-0" style="font-size:12px;">No manual typing required. Authentication parameters are verified against session credentials automatically.</p>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button onclick="simulateLicenseFailure()" class="btn btn-outline-danger px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Simulate Blocked License</button>
                        <button onclick="advanceWizardStep(3, 'Step 3 of 5')" class="btn btn-info px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Authorize Audit</button>
                    </div>
                </div>

                <div id="pane-step-3" class="step-pane-view d-none">
                    <h4 class="text-white mb-2">Volume Quote Declaration</h4>
                    <p class="text-secondary mb-4" style="font-size:14px;">Declare requested consumption allocations. Volumes must remain below your available limit.</p>

                    <div class="row g-4 max-width-md mb-4">
                        <div class="col-sm-6">
                            <label class="form-label text-secondary" style="font-size:12px; font-weight:600;">FUEL PRODUCT PROFILE</label>
                            <select id="user-fuel-select" class="form-select custom-form-field">
                                <option value="Octane 92">Octane 92 Product Profile</option>
                                <option value="Octane 95">Octane 95 Product Profile</option>
                                <option value="Premium Diesel">Premium Diesel Profile</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-secondary" style="font-size:12px; font-weight:600;">ALLOCATION QUANTITY (LITERS)</label>
                            <input type="number" id="user-volume-input" value="20" class="form-control custom-form-field">
                            <p class="text-secondary mt-1 mb-0" style="font-size:11px;">Current allowance limit balance: <strong class="text-sky-400"><?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?> L</strong></p>
                        </div>
                    </div>

                    <button onclick="verifyQuotaLimitSubmit()" class="btn btn-info px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Verify & Register Reservation</button>
                </div>

                <div id="pane-step-4" class="step-pane-view d-none">
                    <div class="text-center p-4 max-width-md mx-auto">
                        <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                            <i class="bi bi-qr-code-scan fs-3"></i>
                        </div>
                        <h4 class="text-white mb-1">Distribution Token Validated</h4>
                        <p class="text-secondary mb-4" style="font-size:13px;">Scan this verified booking pass at your selected location's scanning terminals.</p>

                        <div class="p-4 bg-black bg-opacity-30 border border-secondary border-opacity-10 rounded-4 max-width-xs mx-auto mb-4">
                            <div class="token-no text-info bg-dark bg-opacity-50 p-4 rounded-3 border border-secondary border-opacity-10 mb-2 select-all" style="font-size: 24px; letter-spacing: 0.1em; font-weight: 700;">
                                MP-883-920X
                            </div>
                            <span class="status-pill active" style="font-size:9px;">Active Queue Token</span>
                        </div>

                        <div class="d-flex gap-3 justify-content-center">
                            <button onclick="simulateCancelBooking()" class="btn btn-outline-danger px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Cancel Reservation</button>
                            <button onclick="advanceWizardStep(5, 'Step 5 of 5')" class="btn btn-info px-4 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Simulate Pump Fill</button>
                        </div>
                    </div>
                </div>

                <div id="pane-step-5" class="step-pane-view d-none">
                    <div class="max-width-md mx-auto">
                        <div class="text-center mb-4">
                            <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width:60px; height:60px;">
                                <i class="bi bi-hand-thumbs-up-fill fs-3"></i>
                            </div>
                            <h4 class="text-white mb-1">Fueling Cycle Process Finished</h4>
                            <p class="text-secondary mb-0" style="font-size:13px;">Please rate your transaction and service convenience below.</p>
                        </div>

                        <div class="p-4 bg-black bg-opacity-20 border border-secondary border-opacity-10 rounded-4 mb-4 space-y-4">
                            <h5 class="text-white fs-6 mb-3">Service Satisfaction Score</h5>
                            <div class="d-flex gap-2 mb-4">
                                <button onclick="setFeedbackRating(1)" class="star-node btn p-1 text-secondary"><i class="bi bi-star-fill fs-3"></i></button>
                                <button onclick="setFeedbackRating(2)" class="star-node btn p-1 text-secondary"><i class="bi bi-star-fill fs-3"></i></button>
                                <button onclick="setFeedbackRating(3)" class="star-node btn p-1 text-secondary"><i class="bi bi-star-fill fs-3"></i></button>
                                <button onclick="setFeedbackRating(4)" class="star-node btn p-1 text-secondary"><i class="bi bi-star-fill fs-3"></i></button>
                                <button onclick="setFeedbackRating(5)" class="star-node btn p-1 text-secondary"><i class="bi bi-star-fill fs-3"></i></button>
                            </div>

                            <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">SERVICE COMMENTARY</label>
                            <textarea id="feedback-desc-box" class="form-control custom-form-field" rows="3" placeholder="Tell us about the station facilities, queue time, or staff behavior..."></textarea>
                        </div>

                        <button onclick="sendFeedbackSubmit()" class="btn btn-info w-100 py-3 fw-bold rounded-4" style="font-size:13px; font-family:'Space Grotesk';">Submit Feedback & Reset Hub</button>
                    </div>
                </div>

            </div>
        </section>

        <section id="tab-reports" class="tab-pane-view d-none">
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
        </section>

        <section id="tab-profile" class="tab-pane-view d-none">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="dashboard-panel-card">
                        <h3 class="text-white mb-1">Profile Configurations</h3>
                        <p class="text-secondary mb-4" style="font-size:13px;">Manage driver profile metadata registered with state license records.</p>

                        <form class="space-y-4" onsubmit="event.preventDefault(); alert('Profile updates saved successfully.');">
                            <div class="mb-3">
                                <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">DISPLAY FULL NAME</label>
                                <input type="text" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" class="form-control custom-form-field">
                            </div>
                            <div class="mb-4">
                                <label class="form-label text-secondary" style="font-size:11px; font-weight:600;">REGISTERED SYSTEM EMAIL</label>
                                <input type="email" value="<?php echo htmlspecialchars($_SESSION['user_email']); ?>" class="form-control custom-form-field" readonly>
                            </div>
                            <button type="submit" class="btn btn-info px-4 py-2.5 fw-bold rounded-4" style="font-size:12px; font-family:'Space Grotesk';">Save Changes</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="dashboard-panel-card">
                        <div class="d-flex align-items-start gap-3 mb-4">
                            <div class="bg-primary bg-opacity-10 p-2.5 rounded-3 text-sky-400">
                                <i class="bi bi-buildings fs-4"></i>
                            </div>
                            <div>
                                <h3 class="text-white mb-1">Enterprise Conversion</h3>
                                <p class="text-secondary mb-0" style="font-size:13px;">Corporate entities and station branches can request profile transitions below.</p>
                            </div>
                        </div>

                        <?php if ($_SESSION['has_business']): ?>
                            <div class="p-3 bg-success bg-opacity-10 text-success rounded-4 border border-success border-opacity-10 text-xs font-semibold">
                                <i class="bi bi-shield-fill-check me-1"></i> This account is successfully connected to a registered Enterprise Group profile.
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="upgrade_business">
                                <button type="submit" class="btn btn-primary w-100 py-3 fw-bold rounded-4" style="font-size:12px; font-family:'Space Grotesk';">
                                    Transition profile to Station Owner / Partner
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <div class="offcanvas offcanvas-start bg-dark text-white" tabindex="-1" id="mobileSidebar" style="width:280px; background-color: var(--navy-900) !important;">
        <div class="offcanvas-header border-bottom border-secondary border-opacity-10">
            <h5 class="offcanvas-title disp-brand text-sky-400">MAP & PUMP</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column justify-content-between p-0">
            <div class="mt-4">
                <a href="#dashboard" onclick="switchTab('dashboard')" class="nav-item-link active" data-bs-dismiss="offcanvas">
                    <i class="bi bi-grid-1x2-fill"></i> Dashboard
                </a>
                <a href="#booking" onclick="switchTab('booking')" class="nav-item-link" data-bs-dismiss="offcanvas">
                    <i class="bi bi-geo-alt-fill"></i> Track & Book
                </a>
                <a href="#reports" onclick="switchTab('reports')" class="nav-item-link" data-bs-dismiss="offcanvas">
                    <i class="bi bi-clipboard2-pulse-fill"></i> Usage History
                </a>
                <a href="#profile" onclick="switchTab('profile')" class="nav-item-link" data-bs-dismiss="offcanvas">
                    <i class="bi bi-person-fill-gear"></i> Portal Profile
                </a>
            </div>
            <div class="p-4 bg-black bg-opacity-20">
                <a href="logout.php" class="btn btn-danger w-100 py-2">Sign Out</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Global variables maintaining active state
        let currentStationName = "";
        let feedbackScore = 0;
        const availableOilQuota = <?php echo ($_SESSION['oil_limit'] - $_SESSION['oil_used']); ?>;

        // Dynamic multi-tab navigation switch routing
        function switchTab(tabName) {
            document.querySelectorAll('.tab-pane-view').forEach(tab => tab.classList.add('d-none'));
            document.getElementById('tab-' + tabName).classList.remove('d-none');

            document.querySelectorAll('.nav-item-link').forEach(btn => {
                btn.classList.remove('active');
            });

            // Find matching links and toggle highlighting states
            const activeNavLinks = document.querySelectorAll(`[href="#${tabName}"]`);
            activeNavLinks.forEach(link => link.classList.add('active'));

            // Format topbar title displays dynamically
            const titlesMap = {
                'dashboard': 'Dashboard Overview',
                'booking': 'Active Reservation Hub',
                'reports': 'Usage Reports Log',
                'profile': 'Portal Profile Settings'
            };
            document.getElementById('view-title').innerText = titlesMap[tabName] || 'Customer Portal';
        }

        // Step 1: Handle localized node choices
        function triggerStationSelection(stationName, description) {
            currentStationName = stationName;
            
            // Advance view to Step 2
            advanceWizardStep(2, "Step 2 of 5");
        }

        // Step 2: Handle simulated vehicle check failures
        function simulateLicenseFailure() {
            alert("Security failure registered. The vehicle registry check returned: Blocked or expired status license.");
            abortBookingProcess();
        }

        // Step 3: Check remaining limits before confirming bookings
        function verifyQuotaLimitSubmit() {
            const requestedValInput = parseFloat(document.getElementById('user-volume-input').value);
            
            if (isNaN(requestedValInput) || requestedValInput <= 0) {
                alert("Provide a valid fuel allocation volume.");
                return;
            }

            if (requestedValInput > availableOilQuota) {
                alert(`Authorization rejected. Your requested reservation of ${requestedValInput}L exceeds your remaining allowance limit: ${availableOilQuota}L.`);
                abortBookingProcess();
                return;
            }

            advanceWizardStep(4, "Step 4 of 5");
        }

        // Step 4: Handle active user cancellation processes
        function simulateCancelBooking() {
            alert("Processing cancellation logs. Dispatched network cancel event to stations.");
            
            // Show new alert notifications panel state
            document.getElementById('notif-pulse-dot').classList.remove('d-none');
            const alertContainer = document.getElementById('alert-logs-list');
            alertContainer.innerHTML = `
                <div class="px-3 py-2 border-bottom border-secondary border-opacity-5 hover:bg-white hover:bg-opacity-5 transition-all" style="font-size:12px;">
                    <p class="font-bold text-danger mb-0">Token Cancelled</p>
                    <p class="text-secondary mb-0 mt-0.5">Reservation for ${currentStationName} was cancelled.</p>
                </div>
            `;
            
            abortBookingProcess();
        }

        // Step 5: Handle dynamic user feedback submission
        function setFeedbackRating(scoreValue) {
            feedbackScore = scoreValue;
            const starElements = document.querySelectorAll('.star-node');
            
            starElements.forEach((star, idx) => {
                if (idx < scoreValue) {
                    star.classList.remove('text-secondary');
                    star.classList.add('text-warning');
                } else {
                    star.classList.remove('text-warning');
                    star.classList.add('text-secondary');
                }
            });
        }

        function sendFeedbackSubmit() {
            const feedbackTextVal = document.getElementById('feedback-desc-box').value;
            alert(`Thank you for helping us improve!\nRating Score: ${feedbackScore} / 5 Stars\nMessage: ${feedbackTextVal}`);
            abortBookingProcess();
        }

        // Core Helper routines
        function advanceWizardStep(targetStepNumber, labelStepText) {
            document.querySelectorAll('.step-pane-view').forEach(pane => pane.classList.add('d-none'));
            document.getElementById('pane-step-' + targetStepNumber).classList.remove('d-none');
            document.getElementById('label-step-badge').innerText = labelStepText;

            // Handle visual adjustments to header wizard nodes
            for (let i = 1; i <= 4; i++) {
                const nodeElement = document.getElementById('step-node-' + i);
                const lineElement = document.getElementById('step-node-line-' + i);
                
                if (nodeElement) {
                    if (i < targetStepNumber) {
                        nodeElement.className = "step-circle completed";
                        nodeElement.innerHTML = '<i class="bi bi-check"></i>';
                    } else if (i === targetStepNumber) {
                        nodeElement.className = "step-circle active";
                        nodeElement.innerHTML = i;
                    } else {
                        nodeElement.className = "step-circle";
                        nodeElement.innerHTML = i;
                    }
                }
                
                if (lineElement) {
                    if (i < targetStepNumber) {
                        lineElement.style.background = "var(--mint-400)";
                    } else {
                        lineElement.style.background = "rgba(255,255,255,0.05)";
                    }
                }
            }
        }

        function abortBookingProcess() {
            advanceWizardStep(1, "Step 1 of 5");
            document.getElementById('user-volume-input').value = "20";
            document.getElementById('feedback-desc-box').value = "";
            setFeedbackRating(0);
            switchTab('dashboard');
        }

        function toggleAlertBox() {
            const panel = document.getElementById('notif-alert-panel');
            panel.classList.toggle('d-none');
            // Hide notification badge indicator upon opening
            document.getElementById('notif-pulse-dot').classList.add('d-none');
        }
    </script>
</body>
</html>