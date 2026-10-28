<?php
declare(strict_types=1);

// Single entry point (vercel.json routes everything here).
foreach (['Config', 'Http', 'Jwt', 'Supabase', 'Session', 'PafValidator', 'App', 'Pafs', 'Router'] as $c) {
    require_once __DIR__ . '/../src/' . $c . '.php';
}
\Ei6\Router::dispatch();
