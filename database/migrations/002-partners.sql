CREATE TABLE IF NOT EXISTS outbound_events(id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, provider TEXT NOT NULL, campaign TEXT NOT NULL, ref TEXT NOT NULL DEFAULT '', param TEXT NOT NULL, source TEXT NOT NULL DEFAULT '', page TEXT NOT NULL, placement TEXT NOT NULL, medium TEXT NOT NULL DEFAULT '', ad_campaign TEXT NOT NULL DEFAULT '', ad TEXT NOT NULL DEFAULT '');
CREATE INDEX IF NOT EXISTS outbound_created ON outbound_events(created_at);
CREATE INDEX IF NOT EXISTS outbound_attribution ON outbound_events(provider,campaign,ref,param);
