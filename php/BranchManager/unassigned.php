<?php
require_once __DIR__ . '/_helpers.php';
require_branch_manager(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Assignment Pending | Map &amp; Pump</title>
    <style>
        :root { color-scheme: light; font-family: "Segoe UI", sans-serif; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #f1f5f4; color: #172a28; }
        main { width: min(520px, calc(100% - 40px)); padding: 36px; border: 1px solid #d5e0dc; border-radius: 8px; background: #fff; }
        h1 { margin: 0 0 12px; font-size: 1.6rem; }
        p { margin: 0 0 24px; color: #52635f; line-height: 1.6; }
        a { color: #126b59; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <h1>Branch assignment pending</h1>
        <p>Your Branch Manager account is active, but HQ has not assigned a station yet. Contact your HQ administrator; branch tools will be available once the assignment is complete.</p>
        <a href="/php/BranchManager/logout.php">Sign out</a>
    </main>
</body>
</html>
