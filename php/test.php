<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Grid Layout</title>
  <link rel="stylesheet" href="../css/test.css">
</head>
<body>

  <!-- 1. LEFT ICON SIDEBAR -->
  <aside class="icon-sidebar">
    <div class="logo">📄</div>
    <nav class="nav-icons">
      <a href="#" class="icon-link active">👤</a>
      <a href="#" class="icon-link">🔍</a>
      <a href="#" class="icon-link">🚀</a>
      <a href="#" class="icon-link">📡</a>
      <a href="#" class="icon-link">📁</a>
    </nav>
  </aside>

  <!-- MAIN CONTAINER (Header + Content Grid) -->
  <div class="main-container">
    
    <!-- 2. TOP NAV BAR -->
    <header class="top-nav">
      <div class="nav-links">
        <a href="#" class="active">Front page</a>
        <a href="#">Job board</a>
        <a href="#">Activity</a>
        <a href="#">Gift shop</a>
        <a href="#">Pricing</a>
        <a href="#">About</a>
      </div>
      <button class="auth-btn">Log in or sign up</button>
    </header>

    <!-- 3. THE BENTO/MASONRY GRID -->
    <main class="grid-layout">
      
      <!-- LEFT COLUMN: Widgets -->
      <div class="grid-column col-left">
        <!-- Supporter Card -->
        <div class="card supporter-card">
          <h3>Become a supporter</h3>
          <p>Unlock premium features, discounts on job listings, and help us build the future.</p>
          <button class="support-btn">💛 Become a supporter</button>
        </div>

        <!-- Job Listings Card -->
        <div class="card jobs-card">
          <h4>Active job listings</h4>
          <ul class="job-list">
            <li class="job-item">
              <div class="job-avatar">D</div>
              <div class="job-info"><strong>Doccle</strong><p>UI/UX Designer</p></div>
            </li>
            <li class="job-item">
              <div class="job-avatar">C</div>
              <div class="job-info"><strong>Character.AI</strong><p>Senior Product Designer</p></div>
            </li>
            <li class="job-item">
              <div class="job-avatar">V</div>
              <div class="job-info"><strong>Valley</strong><p>Head of Product</p></div>
            </li>
          </ul>
          <button class="see-all-btn">See all</button>
        </div>
      </div>

      <!-- MIDDLE COLUMN: Post Feed (Image Focus) -->
      <div class="grid-column col-mid">
        <div class="card feed-card">
          <div class="card-image-wrapper">
            <img src="https://via.placeholder.com/400x350" alt="Post art" class="card-img">
          </div>
          <div class="card-content">
            <span class="author">Johel Pereira</span>
            <h3>Synthetic Symmetry ↗</h3>
            <p>"Synthetic Symmetry" uses artwork to explore identity fluidity and celebrate our interconnectedness.</p>
            <div class="card-footer">
              <input type="text" placeholder="Reply directly..." class="reply-input">
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: Post Feed (Longer Content / Bold Banner) -->
      <div class="grid-column col-right">
        <div class="card feed-card featured">
          <div class="banner-header">
            <span>HIDRANTE</span>
            <span>5 — 6 NOVIEMBRE, 2021</span>
          </div>
          <div class="card-image-wrapper split">
            <div class="banner-text">LAS HIJAS DE LA PIEDRA</div>
            <img src="https://via.placeholder.com/200x300" alt="Sculpture" class="card-img-split">
          </div>
          <div class="card-content">
            <span class="author">Ryan Levitan</span>
            <h3>Hidrante at Hidrante ↗</h3>
            <p>Founded in 2015, Hidrante is a project space oriented towards exhibitions, residencies, and cultural production...</p>
            <div class="card-footer">
              <input type="text" placeholder="Reply directly..." class="reply-input">
            </div>
          </div>
        </div>
      </div>

    </main>
  </div>

</body>
</html>