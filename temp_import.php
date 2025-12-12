<?php
$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=store';
$user = 'root';
$pass = '';
$sql = file_get_contents(__DIR__.'/store_db_no_fk.sql');
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
    $pdo->exec($sql);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    echo "Import completed\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
