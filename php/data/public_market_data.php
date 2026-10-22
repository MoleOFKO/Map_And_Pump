<?php
/**
 * Database-backed public fuel prices, availability ticker, and market news.
 * Falls back to empty arrays when the database is unavailable or managers
 * have not published prices yet — never to hard-coded sample figures.
 */

require_once __DIR__ . '/../business_scope.php';

if (!function_exists('map_pump_oil_name_to_grade_code')) {
    function map_pump_oil_name_to_grade_code(string $oilName): ?string
    {
        $key = strtolower(str_replace(' ', '', trim($oilName)));
        return match (true) {
            $key === 'octane92' => '92',
            $key === 'octane95' => '95',
            in_array($key, ['disel', 'diesel'], true) => 'diesel',
            in_array($key, ['premiumdisel', 'premiumdiesel'], true) => 'pdiesel',
            default => null,
        };
    }
}

if (!function_exists('map_pump_grade_catalog')) {
    function map_pump_grade_catalog(): array
    {
        return [
            '92' => ['code' => '92', 'label' => 'Octane 92', 'icon' => 'fa-gas-pump'],
            '95' => ['code' => '95', 'label' => 'Octane 95', 'icon' => 'fa-gas-pump'],
            'diesel' => ['code' => 'diesel', 'label' => 'Diesel', 'icon' => 'fa-truck-fast'],
            'pdiesel' => ['code' => 'pdiesel', 'label' => 'Premium Diesel', 'icon' => 'fa-truck-front'],
        ];
    }
}

if (!function_exists('map_pump_public_hq_owner_join_sql')) {
    function map_pump_public_hq_owner_join_sql(): string
    {
        return "LEFT JOIN (
                    SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
                    FROM users u
                    LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
                    LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
                    LEFT JOIN roles current_role_lookup ON current_role_lookup.role_id = u.current_role_id
                    WHERE LOWER(TRIM(COALESCE(primary_role.role_name, ''))) IN ('hq', 'headquarter', 'headquarters')
                       OR LOWER(TRIM(COALESCE(business_role.role_name, ''))) IN ('hq', 'headquarter', 'headquarters')
                       OR LOWER(TRIM(COALESCE(current_role_lookup.role_name, ''))) IN ('hq', 'headquarter', 'headquarters')
                    GROUP BY u.headquarter_id
                ) hq_owner_ids ON hq_owner_ids.headquarter_id = h.headquarter_id
                LEFT JOIN users hq_owner ON hq_owner.user_id = COALESCE(hq_owner_ids.owner_user_id, h.headquarters_owner_id)";
    }
}

if (!function_exists('map_pump_public_pdo')) {
    function map_pump_public_pdo(): ?PDO
    {
        static $resolved = false;
        static $publicPdo = null;
        if ($resolved) {
            return $publicPdo;
        }
        $resolved = true;

        try {
            $connection = $GLOBALS['pdo'] ?? null;
            if (!($connection instanceof PDO)) {
                require_once __DIR__ . '/../conn.php';
                // conn.php is included inside this function, so its $pdo is local here.
                $connection = $pdo ?? ($GLOBALS['pdo'] ?? null);
            }
            $publicPdo = $connection instanceof PDO ? $connection : null;
        } catch (Throwable $e) {
            error_log('Public market data could not connect: ' . $e->getMessage());
            $publicPdo = null;
        }

        return $publicPdo;
    }
}

