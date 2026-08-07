<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MAP & PUMP | Smart Fuel Booking & Tracking</title>
    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Updated FontAwesome to your requested 6.5.1 Link -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Custom Theme Colors Over Bootstrap -->
    <link rel="stylesheet" href="/css/HomePage.css">
    <style>
        #mouse-follower {
            position: fixed;
            left: 0;
            top: 0;
            width: 120px;
            height: 120px;
            pointer-events: none;
            border-radius: 50%;
            background: rgba(150, 230, 255, 0.18);
            box-shadow: 0 0 40px rgba(150, 230, 255, 0.35),
                        0 0 110px rgba(150, 230, 255, 0.12),
                        inset 0 0 20px rgba(255, 255, 255, 0.15);
            transform: translate(-50%, -50%);
            will-change: transform;
            z-index: 9999;
            mix-blend-mode: screen;
        }
    </style>
    <!-- Browser Tab Logo (Favicon) -->
    <link rel="icon" type="image/png" href="../
    ../images/Logo.png">
</head>
<body>
    <div id="mouse-follower"></div>

    <!-- NAVIGATION HEADER -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom fixed-top backdrop-blur main-navbar py-3">
        <div class="container">
            <!-- Brand Logo -->
            <a class="navbar-brand logo-text d-flex align-items-center gap-2 fs-4 fw-bold" href="#">
                <!-- Replaced the FontAwesome icon with your custom logo image -->
                <img src="/images/Logo.png" alt="MAP & PUMP Logo" class="navbar-logo-img">
                MAP & PUMP
            </a>
            
            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <!-- Navbar Links Container -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <!-- Centered Navigation Links -->
                <ul class="navbar-nav mx-auto gap-4 my-3 my-lg-0 text-center fs-5">
                    <li class="nav-item"><a class="nav-link px-3" href="#features">How it works</a></li>
                    <li class="nav-item"><a class="nav-link px-3" href="#about">About Stations</a></li>
                </ul>
                
                <!-- Action Button Replaced at the Right -->
                <div class="d-flex justify-content-center mt-2 mt-lg-0">
                    <a class="btn btn-primary rounded-pill px-4 py-2 fs-5 fw-semibold shadow-sm" href="/login">Sign In</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION (Main Advertising Area) -->
    <section class="hero-section d-flex align-items-center">
        <div class="container">
            <div class="row align-items-center g-5">
                
                <!-- Left Side: Advertising Copy -->
                <div class="col-lg-7 text-center text-lg-start">
                    <div class="badge-status mb-3 d-inline-flex align-items-center gap-2">
                        <span class="pulse-dot"></span> Real-time Station Tracking Active
                    </div>
                    <h1 class="display-4 fw-extrabold mb-3 text-gradient">No More Waiting in Long Fuel Queues.</h1>
                    <p class="lead text-muted-custom mb-4">
                        Find the nearest active gas station, track live oil availability, and grab your digital booking token. Arrive right on time when your pump is ready—no hassle, no wasted time in the heat.
                    </p>
                    <div class="d-sm-flex gap-3 justify-content-center justify-content-lg-start">
                        <a href="/register" class="btn btn-primary btn-lg px-4 py-3 mb-3 mb-sm-0 shadow-sm w-100 w-sm-auto">Find Nearest Station</a>
                        <a href="#features" class="btn btn-outline-light btn-lg px-4 py-3 w-100 w-sm-auto">See How It Works</a>
                    </div>
                </div>

                <!-- Right Side: Phone Mockup UI -->
                <div class="col-lg-5 d-flex justify-content-center">
                    <div class="phone-container p-4 shadow-lg w-100 expanded-phone">
                        <div class="d-flex justify-content-between small text-secondary mb-4">
                            <span>MAP & PUMP</span>
                            <span class="text-danger fw-bold">● Live</span>
                        </div>
                        
                        <!-- Station Details -->
                        <div class="card bg-dark border-secondary p-3 mb-4 text-start">
                            <h5 class="h6 mb-1 text-light">TharKayTa Station</h5>
                            <p class="small text-secondary mb-2"><i class="fa-solid fa-location-dot"></i> TharKayTa Township</p>
                            <div class="small text-light">Octane 92: <span class="text-success fw-bold">Available</span></div>
                        </div>
                        
                        <!-- Updated Token Card with QR Code and Token ID -->
                        <div class="card bg-gradient-token border-secondary p-4 text-center token-qr-card">
                            <span class="small text-info fw-bold tracking-wider d-block mb-3">YOUR DIGITAL TOKEN</span>
                            
                            <!-- Default QR Code Placeholder -->
                            <div class="qr-wrapper mb-3">
                                <i class="fa-solid fa-qrcode"></i>
                            </div>
                            
                            <hr class="border-secondary my-3">
                            
                            <!-- Cleaned details area with Active Token line removed -->
                            <div class="text-center small text-secondary">
                                <span>Estimated Time: <strong class="text-white">11:52 AM</strong></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section id="features" class="py-5 bg-features">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h2 class="fw-bold h1">Why Choose MAP & PUMP?</h2>
                <p class="text-muted-custom">Designed to eliminate queues, protect consumers, and streamline distribution.</p>
            </div>
            <div class="row g-4 justify-content-center">
                <!-- Card 1 (Real-Time Tracking) -->
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 bg-card border-secondary p-4">
                        <div class="icon-shape mb-4">
                            <i class="fa-solid fa-map fa-xl"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3 text-light">Real-Time Tracking</h3>
                        <p class="text-muted-custom small mb-0">Never drive blindly again. View instant stock levels and verified fuel/oil availability on an interactive map before you leave your house.</p>
                    </div>
                </div>
                <!-- Card 2 -->
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 bg-card border-secondary p-4">
                        <div class="icon-shape mb-4 text-info">
                            <i class="fa-solid fa-hourglass-half fa-xl"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3 text-light">Smart Token Slots</h3>
                        <p class="text-muted-custom small mb-0">Book a guaranteed time slot. Wait comfortably at home or in your office instead of sitting in a hot stationary vehicle on the street.</p>
                    </div>
                </div>
                <!-- Card 3 -->
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 bg-card border-secondary p-4">
                        <div class="icon-shape mb-4 text-info">
                            <i class="fa-solid fa-shield-halved fa-xl"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-3 text-light">Anti-Scam Protection</h3>
                        <p class="text-muted-custom small mb-0">An integrated reporting system lets users flag fake stock or black-market price-gouging, keeping the ecosystem safe and honest.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- B2B COMPANY LINK -->
    <section id="about" class="py-5 bg-gradient-business text-center">
        <div class="container py-5">
            <div class="max-width-md mx-auto">
                <h2 class="fw-bold mb-3">Are you a Station Owner or Fuel Company?</h2>
                <p class="text-light opacity-75 mb-4 px-md-5">Digitize your queue, manage your local station branches seamlessly, protect your staff from chaotic traffic control, and optimize your inventory metrics with our cloud framework.</p>
                <a href="/register?type=company" class="btn btn-success btn-lg px-5 py-3 fw-bold">Register Your Company</a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="py-4 bg-dark-custom text-center border-top border-secondary text-secondary small">
        <div class="container">
            <p class="mb-0">&copy; 2026 MAP & PUMP. Built for public safety and smarter distribution.</p>
        </div>
    </footer>

    <!-- Mouse follower animation -->
    <script>
        const follower = document.getElementById('mouse-follower');
        let targetX = window.innerWidth / 2;
        let targetY = window.innerHeight / 2;
        let currentX = targetX;
        let currentY = targetY;
        const ease = 0.14;

        window.addEventListener('mousemove', (event) => {
            targetX = event.clientX;
            targetY = event.clientY;
        });

        function animateFollower() {
            currentX += (targetX - currentX) * ease;
            currentY += (targetY - currentY) * ease;
            follower.style.transform = `translate3d(${currentX}px, ${currentY}px, 0) translate(-50%, -50%)`;
            requestAnimationFrame(animateFollower);
        }

        requestAnimationFrame(animateFollower);
    </script>

    <!-- Bootstrap Bundle JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>