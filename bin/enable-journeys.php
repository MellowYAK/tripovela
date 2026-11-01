<?php
require dirname(__DIR__).'/src/bootstrap.php';
db()->beginTransaction();
db()->exec("CREATE TABLE IF NOT EXISTS page_events(id INTEGER PRIMARY KEY,created_at TEXT NOT NULL,page TEXT NOT NULL,ref TEXT NOT NULL DEFAULT '',param TEXT NOT NULL DEFAULT '',source TEXT NOT NULL DEFAULT '',medium TEXT NOT NULL DEFAULT '',ad_campaign TEXT NOT NULL DEFAULT '',ad TEXT NOT NULL DEFAULT '',referrer_domain TEXT NOT NULL DEFAULT '')");
db()->exec('CREATE INDEX IF NOT EXISTS page_events_created ON page_events(created_at)');
$columns=array_column(query('PRAGMA table_info(outbound_events)')->fetchAll(),'name');
if(!in_array('entry_id',$columns,true))db()->exec('ALTER TABLE outbound_events ADD COLUMN entry_id INTEGER');
db()->commit();
echo "Journey schema ready; existing data preserved.\n";
