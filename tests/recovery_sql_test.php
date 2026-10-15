<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
// Real InnoDB claim semantics in a disposable fixture; never a store database.
define('ABSPATH', '/synthetic/'); define('ARRAY_A', 'ARRAY_A');
class NCWooConfig {}
class NCWooDB {}
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-recovery.php';
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D', $socket) || !file_exists($socket)) exit(2);
$database = 'nc_woo_recovery_' . bin2hex(random_bytes(6));
$dsn = 'mysql:unix_socket='.$socket.';charset=utf8mb4';
$admin = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $db = new PDO($dsn.';dbname='.$database, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE wp_ncwoo_recovery_token (token_hash CHAR(64) PRIMARY KEY, used_at DATETIME NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB');
    $wpdb = new class($db) {
        public string $prefix='wp_';
        private PDO $db;
        public function __construct(PDO $db) {$this->db=$db;}
        public function prepare($query, ...$args) {foreach($args as $arg) {$query=preg_replace('/%s/', $this->db->quote($arg), $query, 1);}return $query;}
        public function get_row($query, $mode) {return $this->db->query($query)->fetch(PDO::FETCH_ASSOC);}
        public function query($query) {return $this->db->exec($query);}
    };
    $service = new NCWooRecoveryService(new NCWooConfig(),new NCWooDB());
    $claim = new ReflectionMethod($service,'consume_token'); $claim->setAccessible(true);
    $resolve = new ReflectionMethod($service,'resolve_usable_token'); $resolve->setAccessible(true);
    $token='synthetic-fixture-token';
    $hash=hash('sha256',$token);
    $insert=$db->prepare('INSERT INTO wp_ncwoo_recovery_token VALUES (?,NULL,?)');
    $insert->execute([$hash,gmdate('Y-m-d H:i:s',time()+3600)]);
    // Both callers read before either claims: the second must lose even with
    // the usable row cached locally. Only SQL decides ownership of the claim.
    if (!$resolve->invoke($service,$token) || !$resolve->invoke($service,$token)) throw new RuntimeException('fixture resolve');
    if (!$claim->invoke($service,$token) || $claim->invoke($service,$token)) throw new RuntimeException('claim not single-use');
    if ($resolve->invoke($service,$token) !== null) throw new RuntimeException('used token resolved');
    $db->exec("UPDATE wp_ncwoo_recovery_token SET used_at=NULL,expires_at='2000-01-01 00:00:00'");
    if ($claim->invoke($service,$token)) throw new RuntimeException('expired token claimed');
    if ($claim->invoke($service,'unknown')) throw new RuntimeException('missing token claimed');
    echo "Real SQL: two stale reads, one claim only; replay, expired and missing tokens refused.\n";
} finally { $admin->exec('DROP DATABASE `'.$database.'`'); }
