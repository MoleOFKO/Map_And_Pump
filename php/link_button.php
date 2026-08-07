<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<style>
    /* Navigation Card Wrapper */
.nav-container {
  max-width: 850px;
  margin: 40px auto;
  padding: 32px;
  background: rgba(15, 23, 42, 0.75);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  backdrop-filter: blur(20px);
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
  font-family: 'Plus Jakarta Sans', sans-serif;
}

.nav-title {
  margin-bottom: 24px;
  font-size: 1.1rem;
  font-weight: 800;
  color: #38bdf8;
  text-align: center;
  text-transform: uppercase;
  letter-spacing: 1.5px;
}

/* Responsive Grid Layout */
.nav-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
}

/* Button Component */
.nav-btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  padding: 12px 20px;
  background: linear-gradient(180deg, rgba(15, 23, 42, 0.9) 0%, rgba(7, 11, 20, 0.95) 100%);
  color: #ffffff;
  border: 1px solid rgba(56, 189, 248, 0.25);
  border-radius: 12px;
  text-decoration: none;
  font-weight: 700;
  font-size: 0.875rem;
  letter-spacing: 0.3px;
  text-align: center;
  cursor: pointer;
  user-select: none;
  
  /* Smooth transitions for lift and press states */
  transition: transform 0.15s cubic-bezier(0.4, 0, 0.2, 1),
              border-color 0.15s ease,
              box-shadow 0.15s ease,
              background 0.15s ease;
  
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), 
              inset 0 1px 1px rgba(255, 255, 255, 0.1);
}

/* Hover State - Slight Lift & Cyan Glow */
.nav-btn:hover {
  transform: translateY(-2px);
  border-color: #38bdf8;
  color: #38bdf8;
  background: linear-gradient(180deg, rgba(20, 32, 58, 0.95) 0%, rgba(10, 18, 35, 0.98) 100%);
  box-shadow: 0 8px 20px rgba(56, 189, 248, 0.25), 
              inset 0 1px 1px rgba(255, 255, 255, 0.2);
}

/* Active Press-Down Effect */
.nav-btn:active {
  transform: translateY(2px);
  border-color: rgba(56, 189, 248, 0.5);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.4), 
              inset 0 2px 4px rgba(0, 0, 0, 0.6);
  background: rgba(7, 11, 20, 1);
}

/* Keyboard Focus Accessibility */
.nav-btn:focus-visible {
  outline: 2px solid #38bdf8;
  outline-offset: 3px;
}
</style>
<body>
<div class="nav-container">
  <h2 class="nav-title">System Quick Access</h2>
  
  <div class="nav-grid">
    <a href="/php/HomePage.php" class="nav-btn" target="_blank" rel="noopener noreferrer">Home Page</a>
    <a href="/php/Login/login.php" class="nav-btn" target="_blank" rel="noopener noreferrer">Login Page</a>
    <a href="/php/Admin(WebOwner)/admin_dashboard.php" class="nav-btn" target="_blank" rel="noopener noreferrer">Admin Dashboard</a>
    <a href="/php/Moderators(UnderAdmin)/moderator_dashboard.php" class="nav-btn" target="_blank" rel="noopener noreferrer">Moderator Dashboard</a>
    <a href="/php/HQ/dashboard.php?role=hq" class="nav-btn" target="_blank" rel="noopener noreferrer">HQ Dashboard</a>
    <a href="/php/BranchManager/dashboard.php?role=branch_manager" class="nav-btn" target="_blank" rel="noopener noreferrer">Branch Manager Dashboard</a>
    <a href="/php/Customer/dashboard.php?role=customer" class="nav-btn" target="_blank" rel="noopener noreferrer">Customer Dashboard</a>
  </div>
</div>
</body>
</html>