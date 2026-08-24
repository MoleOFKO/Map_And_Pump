<?php
/* Shared navigation. Expects $active_nav (home|how|pricing|news|drivers). */
$active_nav = $active_nav ?? '';
$nav_items = [
    'how'     => [ 'label' => 'How it works', 'href' => '/HomePage.php#how' ],
    'pricing' => [ 'label' => 'Pricing',      'href' => '/HomePage.php#pricing' ],
    'news'    => [ 'label' => 'News',         'href' => '/php/News.php' ],
    'drivers' => [ 'label' => 'For drivers',  'href' => '/HomePage.php#drivers' ],
];
?>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top main-navbar py-3">
    <div class="container">
        <a class="navbar-brand logo-text d-flex align-items-center gap-2 fs-4 fw-bold" href="/HomePage.php">
            <img src="/images/Logo.png" alt="MAP &amp; PUMP Logo" class="navbar-logo-img">
            MAP &amp; PUMP
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mx-auto gap-lg-1 my-2 my-lg-0 text-center">
                <?php foreach ($nav_items as $key => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?php echo $active_nav === $key ? 'active' : ''; ?>"
                           href="<?php echo $item['href']; ?>"
                           <?php echo $key === 'news' ? '' : 'data-spy="' . $key . '"'; ?>>
                            <?php if ($key === 'news'): ?>
                                <span class="nav-news">News<span class="nav-news-dot"></span></span>
                            <?php else: ?>
                                <?php echo $item['label']; ?>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="d-flex flex-column flex-lg-row gap-2 gap-lg-3 align-items-stretch align-items-lg-center mt-3 mt-lg-0">
                <a class="nav-link-plain text-center" href="/php/Login/login.php">Sign in</a>
                <a class="btn btn-primary px-4 py-2 fw-semibold" href="/php/Company/register.php">
                    Register company <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
        </div>
    </div>
</nav>
