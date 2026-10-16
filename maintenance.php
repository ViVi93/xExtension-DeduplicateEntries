#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Standalone SQLite maintenance: explicit user directory, lock, plan and backup. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/SqliteIndex.php';
require_once __DIR__.'/Cleanup.php';
$options=getopt('', ['user-dir:', 'apply:', 'backup:', 'index']);
try{
    $requested=$options['user-dir']??'';
    if(!is_string($requested)||$requested===''||realpath($requested)!==rtrim($requested,'/'))throw new RuntimeException('Use an absolute resolved --user-dir');
    $directory=realpath($requested);
    $file=$directory.'/db.sqlite';
    if(!is_file($file)||is_link($file)||realpath($file)!==$file)throw new RuntimeException('Expected an existing non-symlink db.sqlite');
    DeduplicateRefreshLock::acquire($directory);
    $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
    if(isset($options['index'])){
        $start=hrtime(true);$index=new DeduplicateSqliteIndex($pdo);$index->sync();
        echo json_encode(['mode'=>'index','identities'=>(int)$pdo->query('SELECT COUNT(*) FROM _dedup_identity')->fetchColumn(),'pending'=>(int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn(),'elapsed_ms'=>(hrtime(true)-$start)/1e6],JSON_THROW_ON_ERROR),"\n";
        exit;
    }
    $plan=DeduplicateCleanup::plan($pdo);
    if(!isset($options['apply'])){
        echo json_encode(['mode'=>'dry_run','fingerprint'=>$plan['fingerprint'],'surplus_rows'=>$plan['surplus_rows'],'groups'=>$plan['groups']],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
        exit;
    }
    if(!is_string($options['apply'])||!hash_equals($plan['fingerprint'],$options['apply']))throw new RuntimeException('Plan changed; rerun dry run');
    if((int)$pdo->query('SELECT COUNT(*) FROM entrytmp')->fetchColumn()!==0)throw new RuntimeException('Commit staged articles through FreshRSS before cleanup');
    $backup=$options['backup']??'';
    if(!is_string($backup)||$backup===''||file_exists($backup)||is_link($backup)||realpath(dirname($backup))!==dirname($backup))throw new RuntimeException('Provide a new --backup file in an existing resolved directory');
    $handle=fopen($backup,'x');if($handle===false)throw new RuntimeException('Cannot reserve backup');fclose($handle);chmod($backup,0600);
    $source=new SQLite3($file,SQLITE3_OPEN_READONLY);$dest=new SQLite3($backup,SQLITE3_OPEN_READWRITE);
    if(!$source->backup($dest)||$dest->querySingle('PRAGMA quick_check')!=='ok')throw new RuntimeException('Consistent backup verification failed');
    $dest->close();$source->close();
    $index=new DeduplicateSqliteIndex($pdo);$index->sync();
    $result=DeduplicateCleanup::apply($pdo,$options['apply']);
    $index->sync();
    $remaining=DeduplicateCleanup::plan($pdo);
    if($remaining['surplus_rows']!==0||$pdo->query('PRAGMA quick_check')->fetchColumn()!=='ok'||$pdo->query('PRAGMA foreign_key_check')->fetchAll()!==[])throw new RuntimeException('Post-cleanup verification failed; retain backup');
    echo json_encode(['mode'=>'applied','result'=>$result,'remaining_surplus'=>0,'backup'=>$backup,'backup_sha256'=>hash_file('sha256',$backup),'integrity'=>'ok','pending'=>(int)$pdo->query('SELECT COUNT(*) FROM _dedup_pending')->fetchColumn()],JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $e){fwrite(STDERR,'Maintenance failed: '.$e->getMessage()."\n");exit(1);}
