<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/src/bootstrap.php';
db()->exec("CREATE TABLE IF NOT EXISTS visit_sessions (id TEXT PRIMARY KEY,started_at INTEGER NOT NULL,last_at INTEGER NOT NULL,source TEXT NOT NULL,medium TEXT NOT NULL,ad_campaign TEXT NOT NULL,ad TEXT NOT NULL,ref TEXT NOT NULL,param TEXT NOT NULL,referrer_domain TEXT NOT NULL,language TEXT NOT NULL,landing TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,engaged INTEGER NOT NULL DEFAULT 0,outbound INTEGER NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS visits_started ON visit_sessions(started_at);
CREATE TABLE IF NOT EXISTS visit_actions (id INTEGER PRIMARY KEY AUTOINCREMENT,visit_id TEXT NOT NULL REFERENCES visit_sessions(id),at INTEGER NOT NULL,kind TEXT NOT NULL,page TEXT NOT NULL,language TEXT NOT NULL,target TEXT NOT NULL DEFAULT '',placement TEXT NOT NULL DEFAULT '',provider TEXT NOT NULL DEFAULT '');
CREATE INDEX IF NOT EXISTS actions_visit ON visit_actions(visit_id,at);");
echo "Analytics migration complete\n";
