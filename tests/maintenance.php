<?php
declare(strict_types=1);
/** Exercise public CLI and backup refusal using private synthetic SQLite data. */
$root=sys_get_temp_dir().'/dedup-maintenance-'.bin2hex(random_bytes(8));mkdir($root,0700);
$p=new PDO('sqlite:'.$root.'/db.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE feed(id INTEGER PRIMARY KEY,cache_nbEntries INTEGER,cache_nbUnreads INTEGER);INSERT INTO feed VALUES(1,2,2);CREATE TABLE entry(id INTEGER PRIMARY KEY,link TEXT,guid TEXT,title TEXT,content TEXT,date INTEGER,lastSeen INTEGER,lastModified INTEGER,lastUserModified INTEGER,is_read INTEGER,is_favorite INTEGER,id_feed INTEGER,tags TEXT,attributes TEXT);CREATE TABLE entrytmp AS SELECT * FROM entry WHERE 0;CREATE TABLE entrytag(id_tag INTEGER,id_entry INTEGER,PRIMARY KEY(id_tag,id_entry));');
$s=$p->prepare('INSERT INTO entry VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach([1,2] as $id)$s->execute([$id,'https://www.ndtv.com/cli'.($id===1?'#publisher=newsstand':''),'g'.$id,'Title','body',1,1,1,1,0,0,1,'','{}']);unset($p);
function runCli(array $args,bool $ok=true):array{
    $cmd=array_merge([PHP_BINARY,dirname(__DIR__).'/maintenance.php'],$args);
    $process=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if(($status===0)!==$ok)throw new RuntimeException('Unexpected CLI status: '.$err);
    return $ok?json_decode($out,true,flags:JSON_THROW_ON_ERROR):['error'=>$err];
}
$base=['--user-dir='.$root];$plan=runCli($base);
if($plan['surplus_rows']!==1)throw new RuntimeException('Dry run missed duplicate');echo "PASS CLI dry run\n";
runCli(array_merge($base,['--apply=wrong','--backup='.$root.'/before.sqlite']),false);echo "PASS CLI refuses stale fingerprint\n";
file_put_contents($root.'/occupied.sqlite','keep');runCli(array_merge($base,['--apply='.$plan['fingerprint'],'--backup='.$root.'/occupied.sqlite']),false);
if(file_get_contents($root.'/occupied.sqlite')!=='keep')throw new RuntimeException('Existing backup changed');echo "PASS CLI refuses existing backup\n";
$result=runCli(array_merge($base,['--apply='.$plan['fingerprint'],'--backup='.$root.'/before.sqlite']));
if($result['result']['deleted']!==1||$result['remaining_surplus']!==0||$result['integrity']!=='ok')throw new RuntimeException('CLI cleanup mismatch');
$b=new PDO('sqlite:'.$root.'/before.sqlite');if((int)$b->query('SELECT COUNT(*) FROM entry')->fetchColumn()!==2)throw new RuntimeException('Backup not pre-cleanup');echo "PASS CLI merges and preserves pre-cleanup backup\n";
$index=runCli(array_merge($base,['--index']));if($index['identities']!==1||$index['pending']!==0)throw new RuntimeException('Index mismatch');echo "PASS CLI index agrees with retained rows\n";
if(runCli($base)['surplus_rows']!==0)throw new RuntimeException('Cleanup not idempotent');echo "PASS CLI cleanup idempotent\n";
