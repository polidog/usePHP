<?php

declare(strict_types=1);

/**
 * usePHP PSX Phase 0 demo
 *
 * Compiles examples/components/psx/Counter.psx on startup and renders it.
 *
 * Run: php -S localhost:8000 examples/psx-index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\UsePHP;

if ($_SERVER['REQUEST_URI'] === '/usephp.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/../public/usephp.js');
    exit;
}

$psxSource = __DIR__ . '/components/psx/Counter.psx';
$compiledPath = __DIR__ . '/components/psx/Counter.psx.php';
$manifestPath = __DIR__ . '/components/psx/psx-manifest.php';

if (!file_exists($compiledPath) || filemtime($psxSource) > filemtime($compiledPath)) {
    $compiler = new Compiler();
    $compiled = $compiler->compile(file_get_contents($psxSource));
    file_put_contents($compiledPath, $compiled);
}

if (!file_exists($manifestPath) || filemtime($compiledPath) > filemtime($manifestPath)) {
    $manifest = [
        'App\\Components\\Psx\\Counter' => $compiledPath,
    ];
    file_put_contents(
        $manifestPath,
        "<?php\n\nreturn " . var_export($manifest, true) . ";\n"
    );
}

$app = new UsePHP();
$app->setSnapshotSecret('phase-0-demo-secret');
$app->loadComponentManifest($manifestPath);

$router = $app->getRouter();
$router->get('/', function (): \Polidog\UsePhp\Runtime\Element {
    \Polidog\UsePhp\Runtime\RenderContext::beginRender();
    return \Polidog\UsePhp\Runtime\RenderContext::getApp()
        ->renderPsxComponent('App\\Components\\Psx\\Counter', ['initial' => 0]);
});

$layoutWrapper = function (string $title, string $content): void {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?> - usePHP PSX</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        .counter { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { margin: 0 0 20px; color: #333; text-align: center; }
        .counter-display { text-align: center; font-size: 2rem; margin: 20px 0; color: #555; }
        .counter-buttons { display: flex; gap: 10px; justify-content: center; }
        .btn { padding: 10px 20px; font-size: 1rem; border: none; border-radius: 6px; cursor: pointer; background: #4a90e2; color: white; }
        .btn:hover { background: #357abd; }
        .btn-reset { background: #888; }
    </style>
</head>
<body>
    <h1><?= $title ?></h1>
    <?= $content ?>
    <script src="/usephp.js"></script>
</body>
</html>
<?php
};

ob_start();
$app->run();
$content = ob_get_clean();

$layoutWrapper('PSX Counter', $content);