if (!function_exists('map_pump_load_latest_station_prices')) {
    function map_pump_load_latest_station_prices(PDO $pdo): array
    {
        $latestInventorySql = map_pump_latest_station_inventory_sql();
        $hqOwnerJoinSql = map_pump_public_hq_owner_join_sql();
        $stmt = $pdo->query(
                "SELECT s.branch_id AS station_id, s.branch_name AS station_name,
                    s.address AS station_address,
                    s.hq_region AS station_region,
                    h.headquarter_id, h.company_name,
                    ot.oil_type_id, ot.oil_name, li.price_per_litre, li.is_active, li.updated_at,
                    t.fuel_code,
                    GREATEST(0, t.current_l - COALESCE((
                        SELECT SUM(r.requested_liters)
                        FROM booking_reservations r
                        INNER JOIN booking active_b ON active_b.booking_id = r.booking_id
                        WHERE r.station_id = s.branch_id AND r.oil_type_id = ot.oil_type_id
                          AND active_b.status IN ('notfilled','waiting','serving')
                          AND active_b.expire_at > NOW()
                    ), 0)) AS current_l,
                    t.capacity_l, t.is_enabled
             FROM branches s
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             INNER JOIN ({$latestInventorySql}) li ON li.station_id = s.branch_id
             INNER JOIN oil_types ot ON ot.oil_type_id = li.oil_type_id
             LEFT JOIN tanks t ON t.branch_id = s.branch_id
                AND (
                    (t.fuel_code = 'p92' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane92')
                    OR (t.fuel_code = 'p95' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane95')
                    OR (t.fuel_code = 'dsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('disel', 'diesel'))
                    OR (t.fuel_code = 'pdsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('premiumdisel', 'premiumdiesel'))
                )
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')
               AND li.price_per_litre > 0"
        );

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = map_pump_oil_name_to_grade_code((string) ($row['oil_name'] ?? ''));
            $rows[] = array_merge($row, ['grade_code' => $code]);
        }

        return $rows;
    }
}

if (!function_exists('map_pump_load_previous_week_prices')) {
    function map_pump_load_previous_week_prices(PDO $pdo): array
    {
        $hqOwnerJoinSql = map_pump_public_hq_owner_join_sql();
        $stmt = $pdo->query(
            "SELECT s.branch_id AS station_id, ot.oil_name, soi.price_per_litre
             FROM station_oil_inventory soi
             INNER JOIN branches s ON s.branch_id = soi.station_id
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             INNER JOIN oil_types ot ON ot.oil_type_id = soi.oil_type_id
             INNER JOIN (
                 SELECT station_id, oil_type_id, MAX(inventory_id) AS inventory_id
                 FROM station_oil_inventory
                 WHERE updated_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY station_id, oil_type_id
             ) prev ON prev.inventory_id = soi.inventory_id
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')
               AND soi.price_per_litre > 0"
        );

        $byGrade = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = map_pump_oil_name_to_grade_code((string) ($row['oil_name'] ?? ''));
            if ($code === null) {
                continue;
            }
            $byGrade[$code][] = (float) $row['price_per_litre'];
        }

        $averages = [];
        foreach ($byGrade as $code => $values) {
            $averages[$code] = (int) round(array_sum($values) / count($values));
        }

        return $averages;
    }
}

if (!function_exists('map_pump_extract_region_name')) {
    function map_pump_extract_region_name(?string $location, ?string $address = null): string
    {
        $source = trim((string) ($location ?: $address ?: ''));
        if ($source === '') {
            return 'Other';
        }
        if (preg_match('/^([^,—\-]+)/u', $source, $matches)) {
            return trim(str_replace(' Region', '', $matches[1]));
        }

        return $source;
    }
}

if (!function_exists('map_pump_build_public_market_data')) {
    function map_pump_build_public_market_data(): array
    {
        $catalog = map_pump_grade_catalog();
        $data = [
            'source' => 'empty',
            'updated_at' => 'Awaiting manager price updates',
            'updated_at_raw' => null,
            'fuel_grades' => [],
            'station_fuel_types' => [],
            'station_fuel_prices' => [],
            'company_prices' => [],
            'region_prices' => [],
            'news_posts' => [],
            'live_ticker' => [],
            'platform_stats' => [
                'stations' => 0,
                'drivers' => 0,
            ],
        ];

        $pdo = map_pump_public_pdo();
        if (!$pdo) {
            return $data;
        }

        try {
            $latestRows = map_pump_load_latest_station_prices($pdo);
            if (!$latestRows) {
                return $data;
            }

            $data['source'] = 'database';
            $prevWeek = map_pump_load_previous_week_prices($pdo);

            $gradeTotals = [];
            $gradeUpdated = [];
            $companyMap = [];
            $regionMap = [];
            $stationMap = [];
            $stationFuelTypes = [];

            foreach ($latestRows as $row) {
                $code = (string) $row['grade_code'];
                $price = (int) $row['price_per_litre'];
                $stationId = (int) ($row['station_id'] ?? 0);
                $oilTypeId = (int) ($row['oil_type_id'] ?? 0);
                $oilName = trim((string) ($row['oil_name'] ?? 'Fuel'));

                if ($stationId > 0 && $oilTypeId > 0) {
                    if (!isset($stationMap[$stationId])) {
                        $stationMap[$stationId] = [
                            'station_name' => trim((string) ($row['station_name'] ?? 'Station')),
                            'prices' => [],
                        ];
                    }
                    $stationMap[$stationId]['prices'][$oilTypeId] = $price;
                    $stationFuelTypes[$oilTypeId] = [
                        'id' => $oilTypeId,
                        'label' => $oilName,
                    ];
                }

                if ($code === '') {
                    continue;
                }
                $gradeTotals[$code][] = $price;
                $gradeUpdated[$code] = max($gradeUpdated[$code] ?? '', (string) ($row['updated_at'] ?? ''));

                $hqId = (int) ($row['headquarter_id'] ?? 0);
                $companyName = trim((string) ($row['company_name'] ?? 'Unknown company'));
                if ($hqId > 0) {
                    if (!isset($companyMap[$hqId])) {
                        $companyMap[$hqId] = [
                            'company' => $companyName,
                            'stations' => [],
                            'prices' => [],
                            'prev' => [],
                        ];
                    }
                    $companyMap[$hqId]['stations'][(int) $row['station_id']] = true;
                    $companyMap[$hqId]['prices'][$code][] = $price;
                    if (isset($prevWeek[$code])) {
                        $companyMap[$hqId]['prev'][$code][] = $prevWeek[$code];
                    }
                }

                if ($code === '92') {
                    $region = trim((string) ($row['station_region'] ?? ''));
                    if ($region === '') {
                        $region = map_pump_extract_region_name(null, $row['station_address'] ?? null);
                    }
                    if (!isset($regionMap[$region])) {
                        $regionMap[$region] = ['stations' => []];
                    }
                    $regionMap[$region]['stations'][$stationId] = [
                        'station_name' => trim((string) ($row['station_name'] ?? 'Station')),
                        'address' => trim((string) ($row['station_address'] ?? '')),
                        'price92' => $price,
                    ];
                }
            }

            $standardFuelOrder = [
                'octane92' => 0,
                'octane95' => 1,
                'disel' => 2,
                'diesel' => 2,
                'premiumdisel' => 3,
                'premiumdiesel' => 3,
            ];
            $data['station_fuel_types'] = array_values($stationFuelTypes);
            usort($data['station_fuel_types'], static function (array $a, array $b) use ($standardFuelOrder): int {
                $aKey = strtolower(str_replace(' ', '', (string) $a['label']));
                $bKey = strtolower(str_replace(' ', '', (string) $b['label']));
                $aOrder = $standardFuelOrder[$aKey] ?? 100;
                $bOrder = $standardFuelOrder[$bKey] ?? 100;

                return $aOrder === $bOrder
                    ? strnatcasecmp((string) $a['label'], (string) $b['label'])
                    : $aOrder <=> $bOrder;
            });
            $data['station_fuel_prices'] = array_values($stationMap);
            usort($data['station_fuel_prices'], static fn(array $a, array $b): int => strnatcasecmp($a['station_name'], $b['station_name']));

            foreach ($catalog as $code => $meta) {
                if (empty($gradeTotals[$code])) {
                    continue;
                }
                $avg = (int) round(array_sum($gradeTotals[$code]) / count($gradeTotals[$code]));
                $data['fuel_grades'][] = [
                    'code' => $code,
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'avg' => $avg,
                    'prev' => $prevWeek[$code] ?? $avg,
                ];
            }

            foreach ($companyMap as $entry) {
                $prices = [];
                $previous = [];
                foreach ($catalog as $code => $_meta) {
                    if (!empty($entry['prices'][$code])) {
                        $prices[$code] = (int) round(array_sum($entry['prices'][$code]) / count($entry['prices'][$code]));
                    } else {
                        $prices[$code] = null;
                    }
                    if (!empty($entry['prev'][$code])) {
                        $previous[$code] = (int) round(array_sum($entry['prev'][$code]) / count($entry['prev'][$code]));
                    } else {
                        $previous[$code] = $prices[$code];
                    }
                }
                $data['company_prices'][] = [
                    'company' => $entry['company'],
                    'stations' => count($entry['stations']),
                    'prices' => $prices,
                    'prev' => $previous,
                ];
            }
            usort($data['company_prices'], static fn(array $a, array $b): int => strcmp($a['company'], $b['company']));

            foreach ($regionMap as $region => $entry) {
                if (!$entry['stations']) {
                    continue;
                }
                $stationPrices = array_column($entry['stations'], 'price92');
                $avg92 = (int) round(array_sum($stationPrices) / count($stationPrices));
                $data['region_prices'][] = [
                    'region' => $region,
                    'avg92' => $avg92,
                    'prev92' => $prevWeek['92'] ?? $avg92,
                    'stations' => count($entry['stations']),
                    'station_rows' => array_values($entry['stations']),
                ];
            }
            usort($data['region_prices'], static fn(array $a, array $b): int => strcmp($a['region'], $b['region']));

            $latestTimestamp = '';
            foreach ($gradeUpdated as $timestamp) {
                if ($timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                }
            }
            if ($latestTimestamp !== '') {
                $data['updated_at'] = date('j F Y, g:i A', strtotime($latestTimestamp));
                $data['updated_at_raw'] = $latestTimestamp;
            }

            $data['live_ticker'] = map_pump_build_live_ticker($pdo, $latestRows);
            $data['news_posts'] = map_pump_build_market_news($pdo, $latestRows);
            $data['platform_stats'] = map_pump_build_platform_stats($pdo);
        } catch (Throwable $e) {
            error_log('Failed to build public market data: ' . $e->getMessage());
        }

        return $data;
    }
}

if (!function_exists('map_pump_build_live_ticker')) {
    function map_pump_build_live_ticker(PDO $pdo, array $latestRows): array
    {
        $items = [];
        foreach ($latestRows as $row) {
            $enabled = (int) ($row['is_enabled'] ?? 1) === 1;
            $active = (int) ($row['is_active'] ?? 0) === 1;
            $current = (float) ($row['current_l'] ?? 0);
            $capacity = max(1, (float) ($row['capacity_l'] ?? 1));
            $pct = (int) round(($current / $capacity) * 100);
            $station = (string) ($row['station_name'] ?? 'Station');
            $grade = (string) ($row['oil_name'] ?? 'Fuel');

            if (!$enabled || !$active) {
                $items[] = ['class' => 'dot-out', 'text' => "{$station} — {$grade} switched off"];
            } elseif ($current <= 0) {
                $items[] = ['class' => 'dot-out', 'text' => "{$station} — {$grade} out of stock"];
            } elseif ($pct <= 25) {
                $items[] = ['class' => 'dot-low', 'text' => "{$station} — {$grade} running low"];
            } else {
                $items[] = ['class' => 'dot-ok', 'text' => "{$station} — {$grade} in stock"];
            }
        }

        $hqOwnerJoinSql = map_pump_public_hq_owner_join_sql();
        $deliveryStmt = $pdo->query(
            "SELECT s.branch_name AS station_name, d.quantity_l, d.fuel_code, d.received_at
             FROM deliveries d
             INNER JOIN branches s ON s.branch_id = d.branch_id
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')
             ORDER BY d.received_at DESC
             LIMIT 4"
        );
        foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
            $station = (string) ($delivery['station_name'] ?? 'Station');
            $qty = number_format((int) ($delivery['quantity_l'] ?? 0));
            $time = date('g:i A', strtotime((string) ($delivery['received_at'] ?? 'now')));
            $items[] = ['class' => 'dot-ok', 'text' => "{$station} — restocked {$qty} L at {$time}"];
        }

        return array_slice($items, 0, 12);
    }
}

if (!function_exists('map_pump_build_market_news')) {
    function map_pump_build_market_news(PDO $pdo, array $latestRows): array
    {
        $posts = [];
        $hqOwnerJoinSql = map_pump_public_hq_owner_join_sql();

        $priceChanges = $pdo->query(
            "SELECT s.branch_name AS station_name, h.company_name, ot.oil_name,
                    latest.price_per_litre AS current_price,
                    previous.price_per_litre AS previous_price,
                    latest.updated_at
             FROM (
                 SELECT soi.*
                 FROM station_oil_inventory soi
                 INNER JOIN (
                     SELECT station_id, oil_type_id, MAX(inventory_id) AS inventory_id
                     FROM station_oil_inventory
                     GROUP BY station_id, oil_type_id
                 ) x ON x.inventory_id = soi.inventory_id
             ) latest
             INNER JOIN (
                SELECT earlier.station_id, earlier.oil_type_id,
                       MAX(earlier.inventory_id) AS previous_inventory_id
                FROM station_oil_inventory earlier
                INNER JOIN (
                    SELECT station_id, oil_type_id, MAX(inventory_id) AS latest_inventory_id
                    FROM station_oil_inventory
                    GROUP BY station_id, oil_type_id
                ) latest_ids
                    ON latest_ids.station_id = earlier.station_id
                   AND latest_ids.oil_type_id = earlier.oil_type_id
                WHERE earlier.inventory_id < latest_ids.latest_inventory_id
                GROUP BY earlier.station_id, earlier.oil_type_id
             ) previous_ids
                ON previous_ids.station_id = latest.station_id
               AND previous_ids.oil_type_id = latest.oil_type_id
             INNER JOIN station_oil_inventory previous
                ON previous.inventory_id = previous_ids.previous_inventory_id
             INNER JOIN branches s ON s.branch_id = latest.station_id
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             INNER JOIN oil_types ot ON ot.oil_type_id = latest.oil_type_id
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')
               AND latest.price_per_litre > 0
               AND previous.price_per_litre > 0
               AND latest.price_per_litre <> previous.price_per_litre
             ORDER BY latest.updated_at DESC
             LIMIT 4"
        );
        foreach ($priceChanges->fetchAll(PDO::FETCH_ASSOC) as $change) {
            $delta = (int) $change['current_price'] - (int) $change['previous_price'];
            $direction = $delta > 0 ? 'increased' : 'decreased';
            $posts[] = [
                'tag' => 'price',
                'date' => date('j M Y', strtotime((string) $change['updated_at'])),
                'title' => ((string) $change['company_name']) . ' updated ' . ((string) $change['oil_name']) . ' price',
                'body' => ((string) $change['station_name']) . ' ' . $direction . ' ' . ((string) $change['oil_name'])
                    . ' to ' . number_format((int) $change['current_price']) . ' MMK/L '
                    . '(' . ($delta > 0 ? '+' : '') . number_format($delta) . ' MMK).',
            ];
        }

        $deliveryStmt = $pdo->query(
            "SELECT s.branch_name AS station_name, d.quantity_l, d.fuel_code, d.supplier, d.received_at
             FROM deliveries d
             INNER JOIN branches s ON s.branch_id = d.branch_id
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')
             ORDER BY d.received_at DESC
             LIMIT 4"
        );
        foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
            $posts[] = [
                'tag' => 'supply',
                'date' => date('j M Y', strtotime((string) $delivery['received_at'])),
                'title' => ((string) $delivery['station_name']) . ' recorded a fuel delivery',
                'body' => number_format((int) $delivery['quantity_l']) . ' L of '
                    . strtoupper((string) $delivery['fuel_code']) . ' received from '
                    . ((string) ($delivery['supplier'] ?: 'supplier')) . '.',
            ];
        }

        $stationCount = count(array_unique(array_map(static fn(array $row): int => (int) ($row['station_id'] ?? 0), $latestRows)));
        if ($stationCount > 0) {
            $posts[] = [
                'tag' => 'station',
                'date' => date('j M Y'),
                'title' => $stationCount . ' stations currently publishing live prices',
                'body' => 'Public price and availability data is now sourced from branch-manager updates across participating companies.',
            ];
        }

        return array_slice($posts, 0, 8);
    }
}

if (!function_exists('map_pump_build_platform_stats')) {
    function map_pump_build_platform_stats(PDO $pdo): array
    {
        $hqOwnerJoinSql = map_pump_public_hq_owner_join_sql();
        $stations = (int) $pdo->query(
            "SELECT COUNT(DISTINCT s.branch_id)
             FROM branches s
             INNER JOIN station_business_scope sbs ON sbs.station_id = s.branch_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             {$hqOwnerJoinSql}
             WHERE LOWER(COALESCE(s.status, 'active')) IN ('active', 'approved')
               AND LOWER(COALESCE(hq_owner.status, 'approved')) IN ('active', 'approved')"
        )->fetchColumn();

        $drivers = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM users u
             WHERE COALESCE(u.current_role_id, u.role_id) = 6
               AND LOWER(COALESCE(u.status, 'active')) NOT IN ('suspended', 'removed')"
        )->fetchColumn();

        return [
            'stations' => $stations,
            'drivers' => $drivers,
        ];
    }
}
