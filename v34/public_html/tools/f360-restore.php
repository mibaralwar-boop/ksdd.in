<?php
declare(strict_types=1);
// KSDD Finance 360 — restore an encrypted backup (.ksddbk) into an EMPTY database, and/or extract its documents.
// CLI only. Never restores into the live database named in config.php unless --i-understand-overwrite-live is given.
//   php tools/f360-restore.php --file=ksdd-backup-....ksddbk --pass-file=/path/pass.txt --verify-only
//   php tools/f360-restore.php --file=... --pass-file=... --db-host=localhost --db-name=ksdd_restore --db-user=u --db-pass=p --files-to=/tmp/restore_uploads
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../f360/admin.php';
require __DIR__ . '/../f360/purchase.php';
require __DIR__ . '/../f360/integrations.php';
$o = getopt('', ['file:', 'pass-file:', 'verify-only', 'db-host:', 'db-name:', 'db-user:', 'db-pass:', 'db-port:', 'files-to:', 'i-understand-overwrite-live']);
$die = function (string $m) { fwrite(STDERR, "ERROR: $m\n"); exit(1); };
if (empty($o['file']) || !is_file($o['file'])) $die('--file=<backup.ksddbk> is required');
if (empty($o['pass-file']) || !is_file($o['pass-file'])) $die('--pass-file=<file containing the backup passphrase> is required (keeps it out of shell history)');
$pass = rtrim((string)file_get_contents($o['pass-file']), "\r\n");
$blob = (string)file_get_contents($o['file']);
try { $v = f3_backup_verify_blob($blob, $pass); } catch (Throwable $e) { $die($e->getMessage()); }
echo "Verify: " . ($v['ok'] ? 'OK' : 'PROBLEMS') . " · {$v['tables']} tables · {$v['rows']} rows · {$v['documents_ok']}/{$v['documents']} documents · created {$v['created_utc']} · schema {$v['schema']}\n";
foreach ($v['issues'] as $i) echo "  - $i\n";
if (!$v['ok']) exit(2);
if (isset($o['verify-only'])) exit(0);
$parts = f3_backup_unpack(f3_decrypt_blob($blob, $pass)); $man = json_decode($parts['manifest.json'], true);
if (!empty($o['db-name'])) {
    $live = (string)(cfg()['db_name'] ?? ''); if ($o['db-name'] === $live && !isset($o['i-understand-overwrite-live'])) $die("Refusing to overwrite the live database '$live'. Restore into a NEW empty database, check it, then switch config.php.");
    $pdo = new PDO('mysql:host=' . ($o['db-host'] ?? 'localhost') . ';port=' . (int)($o['db-port'] ?? 3306) . ';dbname=' . $o['db-name'] . ';charset=utf8mb4', (string)($o['db-user'] ?? ''), (string)($o['db-pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"); // same as the app (lib.php db())
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); if ($existing && !isset($o['i-understand-overwrite-live'])) $die('Target database is not empty (' . count($existing) . ' tables). Use an empty database.');
    $stmt = ''; $n = 0;
    foreach (explode("\n", $parts['database.sql']) as $line) { if ($line === '' || str_starts_with($line, '-- ')) continue; $stmt .= $line . "\n"; if (str_ends_with($line, ';')) { $pdo->exec($stmt); $stmt = ''; $n++; } }
    $bad = 0; foreach ($man['tables'] as $t => $cnt) { $got = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); if ($got !== (int)$cnt) { echo "  row count mismatch $t: backup $cnt, restored $got\n"; $bad++; } }
    echo "Database restored: $n statements, " . count($man['tables']) . ' tables, ' . ($bad ? "$bad MISMATCHES" : 'all row counts match') . "\n"; if ($bad) exit(3);
}
if (!empty($o['files-to'])) {
    $root = rtrim($o['files-to'], '/'); $n = 0; $bad = 0;
    foreach ($man['files'] as $f) { $b = $parts['files/' . $f['path']] ?? null; if ($b === null || str_contains($f['path'], '..')) { $bad++; continue; } $p = $root . '/' . $f['path']; @mkdir(dirname($p), 0750, true); file_put_contents($p, $b); if ($f['sha256'] && hash_file('sha256', $p) !== $f['sha256']) $bad++; else $n++; }
    echo "Documents restored to $root: $n ok" . ($bad ? ", $bad FAILED" : '') . " (copy into private/uploads/)\n"; if ($bad) exit(4);
}
