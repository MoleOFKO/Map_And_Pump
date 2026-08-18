<?php
/* ==========================================================================
   SHARED <head> + PAGE CHROME
   Set these before including this file:
     $page_title       — browser tab title
     $page_description — meta description
     $active_nav       — which nav item to highlight: home | how | pricing | news | drivers
   ========================================================================== */
$page_title       = $page_title       ?? 'MAP & PUMP';
$page_description = $page_description ?? 'Smart fuel booking and live station tracking.';
$active_nav       = $active_nav       ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#05070e">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">

    <!-- Bootstrap 5.3.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6.5.1 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Custom Theme -->
    <link rel="stylesheet" href="/css/HomePage.css">

    <link rel="icon" type="image/png" href="/images/Logo.png">
</head>
<body>

<div class="mp-backdrop"></div>
<div id="scroll-progress"></div>
<div id="mouse-follower"></div>