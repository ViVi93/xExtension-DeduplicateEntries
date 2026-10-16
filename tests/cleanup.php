<?php
declare(strict_types=1);
require dirname(__DIR__).'/Cleanup.php';
$p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('PRAGMA foreign_keys=ON; CREATE TABLE feed(id INTEGER PRIMARY KEY, cache_nbEntries INTEGER,cache_nbUnreads INTEGER); CREATE TABLE tag(id INTEGER PRIMARY KEY); INSERT INTO tag VALUES(11),(12); CREATE TABLE entry (id INTEGER PRIMARY KEY,link TEXT,guid TEXT,title TEXT,content TEXT,author TEXT,date INTEGER,lastSeen INTEGER,lastModified INTEGER,lastUserModified INTEGER,is_read INTEGER,is_favorite INTEGER,id_feed INTEGER REFERENCES feed(id),tags TEXT,attributes TEXT); CREATE TABLE entrytag(id_tag INTEGER REFERENCES tag(id),id_entry INTEGER REFERENCES entry(id) ON DELETE CASCADE,PRIMARY KEY(id_tag,id_entry)); INSERT INTO feed VALUES(1,99,99),(2,99,99);');
$s=$p->prepare('INSERT INTO entry VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$s->execute([1,'https://www.ndtv.com/story#publisher=newsstand','a','Old title','short','Alice',1,2,3,4,0,0,1,'#one','{"source":"a"}']);
$s->execute([2,'https://www.ndtv.com/story','b','Edited title','much longer useful body','Bob',1,9,8,7,1,1,2,'#two','{"source":"b"}']);
$s->execute([3,'https://example.com/episode-1','c','Inside Health','body','',1,1,1,1,0,0,1,'','{}']);
$s->execute([4,'https://example.com/episode-2','d','Inside Health','body','',1,1,1,1,0,0,1,'','{}']);
$p->exec('INSERT INTO entrytag VALUES(11,1),(12,2)');
function ok(bool $b,string $m):void {if(!$b)throw new RuntimeException($m);echo "PASS $m\n";}
function snapshot(PDO $p):array{return [$p->query('SELECT * FROM entry ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM entrytag ORDER BY id_tag,id_entry')->fetchAll(PDO::FETCH_ASSOC),$p->query('SELECT * FROM feed ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];}
$originalKeeper=$p->query('SELECT * FROM entry WHERE id=2')->fetch(PDO::FETCH_ASSOC);
$plan=DeduplicateCleanup::plan($p);
ok($plan['surplus_rows']===1,'one canonical surplus; distinct episodes untouched');
ok((int)$p->query('SELECT COUNT(*) FROM entry')->fetchColumn()===4,'dry run writes no article data');
$result=DeduplicateCleanup::apply($p,$plan['fingerprint']);
ok($result['deleted']===1 && (int)$p->query('SELECT COUNT(*) FROM entry')->fetchColumn()===3,'one deletion after approved fingerprint');
$row=$p->query("SELECT * FROM entry WHERE link='https://www.ndtv.com/story'")->fetch(PDO::FETCH_ASSOC);
ok($row!==false && (int)$row['is_favorite']===1 && (int)$row['is_read']===0,'favourite keeper; unread preserved');
ok($row['guid']==='b' && $row['content']==='much longer useful body','keeper original identity and richest content kept');
$attrs=json_decode($row['attributes'],true,flags:JSON_THROW_ON_ERROR);
ok($attrs['deduplicate_keeper_original']===$originalKeeper,'complete original keeper row and types preserved');
ok($attrs['source']==='b' && count($attrs['deduplicate_merged_sources'])===1 && $attrs['deduplicate_merged_sources'][0]['content']==='short','source metadata and alternate content archived');
ok(str_contains($row['tags'],'one')&&str_contains($row['tags'],'two'),'both article tags preserved');
ok((int)$p->query('SELECT COUNT(*) FROM entrytag WHERE id_entry=2')->fetchColumn()===2,'both label associations preserved');
ok((int)$p->query('SELECT COUNT(*) FROM entrytag WHERE id_entry=1')->fetchColumn()===0,'deleted entry has no orphan labels');
foreach($p->query('SELECT * FROM feed') as $f){$q=$p->query('SELECT COUNT(*) AS n,SUM(is_read=0) AS u FROM entry WHERE id_feed='.(int)$f['id'])->fetch(PDO::FETCH_ASSOC);ok((int)$f['cache_nbEntries']===(int)$q['n']&&(int)$f['cache_nbUnreads']===(int)$q['u'],'feed caches accurate');}
$empty=DeduplicateCleanup::plan($p);ok($empty['surplus_rows']===0,'cleanup idempotent');
try{DeduplicateCleanup::apply($p,'wrong');throw new RuntimeException('bad plan accepted');}catch(UnexpectedValueException $e){echo "PASS stale plan rejected\n";}
// Any content/label state change must invalidate a previously approved plan.
$s->execute([5,'https://www.ndtv.com/story#publisher=newsstand','e','Another title','short','',1,1,1,1,1,0,1,'','{}']);
$stale=DeduplicateCleanup::plan($p);
$p->exec("UPDATE entry SET content='equal' WHERE id=5");
try{DeduplicateCleanup::apply($p,$stale['fingerprint']);throw new RuntimeException('stale content accepted');}catch(UnexpectedValueException $e){echo "PASS equal-length content change invalidates plan\n";}
// Force a later failing statement; all earlier merges/deletions must roll back.
$before=$p->query('SELECT COUNT(*) FROM entry')->fetchColumn();
$beforeSnapshot=snapshot($p);
$fresh=DeduplicateCleanup::plan($p);
$p->exec("CREATE TRIGGER stop_cleanup BEFORE UPDATE ON entry BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
try{DeduplicateCleanup::apply($p,$fresh['fingerprint']);throw new RuntimeException('failure ignored');}catch(PDOException $e){}
ok($p->query('SELECT COUNT(*) FROM entry')->fetchColumn()===$before,'merge failure rolls back all deletions');
ok(snapshot($p)===$beforeSnapshot,'rollback restores complete entries labels and caches');
$p->exec('DROP TRIGGER stop_cleanup');
// Binary hash columns and malformed feed text must be archived losslessly.
$binary="bad\x80bytes";
$q=$p->prepare('UPDATE entry SET content=:data WHERE id=5');$q->execute([':data'=>$binary]);
$binaryPlan=DeduplicateCleanup::plan($p);
$q->execute([':data'=>"bad\x81bytes"]);
$changedPlan=DeduplicateCleanup::plan($p);
ok($binaryPlan['fingerprint']!==$changedPlan['fingerprint'],'different malformed bytes produce distinct fingerprints');
$binaryResult=DeduplicateCleanup::apply($p,$changedPlan['fingerprint']);
$binaryAttrs=json_decode($p->query('SELECT attributes FROM entry WHERE id=2')->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);
$last=end($binaryAttrs['deduplicate_merged_sources']);
ok(base64_decode($last['content']['deduplicate_binary_base64'],true)==="bad\x81bytes",'malformed source text archived byte-for-byte');
// A later invalid keeper JSON must roll back an earlier processed group.
foreach([[6,'https://www.ndtv.com/g1','{}'],[7,'https://www.ndtv.com/g1#publisher=newsstand','{}'],[8,'https://www.ndtv.com/g2','{bad'],[9,'https://www.ndtv.com/g2#publisher=newsstand','{}']] as [$id,$link,$attr]){
    $s->execute([$id,$link,'g'.$id,'Title','body','',1,1,1,1,0,0,1,'',$attr]);
}
$invalidPlan=DeduplicateCleanup::plan($p);$before=(int)$p->query('SELECT COUNT(*) FROM entry')->fetchColumn();
try{DeduplicateCleanup::apply($p,$invalidPlan['fingerprint']);throw new RuntimeException('invalid JSON accepted');}catch(JsonException $e){}
ok((int)$p->query('SELECT COUNT(*) FROM entry')->fetchColumn()===$before,'invalid keeper JSON rolls back earlier group');
$p->prepare('UPDATE entry SET attributes=:attrs WHERE id=8')->execute([':attrs'=>'{"deduplicate_merged_sources":42}']);
$invalidArchive=DeduplicateCleanup::plan($p);
try{DeduplicateCleanup::apply($p,$invalidArchive['fingerprint']);throw new RuntimeException('bad archive accepted');}catch(RuntimeException $e){ok($e->getMessage()==='Invalid existing source archive','invalid existing archive rejected');}
ok((int)$p->query('SELECT COUNT(*) FROM entry')->fetchColumn()===$before,'invalid archive rolls back earlier group');
ok($p->query('PRAGMA integrity_check')->fetchColumn()==='ok' && $p->query('PRAGMA foreign_key_check')->fetchAll()===[], 'integrity and enforced foreign keys valid');
echo "Cleanup checks complete\n";
