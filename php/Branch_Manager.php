<!DOCTYPE html>
<html lang="my" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>MAP & PUMP Branch Manager Dashboard</title>
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+Myanmar:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- Bootstrap 5.3.3 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <style>
    :root {
      --navy-950: #070b14; 
      --navy-900: #0b1220; 
      --navy-800: #101a30; 
      --navy-700: #16213f;
      --blue-700: #1d4ed8; 
      --sky-400: #38bdf8;
      --amber-400: #fbbf24; 
      --amber-300: #fcd34d; 
      --mint-400: #34d399;
      --red-400: #f87171;
      --purple-400: #c084fc;
      --slate-300: #c9d2e3; 
      --slate-400: #8b96ad;
    }

    body {
      background-color: var(--navy-950);
      color: #f8fafc;
      font-family: 'Noto Sans Myanmar', system-ui, -apple-system, sans-serif;
    }

    .font-space {
      font-family: 'Space Grotesk', sans-serif;
    }

    /* Sidebar Custom Styling */
    .sidebar {
      width: 252px;
      min-height: 100vh;
      background-color: var(--navy-900);
      border-right: 1px solid rgba(255, 255, 255, 0.06);
    }

    /* Logo Container with Zoom & Blend Fix */
    .brand-logo-container {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      background-color: transparent;
    }

    .brand-logo-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      /* Scales image past extra black padding & blends background */
      transform: scale(1.85);
      mix-blend-mode: screen;
    }

    .brand-title {
      font-weight: 700;
      font-size: 1.1rem;
      letter-spacing: 0.3px;
      line-height: 1.2;
      color: #ffffff;
      white-space: nowrap;
    }

    .side-branch {
      background-color: var(--navy-800);
      border: 1px solid rgba(255, 255, 255, 0.06);
      transition: border-color 0.3s ease;
    }
    .side-branch:hover {
      border-color: rgba(56, 189, 248, 0.3);
    }

    .nav-link-custom {
      color: var(--slate-300);
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 500;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .nav-link-custom:hover {
      background-color: rgba(255, 255, 255, 0.06);
      color: #ffffff;
      transform: translateX(4px);
    }

    .nav-link-custom.active {
      background: linear-gradient(135deg, rgba(56,189,248,0.16), rgba(29,78,216,0.12));
      color: #ffffff;
      box-shadow: inset 0 0 0 1px rgba(56,189,248,0.32);
    }

    /* Vibrant Icon Badge Containers in Sidebar */
    .side-ic-box {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      flex-shrink: 0;
    }

    .nav-link-custom:hover .side-ic-box {
      transform: scale(1.15) rotate(-3deg);
    }

    /* Colorful Icon Badge Themes */
    .side-ic-home {
      background: rgba(56, 189, 248, 0.15);
      color: var(--sky-400);
      border: 1px solid rgba(56, 189, 248, 0.3);
    }

    .side-ic-staff {
      background: rgba(52, 211, 153, 0.15);
      color: var(--mint-400);
      border: 1px solid rgba(52, 211, 153, 0.3);
    }

    .side-ic-stock {
      background: rgba(251, 191, 36, 0.15);
      color: var(--amber-400);
      border: 1px solid rgba(251, 191, 36, 0.3);
    }

    .side-ic-booking {
      background: rgba(192, 132, 252, 0.15);
      color: var(--purple-400);
      border: 1px solid rgba(192, 132, 252, 0.3);
    }

    .side-ic-logout {
      background: rgba(248, 113, 113, 0.15);
      color: var(--red-400);
      border: 1px solid rgba(248, 113, 113, 0.3);
    }

    /* Glow Effect when Nav Link is Active */
    .nav-link-custom.active .side-ic-home {
      box-shadow: 0 0 10px rgba(56, 189, 248, 0.5);
      background: var(--sky-400);
      color: #070b14;
    }

    /* Topbar Styling */
    .topbar {
      background: rgba(7, 11, 20, 0.72);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .bell-btn {
      width: 38px;
      height: 38px;
      background-color: var(--navy-800);
      border: 1px solid rgba(255, 255, 255, 0.08);
      transition: transform 0.2s ease, background-color 0.2s ease;
    }
    .bell-btn:hover {
      transform: scale(1.08);
      background-color: var(--navy-700);
    }

    .bell-ping {
      position: absolute;
      top: 7px;
      right: 8px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background-color: var(--amber-400);
      box-shadow: 0 0 0 2px var(--navy-800);
      animation: pulse-glow 1.8s infinite;
    }

    @keyframes pulse-glow {
      0% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0.7); }
      70% { box-shadow: 0 0 0 8px rgba(251, 191, 36, 0); }
      100% { box-shadow: 0 0 0 0 rgba(251, 191, 36, 0); }
    }

    .avatar-circle {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--sky-400), var(--blue-700));
      box-shadow: 0 2px 8px rgba(29, 78, 216, 0.4);
    }

    /* Dashboard Cards & Panels Hover Effects */
    .kpi-card, .module-card, .panel-card {
      background: linear-gradient(160deg, var(--navy-800), var(--navy-900));
      border: 1px solid rgba(255, 255, 255, 0.07);
      border-radius: 16px;
      transition: transform 0.3s cubic-bezier(0.165, 0.84, 0.44, 1), 
                  box-shadow 0.3s cubic-bezier(0.165, 0.84, 0.44, 1),
                  border-color 0.3s ease;
    }

    .kpi-card:hover {
      transform: translateY(-4px);
      border-color: rgba(56, 189, 248, 0.25);
      box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.5);
    }

    .module-card:hover {
      transform: translateY(-6px);
      border-color: rgba(56, 189, 248, 0.35);
      box-shadow: 0 16px 32px -12px rgba(7, 11, 20, 0.8);
    }

    .module-card .go-arrow {
      transition: transform 0.25s ease;
    }
    .module-card:hover .go-arrow {
      transform: translateX(6px);
    }

    .ic-badge {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      transition: transform 0.3s ease;
    }
    .module-card:hover .ic-badge {
      transform: scale(1.1);
    }

    .module-card.staff .ic-badge { background: rgba(52, 211, 153, 0.14); color: var(--mint-400); }
    .module-card.oil .ic-badge { background: rgba(251, 191, 36, 0.14); color: var(--amber-400); }
    .module-card.slot .ic-badge { background: rgba(56, 189, 248, 0.14); color: var(--sky-400); }

    /* Queue Row Transition */
    .queue-item {
      transition: background-color 0.2s ease, transform 0.2s ease;
      border-radius: 8px;
      padding: 6px 8px;
    }
    .queue-item:hover {
      background-color: rgba(255, 255, 255, 0.03);
      transform: translateX(3px);
    }

    /* Alert Indicators */
    .dot-indicator {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      margin-top: 6px;
      flex-shrink: 0;
    }

    /* Live Queue Pulse Indicator */
    .live-dot {
      display: inline-block;
      width: 8px;
      height: 8px;
      background-color: var(--mint-400);
      border-radius: 50%;
      margin-right: 6px;
      animation: live-pulse 1.5s infinite;
    }

    @keyframes live-pulse {
      0% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.4; transform: scale(1.2); }
      100% { opacity: 1; transform: scale(1); }
    }
  </style>
