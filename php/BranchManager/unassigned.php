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
    <link rel="icon" type="image/png" href="/images/Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            color-scheme: dark;
            --navy-950: #070b14;
            --navy-900: #0b1220;
            --navy-800: #101a30;
            --navy-700: #16213f;
            --sky-400: #38bdf8;
            --blue-500: #2f6feb;
            --amber-400: #fbbf24;
            --slate-50: #f8fafc;
            --slate-300: #c9d2e3;
            --slate-400: #8b96ad;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(ellipse at 50% 30%, rgba(47, 111, 235, 0.12), transparent 58%),
                var(--navy-950);
            color: var(--slate-50);
            font-family: Inter, sans-serif;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page-shell {
            width: min(1120px, calc(100% - 40px));
            min-height: 100vh;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            min-height: 82px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            font-family: "Space Grotesk", sans-serif;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .brand img {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }

        .brand-copy {
            display: grid;
            gap: 2px;
        }

        .brand-copy small {
            color: var(--slate-400);
            font-family: Inter, sans-serif;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0;
        }

        .sign-out {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 9px 13px;
            border: 1px solid rgba(248, 113, 113, 0.25);
            border-radius: 9px;
            color: #fca5a5;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .sign-out:hover,
        .sign-out:focus-visible {
            border-color: rgba(248, 113, 113, 0.5);
            background: rgba(248, 113, 113, 0.08);
        }

        main {
            flex: 1;
            display: grid;
            place-items: center;
            padding: 56px 0;
        }

        .assignment-card {
            width: min(620px, 100%);
            padding: clamp(26px, 6vw, 48px);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(16, 26, 48, 0.98), rgba(11, 18, 32, 0.98));
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.35);
            text-align: center;
        }

        .status-icon {
            width: 76px;
            height: 76px;
            margin: 0 auto 22px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(251, 191, 36, 0.24);
            border-radius: 22px;
            background: rgba(251, 191, 36, 0.1);
            color: var(--amber-400);
            font-size: 30px;
        }

        .status-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 14px;
            padding: 6px 10px;
            border: 1px solid rgba(251, 191, 36, 0.2);
            border-radius: 999px;
            background: rgba(251, 191, 36, 0.07);
            color: #fcd34d;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            font-family: "Space Grotesk", sans-serif;
            font-size: clamp(25px, 5vw, 34px);
            line-height: 1.2;
        }

        .description {
            max-width: 470px;
            margin: 15px auto 0;
            color: var(--slate-300);
            font-size: 14px;
            line-height: 1.75;
        }

        .next-step {
            margin-top: 28px;
            padding: 18px 20px;
            border: 1px solid rgba(56, 189, 248, 0.18);
            border-radius: 13px;
            background: rgba(56, 189, 248, 0.055);
            text-align: left;
        }

        .next-step-heading {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 7px;
            color: var(--slate-50);
            font-size: 13px;
            font-weight: 700;
        }

        .next-step-heading i {
            color: var(--sky-400);
        }

        .next-step p {
            margin: 0;
            color: var(--slate-400);
            font-size: 12.5px;
            line-height: 1.7;
        }

        .card-footer {
            margin-top: 24px;
            color: var(--slate-400);
            font-size: 12px;
        }

        .card-footer i {
            margin-right: 5px;
            color: var(--sky-400);
        }

        @media (max-width: 520px) {
            .page-shell {
                width: min(100% - 28px, 1120px);
            }

            .topbar {
                min-height: 70px;
            }

            .brand {
                font-size: 15px;
            }

            .sign-out {
                padding: 8px 10px;
                font-size: 12px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <header class="topbar">
            <div class="brand" aria-label="Map and Pump">
                <img src="/images/Logo.png" alt="">
                <span class="brand-copy">
                    Map &amp; Pump
                    <small>Branch Manager Console</small>
                </span>
            </div>
            <a class="sign-out" href="/php/BranchManager/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                Sign out
            </a>
        </header>

        <main>
            <section class="assignment-card" aria-labelledby="assignmentTitle">
                <div class="status-icon" aria-hidden="true">
                    <i class="fa-solid fa-location-dot"></i>
                </div>
                <div class="status-label">
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                    Assignment pending
                </div>
                <h1 id="assignmentTitle">Your station assignment is pending</h1>
                <p class="description">
                    Your Branch Manager account is active, but it is not assigned to a station yet.
                    Branch tools will become available after your HQ administrator assigns one.
                </p>

                <div class="next-step">
                    <div class="next-step-heading">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        What should I do?
                    </div>
                    <p>
                        Contact your HQ administrator to request a station assignment. Once it is set,
                        refresh this page or sign in again to open your Branch Manager dashboard.
                    </p>
                </div>

                <div class="card-footer">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Your account is active and waiting for assignment.
                </div>
            </section>
        </main>
    </div>
</body>
</html>
