<?php

declare(strict_types=1);

/**
 * usePHP PSX demo (Phase 1)
 *
 * Auto-compiles .psx files on each request during development. In production
 * you would run `./vendor/bin/usephp compile components/` once during deploy.
 *
 * Run: php -S localhost:8000 examples/psx-index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\UsePHP;

session_start();

if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/../public/usephp.js');
    exit;
}

// Toy login / logout endpoints used by the defer demo on '/'. The UserHeader
// component reads $_SESSION at render time — because it is loaded via `defer`,
// that lookup happens in a separate POST request, so the cacheable main page
// never varies by session.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path === '/login') {
    $_SESSION['user'] = ['name' => 'Alice'];
    header('Location: /', true, 303);
    exit;
}
if ($path === '/logout') {
    unset($_SESSION['user']);
    header('Location: /', true, 303);
    exit;
}

$psxDir = __DIR__ . '/components/psx';
$cacheDir = __DIR__ . '/../var/cache/psx';
$manifestPath = $cacheDir . '/' . CompileCommand::MANIFEST_FILENAME;

// Dev mode: re-compile if any .psx file is newer than the manifest.
if (psxNeedsCompile($psxDir, $manifestPath)) {
    ob_start();
    $cmd = new CompileCommand();
    $exitCode = $cmd->run([$psxDir, '--cache=' . $cacheDir], $psxDir);
    ob_end_clean();
    if ($exitCode !== 0) {
        http_response_code(500);
        echo "PSX compile failed. Run: ./vendor/bin/usephp compile examples/components/psx/";
        exit;
    }
}

$app = new UsePHP();
$app->setSnapshotSecret('phase-1-demo-secret');
$app->loadComponentManifest($manifestPath);

// Deferred components are addressable by URL-safe name. UserHeader reads
// $_SESSION, so we mark its endpoint `private, no-store` — the page itself
// remains CDN-cacheable, while the per-user fragment is fetched separately
// at /_defer/user-header and never cached by intermediaries.
$app->registerDeferred(
    name: 'user-header',
    component: 'App\\Components\\Psx\\UserHeader',
    cacheControl: 'private, no-store',
);

$router = $app->getRouter();
$router->get('/', function (): \Polidog\UsePhp\Runtime\Element {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
    return \Polidog\UsePhp\Runtime\RenderContext::getApp()
        ->renderPsxComponent('App\\Components\\Psx\\Page', []);
});
$router->get('/counter', function (): \Polidog\UsePhp\Runtime\Element {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
    return \Polidog\UsePhp\Runtime\RenderContext::getApp()
        ->renderPsxComponent('App\\Components\\Psx\\Counter', ['initial' => 0]);
});
$router->get('/todo', function (): \Polidog\UsePhp\Runtime\Element {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
    return \Polidog\UsePhp\Runtime\RenderContext::getApp()
        ->renderPsxComponent('App\\Components\\Psx\\TodoList', []);
});

// Deferred endpoints serve fragment HTML and must NOT be wrapped in the
// page chrome below — the client replaces a placeholder with the response
// body verbatim. Handle the defer route before reaching the layout shell.
$deferredHtml = $app->handleDeferred();
if ($deferredHtml !== null) {
    header('Content-Type: text/html; charset=UTF-8');
    echo $deferredHtml;
    exit;
}

ob_start();
$app->run();
$content = ob_get_clean();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>usePHP PSX demo</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .page { display: flex; flex-direction: column; gap: 20px; }
        h1 { margin: 0; color: #333; text-align: center; }
        .card { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .card-title { margin: 0 0 12px; color: #4a4a4a; font-size: 1.15rem; }
        .card-body { color: #555; }
        .counter { background: #fafafa; border-radius: 8px; padding: 16px; }
        .counter-display { text-align: center; font-size: 1.5rem; margin: 12px 0; }
        .counter-buttons { display: flex; gap: 8px; justify-content: center; }
        .btn { padding: 8px 16px; font-size: 0.95rem; border: none; border-radius: 6px; cursor: pointer; background: #4a90e2; color: white; }
        .btn:hover { background: #357abd; }
        .btn-reset { background: #888; }
        .user-header { padding: 10px 16px; border-radius: 6px; background: #eef4ff; color: #345; display: flex; justify-content: space-between; align-items: center; }
        .user-header a { color: #4a90e2; }
        .user-header--skeleton { background: #e6e6e6; color: transparent; animation: pulse 1.2s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.55; } }
    </style>
</head>
<body>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
<?php

function psxNeedsCompile(string $dir, string $manifest): bool
{
    if (!file_exists($manifest)) {
        return true;
    }
    $manifestMtime = filemtime($manifest);
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.psx')) {
            if (filemtime($file->getPathname()) > $manifestMtime) {
                return true;
            }
        }
    }
    return false;
}