</head>
<body>

<div class="d-flex min-vh-100">
  <!-- Sidebar -->
  <aside class="sidebar d-flex flex-column p-3 sticky-top">
    <!-- Brand Header -->
    <div class="d-flex align-items-center gap-2 px-1 pt-1">
      <div class="brand-logo-container">
        <img src="photo1.jpg" alt="MAP & PUMP Logo" class="brand-logo-img">
      </div>
      <span class="brand-title">MAP & PUMP</span>
    </div>
    <div class="text-secondary small fw-medium ms-1 mt-1">Branch Manager Console</div>

    <div class="side-branch rounded-3 p-2 my-3">
      <div class="fw-bold font-space">Denko — Mingalardon</div>
      <small class="text-secondary">Branch ID: YGN-014</small>
    </div>

    <!-- Sidebar Navigation -->
    <nav class="nav nav-pills flex-column gap-2 my-2 flex-grow-1">
      <a href="dashboard.php" class="nav-link-custom active d-flex align-items-center gap-3">
        <div class="side-ic-box side-ic-home">
          <i class="bi bi-grid-1x2-fill"></i>
        </div>
        <span>Dashboard</span>
      </a>

      <a href="manage-staff.php" class="nav-link-custom d-flex align-items-center gap-3">
        <div class="side-ic-box side-ic-staff">
          <i class="bi bi-people-fill"></i>
        </div>
        <span>Staff Management</span>
      </a>

      <a href="manage-oil.php" class="nav-link-custom d-flex align-items-center gap-3">
        <div class="side-ic-box side-ic-stock">
          <i class="bi bi-fuel-pump-fill"></i>
        </div>
        <span>Stock Management</span>
      </a>

      <a href="booking-slot.php" class="nav-link-custom d-flex align-items-center gap-3">
        <div class="side-ic-box side-ic-booking">
          <i class="bi bi-ticket-perforated-fill"></i>
        </div>
        <span>Booking Slot Staffing</span>
      </a>
    </nav>

    <!-- Logout -->
    <a href="login.php" class="nav-link-custom text-danger fw-bold d-flex align-items-center gap-3 pt-3 border-top border-secondary border-opacity-25">
      <div class="side-ic-box side-ic-logout">
        <i class="bi bi-box-arrow-right"></i>
      </div>
      <span>Log Out</span>
    </a>
  </aside>

  <!-- Main Content Area -->
  <div class="flex-grow-1 min-w-0">
    <!-- Topbar -->
    <header class="topbar sticky-top d-flex align-items-center justify-content-between px-4 py-3">
      <div>
        <h1 class="h5 fw-bold mb-0">Dashboard</h1>
        <div class="text-secondary small" id="current-date">Loading date...</div>
      </div>
      <div class="d-flex align-items-center gap-3">
        <div class="bell-btn rounded-circle d-flex align-items-center justify-content-center position-relative" role="button" id="bellIcon" title="Notifications">
          <i class="bi bi-bell text-light"></i>
          <span class="bell-ping"></span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="avatar-circle d-flex align-items-center justify-content-center fw-bold font-space text-white">F</div>
          <div class="small fw-bold">Felix</div>
        </div>
      </div>
    </header>

    <!-- Content Body -->
    <main class="p-4 p-md-5">
      <h2 class="h4 fw-bold mb-1">Hello! Felix 👋</h2>
      <p class="text-secondary small mb-4">You can view the situation of this branch today</p>

      <!-- KPI Grid -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="kpi-card p-3">
            <span class="text-secondary small d-block mb-1">Today's Token</span>
            <span class="font-space fs-2 fw-bold counter-val" data-target="184">0</span>
            <div class="small text-success fw-semibold mt-1"><i class="bi bi-caret-up-fill"></i> 12% of yesterday</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="kpi-card p-3">
            <span class="text-secondary small d-block mb-1">Current waiting cars</span>
            <span class="font-space fs-2 fw-bold counter-val" data-target="9">0</span>
            <div class="small text-secondary fw-semibold mt-1">Average waiting time: 16 mins</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="kpi-card p-3">
            <span class="text-secondary small d-block mb-1">Average petrol stock</span>
            <span class="font-space fs-2 fw-bold"><span class="counter-val" data-target="61">0</span>%</span>
            <div class="small text-danger fw-semibold mt-1"><i class="bi bi-caret-down-fill"></i> Low Diesel</div>
          </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="kpi-card p-3">
            <span class="text-secondary small d-block mb-1">Today assigned Staff</span>
            <span class="font-space fs-2 fw-bold">7 / 9</span>
            <div class="small text-success fw-semibold mt-1">2 absent with request</div>
          </div>
        </div>
      </div>

      <!-- Modules Section -->
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h3 class="h6 fw-bold mb-0">Modules</h3>
      </div>
      
      <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
          <a href="manage-staff.html" class="text-decoration-none text-light">
            <div class="module-card staff p-4 h-100 d-flex flex-column gap-2">
              <div class="ic-badge"><i class="bi bi-people"></i></div>
              <h4 class="h6 fw-bold mb-0">Staff Management</h4>
              <p class="text-secondary small mb-0 flex-grow-1">Manage shift scheduling, duty status, and new employee onboarding.</p>
              <span class="text-info small fw-bold mt-2">Go <i class="bi bi-arrow-right go-arrow"></i></span>
            </div>
          </a>
        </div>
        <div class="col-12 col-md-4">
          <a href="manage-oil.html" class="text-decoration-none text-light">
            <div class="module-card oil p-4 h-100 d-flex flex-column gap-2">
              <div class="ic-badge"><i class="bi bi-fuel-pump"></i></div>
              <h4 class="h6 fw-bold mb-0">Stock Management</h4>
              <p class="text-secondary small mb-0 flex-grow-1">View tank levels, delivery logs, and low stock alerts.</p>
              <span class="text-info small fw-bold mt-2">Go <i class="bi bi-arrow-right go-arrow"></i></span>
            </div>
          </a>
        </div>
        <div class="col-12 col-md-4">
          <a href="booking-slot.html" class="text-decoration-none text-light">
            <div class="module-card slot p-4 h-100 d-flex flex-column gap-2">
              <div class="ic-badge"><i class="bi bi-ticket-perforated"></i></div>
              <h4 class="h6 fw-bold mb-0">Booking Slot Staffing</h4>
              <p class="text-secondary small mb-0 flex-grow-1">View the live queue, pump status, and the current state of each token in real-time.</p>
              <span class="text-info small fw-bold mt-2">Go <i class="bi bi-arrow-right go-arrow"></i></span>
            </div>
          </a>
        </div>
      </div>

      <!-- Two-Column Queue & Alerts -->
      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="panel-card p-4 h-100">
            <div class="d-flex align-items-center justify-content-between mb-3">
              <h3 class="h6 fw-bold mb-0"><span class="live-dot"></span>Current Queue — Live</h3>
              <span class="badge bg-secondary-subtle text-secondary small">Auto-updating</span>
            </div>
            <div class="vstack gap-1" id="queue-container">
              <div class="queue-item d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-10">
                <span><strong class="font-space text-warning">B-041</strong> · <span class="text-secondary small">Pump #2</span></span>
                <span class="badge bg-success-subtle text-success rounded-pill px-3 py-1">Serving</span>
              </div>
              <div class="queue-item d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-10">
                <span><strong class="font-space text-warning">B-042</strong> · <span class="text-secondary small">Pump #1</span></span>
                <span class="badge bg-success-subtle text-success rounded-pill px-3 py-1">Serving</span>
              </div>
              <div class="queue-item d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-10">
                <span><strong class="font-space text-warning">B-043</strong> · <span class="text-secondary small">—</span></span>
                <span class="badge bg-info-subtle text-info rounded-pill px-3 py-1">Waiting</span>
              </div>
              <div class="queue-item d-flex align-items-center justify-content-between border-bottom border-secondary border-opacity-10">
                <span><strong class="font-space text-warning">B-044</strong> · <span class="text-secondary small">—</span></span>
                <span class="badge bg-info-subtle text-info rounded-pill px-3 py-1">Waiting</span>
              </div>
              <div class="queue-item d-flex align-items-center justify-content-between">
                <span><strong class="font-space text-warning">B-045</strong> · <span class="text-secondary small">—</span></span>
                <span class="badge bg-info-subtle text-info rounded-pill px-3 py-1">Waiting</span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="panel-card p-4 h-100">
            <h3 class="h6 fw-bold mb-3">Cautions!</h3>
            <div class="vstack gap-3">
              <div class="d-flex gap-3 pb-2 border-bottom border-secondary border-opacity-10">
                <div class="dot-indicator bg-warning"></div>
                <div>
                  <strong class="d-block small">Diesel Stock 22% left</strong>
                  <span class="text-secondary small">Needs to deliver in 2 days.</span>
                </div>
              </div>
              <div class="d-flex gap-3 pb-2 border-bottom border-secondary border-opacity-10">
                <div class="dot-indicator bg-danger"></div>
                <div>
                  <strong class="d-block small">Pump #4 — Maintenance</strong>
                  <span class="text-secondary small">Stopped since 9:00 am.</span>
                </div>
              </div>
              <div class="d-flex gap-3">
                <div class="dot-indicator bg-success"></div>
                <div>
                  <strong class="d-block small">Petrol 92 Delivery received</strong>
                  <span class="text-secondary small">8,000 litres — Received since morning.</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- Bootstrap 5.3.3 JavaScript Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    // Dynamic Real-time Date and Time display
    const updateTime = () => {
      const now = new Date();
      const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
      document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', options);
    };
    updateTime();
    setInterval(updateTime, 30000);

    // Animated Counters for KPI numbers
    const counters = document.querySelectorAll('.counter-val');
    counters.forEach(counter => {
      const target = +counter.getAttribute('data-target');
      const duration = 1200; 
      const stepTime = 20;
      const steps = duration / stepTime;
      const increment = target / steps;
      let current = 0;

      const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
          counter.textContent = target;
          clearInterval(timer);
        } else {
          counter.textContent = Math.ceil(current);
        }
      }, stepTime);
    });

    // Notification Bell Interactive Popover
    const bellIcon = document.getElementById('bellIcon');
    const popover = new bootstrap.Popover(bellIcon, {
      title: 'Notifications',
      content: '• Diesel stock running low (22%)<br>• Pump #4 undergoing maintenance',
      html: true,
      placement: 'bottom',
      trigger: 'focus'
    });

    // Queue row hover/click interaction effect
    const queueRows = document.querySelectorAll('.queue-item');
    queueRows.forEach(row => {
      row.addEventListener('click', () => {
        row.style.transition = 'transform 0.1s ease';
        row.style.transform = 'scale(0.98)';
        setTimeout(() => {
          row.style.transform = 'scale(1)';
        }, 100);
      });
    });
  });
</script>
</body>
</html>