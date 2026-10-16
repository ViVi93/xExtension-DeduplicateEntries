<?php
declare(strict_types=1);
require_once __DIR__.'/Url.php';

/** Explicit maintenance only. Caller must hold the refresh lock and take a backup. */
final class DeduplicateCleanup {
    /** JSON-safe, lossless source archive; binary fields (e.g. hash) use base64. */
    private static function archiveRow(array $row): array {
        foreach($row as &$value){
            if(is_string($value) && preg_match('//u',$value)!==1){
                $value=['deduplicate_binary_base64'=>base64_encode($value)];
            }
        }
        unset($value);
        return $row;
    }

    public static function plan(PDO $pdo): array {
        $groups=[];
        $rows=$pdo->query('SELECT id,link,guid,title,is_favorite,is_read,id_feed,tags,attributes,lastSeen,lastModified,lastUserModified,length(content) AS content_bytes FROM entry ORDER BY id');
        while($r=$rows->fetch(PDO::FETCH_ASSOC)){
            if($r['link']==='')continue;
            $key=DeduplicateUrl::canonical($r['link']);
            $groups[$key][]=$r;
        }
        $out=[];
        foreach($groups as $key=>$members){
            if(count($members)<2)continue;
            usort($members,static function($a,$b) use($pdo):int {
                // Saved and labelled rows take priority; ties prefer rich content.
                $labelCount=static function($id)use($pdo):int{$s=$pdo->prepare('SELECT COUNT(*) FROM entrytag WHERE id_entry=:id');$s->execute([':id'=>$id]);return (int)$s->fetchColumn();};
                return ((int)$b['is_favorite']<=>(int)$a['is_favorite'])
                    ?:($labelCount($b['id'])<=>$labelCount($a['id']))
                    ?:((int)$b['content_bytes']<=>(int)$a['content_bytes'])
                    ?:((int)$a['id']<=>(int)$b['id']);
            });
            foreach($members as &$member){
                $full=$pdo->prepare('SELECT * FROM entry WHERE id=:id');$full->execute([':id'=>$member['id']]);
                $labels=$pdo->prepare('SELECT id_tag FROM entrytag WHERE id_entry=:id ORDER BY id_tag');$labels->execute([':id'=>$member['id']]);
                $member['state_digest']=hash('sha256',serialize([$full->fetch(PDO::FETCH_ASSOC),$labels->fetchAll(PDO::FETCH_COLUMN)]));
            }
            unset($member);
            $out[]=['canonical_url'=>$key,'keeper_id'=>(string)$members[0]['id'],'delete_ids'=>array_map(static fn($r)=>(string)$r['id'],array_slice($members,1)),'members'=>$members];
        }
        // State fields participate: a read/favourite/metadata change invalidates dry run.
        $fingerprint=hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
        return ['groups'=>$out,'surplus_rows'=>array_sum(array_map(static fn($g)=>count($g['delete_ids']),$out)),'fingerprint'=>$fingerprint];
    }

    public static function apply(PDO $pdo,string $expected):array {
        if($pdo->inTransaction())throw new RuntimeException('Maintenance requires its own transaction');
        $pdo->exec('BEGIN IMMEDIATE');
        try{
            $plan=self::plan($pdo);
            if(!hash_equals($plan['fingerprint'],$expected))throw new UnexpectedValueException('Cleanup plan changed; rerun dry-run');
            $deleted=0;
            foreach($plan['groups'] as $group){
                $s=$pdo->prepare('SELECT * FROM entry WHERE id=:id');
                $s->execute([':id'=>$group['keeper_id']]);$keeper=$s->fetch(PDO::FETCH_ASSOC);
                $attributes=json_decode($keeper['attributes']?:'{}',true,flags:JSON_THROW_ON_ERROR);
                if(!is_array($attributes))throw new RuntimeException('Non-object entry attributes');
                // Preserve pre-merge keeper tags/content/state too; FreshRSS's own
                // _tags() parser splits commas, but no original spelling is lost.
                if(!array_key_exists('deduplicate_keeper_original',$attributes)){
                    $attributes['deduplicate_keeper_original']=self::archiveRow($keeper);
                }
                $archive=$attributes['deduplicate_merged_sources']??[];
                if(!is_array($archive))throw new RuntimeException('Invalid existing source archive');
                $tags=preg_split('/\s*[#,]\s*/',$keeper['tags']??'',-1,PREG_SPLIT_NO_EMPTY)?:[];
                foreach($group['delete_ids'] as $id){
                    $s->execute([':id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
                    if(!$row)throw new RuntimeException('Missing planned entry');
                    // Archive full original duplicate, including alternate text and attributes.
                    $archive[]=self::archiveRow($row);
                    $tags=array_merge($tags,preg_split('/\s*[#,]\s*/',$row['tags']??'',-1,PREG_SPLIT_NO_EMPTY)?:[]);
                    $keeper['is_favorite']=max((int)$keeper['is_favorite'],(int)$row['is_favorite']);
                    $keeper['is_read']=min((int)$keeper['is_read'],(int)$row['is_read']);
                    foreach(['lastSeen','lastModified','lastUserModified'] as $field)$keeper[$field]=max((int)($keeper[$field]??0),(int)($row[$field]??0));
                    // Preserve richer copy visibly without changing keeper URL/GUID/title.
                    if(strlen($row['content']??'')>strlen($keeper['content']??'')){
                        $archive[]=self::archiveRow(['preserved_keeper_content'=>$keeper['content']??'']);
                        $keeper['content']=$row['content'];
                    }
                    $labels=$pdo->prepare('INSERT OR IGNORE INTO entrytag(id_tag,id_entry) SELECT id_tag,:keeper FROM entrytag WHERE id_entry=:old');
                    $labels->execute([':keeper'=>$group['keeper_id'],':old'=>$id]);
                    $pdo->prepare('DELETE FROM entrytag WHERE id_entry=:id')->execute([':id'=>$id]);
                    $pdo->prepare('DELETE FROM entry WHERE id=:id')->execute([':id'=>$id]);
                    $deleted++;
                }
                $attributes['deduplicate_merged_sources']=$archive;
                $tags=array_values(array_unique($tags));
                $update=$pdo->prepare('UPDATE entry SET is_read=:read,is_favorite=:fav,tags=:tags,attributes=:attrs,content=:content,lastSeen=:seen,lastModified=:modified,lastUserModified=:user_modified WHERE id=:id');
                $update->execute([':read'=>$keeper['is_read'],':fav'=>$keeper['is_favorite'],':tags'=>$tags?'#'.implode(' #',$tags):'',
                    ':attrs'=>json_encode($attributes,JSON_THROW_ON_ERROR),':content'=>$keeper['content'],':seen'=>$keeper['lastSeen'],':modified'=>$keeper['lastModified'],
                    ':user_modified'=>max(time(),(int)$keeper['lastUserModified']),':id'=>$group['keeper_id']]);
            }
            // Same derived values as FreshRSS FeedDAOSQLite::updateCachedValues().
            $pdo->exec('UPDATE feed SET cache_nbEntries=(SELECT COUNT(*) FROM entry WHERE id_feed=feed.id),cache_nbUnreads=(SELECT COUNT(*) FROM entry WHERE id_feed=feed.id AND is_read=0)');
            $pdo->exec('COMMIT');
            return ['deleted'=>$deleted,'groups'=>count($plan['groups']),'fingerprint'=>$plan['fingerprint']];
        }catch(Throwable $e){$pdo->exec('ROLLBACK');throw $e;}
    }
}
