<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
final class MigrationRunner
{
    /** DDL is not atomic in MariaDB. Failed/unfinished files require reviewed recovery. */
    public static function run(string $directory, ?string $baselineThrough = null): array
    {
        $lock='frweb:migrations:'.substr(hash('sha256',(string)Db::value('SELECT DATABASE()')),0,40);
        if((int)Db::value('SELECT GET_LOCK(?,0)',[$lock])!==1){throw new RuntimeException('Another migration process is running.');}
        try{
            $existingApp=(bool)Db::value("SHOW TABLES LIKE 'customer_profiles'");
            $hadLedger=(bool)Db::value("SHOW TABLES LIKE 'schema_migrations'");
            Db::exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, state ENUM("applying","applied","failed") NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL) ENGINE=InnoDB');
            $files=glob(rtrim($directory,'/').'/*.sql');sort($files);
            if($baselineThrough!==null && !in_array($baselineThrough,array_map('basename',$files),true)){throw new RuntimeException('Unknown baseline migration.');}
            if($existingApp && !$hadLedger && $baselineThrough===null){throw new RuntimeException('Existing schema requires an explicitly reviewed baseline; earlier migrations will not be replayed.');}
            // An empty ledger after a refused legacy invocation still requires a baseline.
            if($existingApp && !(int)Db::value('SELECT COUNT(*) FROM schema_migrations') && $baselineThrough===null){throw new RuntimeException('Existing schema requires a reviewed baseline.');}
            $applied=[];$skipped=[];
            foreach($files as $file){
                $name=basename($file);$checksum=hash_file('sha256',$file);
                $row=Db::row('SELECT * FROM schema_migrations WHERE filename=?',[$name]);
                if($row){
                    if($row['checksum']!==$checksum){throw new RuntimeException('Migration checksum changed: '.$name);}
                    if($row['state']!=='applied'){throw new RuntimeException('Migration is unfinished/failed; review partial DDL before recovery: '.$name);}
                    $skipped[]=$name;continue;
                }
                if($baselineThrough!==null && strcmp($name,$baselineThrough)<=0){
                    Db::exec('INSERT INTO schema_migrations (filename,checksum,state,started_at,finished_at) VALUES (?,?,"applied",NOW(),NOW())',[$name,$checksum]);$skipped[]=$name;continue;
                }
                Db::exec('INSERT INTO schema_migrations (filename,checksum,state,started_at) VALUES (?, ?, "applying", NOW())',[$name,$checksum]);
                try{
                    $sql=file_get_contents($file);
                    foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\R|$)/',$sql))) as $statement){Db::pdo()->exec($statement);}
                    Db::exec('UPDATE schema_migrations SET state="applied",finished_at=NOW() WHERE filename=?',[$name]);$applied[]=$name;
                }catch(\Throwable $e){Db::exec('UPDATE schema_migrations SET state="failed",finished_at=NOW() WHERE filename=?',[$name]);throw $e;}
            }
            return ['applied'=>$applied,'skipped'=>$skipped];
        }finally{Db::value('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
