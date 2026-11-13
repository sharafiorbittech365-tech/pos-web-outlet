<?php

declare(strict_types=1);

use CodeIgniter\Test\Filters\CITestStreamFilter;
use OmniPOS\Core\Config\Backup;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Services\DatabaseBackup;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * php spark db:backup: dump → gzip → rotation → log, with a fake mysqldump (a PHP script) so the
 * test does not depend on the MySQL client tools.
 *
 * @internal
 */
final class DbBackupTest extends FeatureTestCase
{
    private string $dir;
    private string $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = WRITEPATH . 'w5-backup-test-' . bin2hex(random_bytes(3));
        mkdir($this->dir);
        $this->fake = $this->dir . '-fake.php';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dir);
        @unlink($this->fake);
        parent::tearDown();
    }

    /** A fake mysqldump: checks it got an option file and a database name, prints a dump (or fails). */
    private function config(string $mode = 'ok'): Backup
    {
        file_put_contents($this->fake, '<?php
$args = array_slice($argv, 1);
$opt = null;
foreach ($args as $a) { if (str_starts_with($a, "--defaults-extra-file=")) { $opt = substr($a, 22); } }
if ($opt === null || ! is_file($opt) || ! str_contains((string) file_get_contents($opt), "[client]")) { fwrite(STDERR, "no option file"); exit(3); }
if (' . var_export($mode, true) . ' === "fail") { fwrite(STDERR, "Access denied for user"); exit(2); }
echo "-- MySQL dump\nCREATE TABLE t (id INT);\nINSERT INTO t VALUES (1);\n";
if (' . var_export($mode, true) . ' === "ok") { echo "-- Dump completed on 2026-10-28\n"; }
');
        $c = new Backup();
        $c->mysqldump = PHP_BINARY;
        $c->prefixArgs = [$this->fake];
        $c->directory = $this->dir;
        $c->keepDays = 7;
        $c->keepMin = 2;

        return $c;
    }

    public function testBackupWritesGzipLogsAndRotates(): void
    {
        // three old backups (10 days) and one recent: with keepMin 2 the two oldest go
        foreach (['a', 'b', 'c'] as $i => $x) {
            $p = $this->dir . '/' . DatabaseBackup::PREFIX . 'old-' . $x . '.sql.gz';
            file_put_contents($p, 'x');
            touch($p, time() - 10 * 86400 - $i * 60);
        }
        $svc = new DatabaseBackup($this->config());
        $r = $svc->run();
        $this->assertSame('ok', $r['status'], $r['message']);
        $this->assertFileExists($r['path']);
        $this->assertStringEndsWith('.sql.gz', $r['file']);
        $sql = (string) gzdecode((string) file_get_contents($r['path']));
        $this->assertStringContainsString('INSERT INTO t VALUES (1);', $sql);
        $this->assertStringContainsString('Dump completed', $sql);
        $this->assertCount(2, $r['deleted']);
        $this->assertCount(2, $svc->files());
        $this->assertFileExists($this->dir . '/.htaccess', 'folder is closed to the web');

        $log = DatabaseBackup::lastRun();
        $this->assertSame('ok', $log['status']);
        $this->assertSame($r['file'], $log['file_name']);
        $this->assertGreaterThan(0, (int) $log['size_bytes']);

        // the health report sees the fresh backup
        $h = (new \OmniPOS\Core\Services\SystemHealth($this->config()))->report();
        $this->assertSame('ok', $h['checks']['backups']['status']);
    }

    public function testFailedOrIncompleteDumpIsNotKept(): void
    {
        $r = (new DatabaseBackup($this->config('fail')))->run();
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('Access denied', $r['message']);
        $this->assertSame([], glob($this->dir . '/*.sql*'));
        $this->assertSame('failed', DatabaseBackup::lastRun()['status']);

        $r = (new DatabaseBackup($this->config('truncated')))->run(null, false);
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('incomplete', $r['message']);
        $this->assertSame([], glob($this->dir . '/*.sql*'));

        $h = (new \OmniPOS\Core\Services\SystemHealth($this->config()))->report();
        $this->assertSame('warn', $h['checks']['backups']['status'], 'no successful backup yet in this folder / latest failed');
    }

    public function testPublicFolderIsRefused(): void
    {
        $this->assertTrue(DatabaseBackup::isPublicPath('C:/xampp/htdocs/shop/backups'));
        $this->assertTrue(DatabaseBackup::isPublicPath('/var/www/omnipos/apps/pos/public/bk'));
        $this->assertFalse(DatabaseBackup::isPublicPath('D:/omnipos-backups'));
        $this->assertFalse(DatabaseBackup::isPublicPath(WRITEPATH . 'backups'));
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new DatabaseBackup($this->config()))->run('C:/xampp/htdocs/x/public/bk'));
    }

    public function testSparkCommand(): void
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        $cfg = $this->config();
        \CodeIgniter\Config\Factories::injectMock('config', Backup::class, $cfg);
        command('db:backup --dir "' . $this->dir . '"');
        command('db:backup --list --dir "' . $this->dir . '"');
        $out = CITestStreamFilter::$buffer;
        CITestStreamFilter::removeOutputFilter();
        $this->assertStringContainsString('Backup written', $out);
        $this->assertStringContainsString('1 backup(s)', $out);
        $this->assertCount(1, (new DatabaseBackup($cfg))->files());
    }
}
