CREATE TABLE IF NOT EXISTS "#__sitemoveinspector_jobs" (
	"id" VARCHAR(36) NOT NULL,
	"user_id" BIGINT NOT NULL,
	"status" VARCHAR(32) NOT NULL,
	"state_json" TEXT NOT NULL,
	"report_json" TEXT NULL,
	"lock_token" VARCHAR(64) NULL,
	"locked_until" TIMESTAMP WITHOUT TIME ZONE NULL,
	"created_at" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
	"updated_at" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
	"expires_at" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
	CONSTRAINT "#__sitemoveinspector_jobs_pkey" PRIMARY KEY ("id")
);

CREATE INDEX IF NOT EXISTS "#__sitemoveinspector_jobs_user_status_idx"
	ON "#__sitemoveinspector_jobs" ("user_id", "status");

CREATE UNIQUE INDEX IF NOT EXISTS "#__sitemoveinspector_jobs_user_idx"
	ON "#__sitemoveinspector_jobs" ("user_id");

CREATE INDEX IF NOT EXISTS "#__sitemoveinspector_jobs_expires_at_idx"
	ON "#__sitemoveinspector_jobs" ("expires_at");

CREATE INDEX IF NOT EXISTS "#__sitemoveinspector_jobs_locked_until_idx"
	ON "#__sitemoveinspector_jobs" ("locked_until");
