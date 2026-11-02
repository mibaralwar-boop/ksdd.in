<?php
declare(strict_types=1);
// Finance 360 V29 platform setup (CLI). Touches ONLY the platform database.
//   php tools/platform-setup.php migrate
//   php tools/platform-setup.php admin <email> "<name>"            (asks for the password on stdin; role super_admin)
//   php tools/platform-setup.php pool-add <host> <port> <db_name> <db_user>   (asks for the password; database must be EMPTY)
//   php tools/platform-setup.php status
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'platform/schema.php'; require_once 'platform/auth.php';
$cmd = $argv[1] ?? 'status';
$ask = function (string $q): string { fwrite(STDOUT, $q); if (DIRECTORY_SEPARATOR === '/' && posix_isatty(STDIN)) system('stty -echo'); $v = trim((string)fgets(STDIN)); if (DIRECTORY_SEPARATOR === '/' && posix_isatty(STDIN)) system('stty echo'); fwrite(STDOUT, "\n"); return $v; };
try {
    switch ($cmd) {
        case 'migrate': $d = p_migrate(); echo $d ? 'Applied platform migrations: ' . implode(', ', $d) . "\n" : "Platform schema already current (v" . p_schema_version() . ")\n"; break;
        case 'admin': $id = pa_create($argv[2] ?? '', $argv[3] ?? 'Super Admin', $ask('Password (10+ chars, letters+numbers): '), 'super_admin'); p_audit('system', null, null, 'admin.create_cli', ['id' => $id]); echo "Super admin #$id created. Sign in at platform-admin/ and set up two-step sign-in.\n"; break;
        case 'pool-add':
            [$h, $port, $n, $u] = [$argv[2] ?? '', (int)($argv[3] ?? 3306), $argv[4] ?? '', $argv[5] ?? '']; $pw = $ask('Database password: ');
            $pdo = db_connect_target(['db_host' => $h, 'db_port' => $port, 'db_name' => $n, 'db_user' => $u, 'db_password' => $pw]);
            if ((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() > 0) throw new RuntimeException('Database is not empty.');
            prun('INSERT INTO p_db_pool(db_host,db_port,db_name,db_user,db_pass_enc) VALUES(?,?,?,?,?)', $h, $port, $n, $u, p_enc($pw)); echo "Added $n to the pool.\n"; break;
        default:
            echo 'Platform schema v' . p_schema_version() . ' (code expects v' . P_SCHEMA . ")\n";
            foreach (prows('SELECT status,COUNT(*) n FROM p_tenants GROUP BY status') as $r) echo "tenants {$r['status']}: {$r['n']}\n";
            foreach (prows('SELECT status,COUNT(*) n FROM p_db_pool GROUP BY status') as $r) echo "pool {$r['status']}: {$r['n']}\n";
            echo 'admins: ' . pval('SELECT COUNT(*) FROM p_admins') . ' · provisioning mode: ' . prov_mode() . ' · signup open: ' . (p_signup_open() ? 'yes' : 'no') . ' · billing: ' . billing_gw()['mode'] . "\n";
    }
} catch (Throwable $e) { fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(1); }
