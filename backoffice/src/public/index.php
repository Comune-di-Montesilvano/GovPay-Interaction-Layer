<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
// Serve file statici direttamente se esistono nella cartella public (img, css, js, ecc.)
if (php_sapi_name() === 'cli-server') {
    $url  = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . $url['path'];
    $realFile = realpath($file);
    // Path traversal guard: il file risolto deve restare dentro __DIR__.
    if ($realFile !== false && str_starts_with($realFile, __DIR__ . DIRECTORY_SEPARATOR) && is_file($realFile)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

[$app, $twig] = require __DIR__ . '/../bootstrap/app.php';
$routes = require __DIR__ . '/../routes/web.php';
$routes($app, $twig);

$app->run();
