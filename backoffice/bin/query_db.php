<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;

try {
    $pdo = Connection::getPDO();
    echo "CONNECTED TO DB\n\n";

    // Show sample IUVs of external pendenze
    $stmt = $pdo->query("SELECT iuv FROM flussi_rendicontazioni WHERE is_govpay = 0 LIMIT 10");
    $sampleIuvs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "SAMPLE IUVs:\n";
    foreach ($sampleIuvs as $iuv) {
        echo "  $iuv\n";
    }

    // Frequencies of LEFT(iuv, 3)
    echo "\nLEFT(iuv, 3) FREQUENCIES:\n";
    $stmt = $pdo->query("SELECT LEFT(iuv, 3) as p, COUNT(*) as c FROM flussi_rendicontazioni WHERE is_govpay = 0 GROUP BY p ORDER BY c DESC LIMIT 10");
    foreach ($stmt->fetchAll() as $r) {
        printf("  Prefix: %s | Count: %d\n", $r['p'], $r['c']);
    }

    // Frequencies of LEFT(iuv, 4)
    echo "\nLEFT(iuv, 4) FREQUENCIES:\n";
    $stmt = $pdo->query("SELECT LEFT(iuv, 4) as p, COUNT(*) as c FROM flussi_rendicontazioni WHERE is_govpay = 0 GROUP BY p ORDER BY c DESC LIMIT 10");
    foreach ($stmt->fetchAll() as $r) {
        printf("  Prefix: %s | Count: %d\n", $r['p'], $r['c']);
    }

    // Frequencies of LEFT(iuv, 5)
    echo "\nLEFT(iuv, 5) FREQUENCIES:\n";
    $stmt = $pdo->query("SELECT LEFT(iuv, 5) as p, COUNT(*) as c FROM flussi_rendicontazioni WHERE is_govpay = 0 GROUP BY p ORDER BY c DESC LIMIT 10");
    foreach ($stmt->fetchAll() as $r) {
        printf("  Prefix: %s | Count: %d\n", $r['p'], $r['c']);
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
