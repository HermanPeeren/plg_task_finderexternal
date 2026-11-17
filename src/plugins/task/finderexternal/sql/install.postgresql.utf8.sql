CREATE TABLE IF NOT EXISTS "#__finderexternal_urls" (
  "id" serial NOT NULL,
  "task_id" bigint NOT NULL,
  "url" varchar(2048) NOT NULL,
  "url_hash" char(40) NOT NULL,
  "depth" smallint NOT NULL DEFAULT 0,
  "in_sitemap" smallint NOT NULL DEFAULT 0,
  "seen_cycle" bigint NOT NULL DEFAULT 0,
  "checked_cycle" bigint NOT NULL DEFAULT 0,
  "status" varchar(20) NOT NULL DEFAULT 'new',
  "link_id" bigint NOT NULL DEFAULT 0,
  "content_hash" char(40) NOT NULL DEFAULT '',
  "etag" varchar(255) NOT NULL DEFAULT '',
  "last_modified" varchar(64) NOT NULL DEFAULT '',
  "last_checked" timestamp without time zone NULL DEFAULT NULL,
  "message" varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY ("id"),
  CONSTRAINT "#__finderexternal_urls_idx_task_url" UNIQUE ("task_id", "url_hash")
);

CREATE INDEX IF NOT EXISTS "#__finderexternal_urls_idx_task_due" ON "#__finderexternal_urls" ("task_id", "checked_cycle", "depth");
CREATE INDEX IF NOT EXISTS "#__finderexternal_urls_idx_link" ON "#__finderexternal_urls" ("link_id");

CREATE TABLE IF NOT EXISTS "#__finderexternal_cycles" (
  "task_id" bigint NOT NULL,
  "cycle" bigint NOT NULL DEFAULT 0,
  "phase" varchar(20) NOT NULL DEFAULT 'discover',
  "state" text NOT NULL,
  "started" timestamp without time zone NOT NULL,
  "finished" timestamp without time zone NULL DEFAULT NULL,
  PRIMARY KEY ("task_id")
);
