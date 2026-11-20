<?php
declare(strict_types=1);
// CLI-only, SELECT-only schema readiness check, never executed against production.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test' || getenv('DB_NAME') !== 'ipdams_qa' || getenv('DB_HOST') !== 'db') {
    fwrite(STDERR, "BLOCKED: requires CLI, APP_ENV=test, DB_NAME=ipdams_qa and DB_HOST=db\n");
    exit(2);
}
require '/var/www/html/private/app/bootstrap.php';
try {
    $db = database();
    $checks = [
        'users' => ['id', 'email', 'password_hash', 'role', 'active'],
        'organizations' => ['id', 'code', 'name', 'kind', 'address', 'parent_division_id'],
        'configuration_versions' => ['id', 'organization_id', 'config_key'],
        'disbursement_vouchers' => ['id', 'organization_id'],
        'purchase_requests' => ['id', 'organization_id'],
    ];
    $blocked = 0;
    foreach ($checks as $table => $required) {
        $stmt = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute(['ipdams_qa', $table]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($required, $existing));
        if ($missing) {
            echo 'BLOCKED ' . $table . ': missing ' . implode(', ', $missing) . "\n";
            $blocked++;
        } else {
            echo 'PASS ' . $table . ': required columns present' . "\n";
        }
    }
    if ($blocked) {
        echo "SCHEMA GATE: BLOCKED. Historical 001/002 migrations do not prove readiness for current IPDAMS workflows.\n";
        exit(3);
    }
    echo "SCHEMA GATE: limited inspected columns PASS; NOT a comprehensive financial schema certification.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKED database/schema connection: ' . $e->getMessage() . "\n");
    exit(2);
}
