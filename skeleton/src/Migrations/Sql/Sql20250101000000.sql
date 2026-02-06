-- Включаем расширение для генерации UUID
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ----------------------------
-- Table structure for activity_log
-- ----------------------------
DROP TABLE IF EXISTS "activity_log";
CREATE TABLE "activity_log" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "user_id" UUID DEFAULT NULL,
  "user_ip" VARCHAR(40) DEFAULT NULL,
  "address" TEXT,
  "obj_type" VARCHAR(25) DEFAULT NULL,
  "obj_id" UUID DEFAULT NULL,
  "action" VARCHAR(25) DEFAULT NULL,
  "action_obj_type" VARCHAR(25) DEFAULT NULL,
  "action_obj_id" UUID DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_activity_log_user_id" ON "activity_log" ("user_id");
CREATE INDEX "idx_activity_log_obj_type" ON "activity_log" ("obj_type");
CREATE INDEX "idx_activity_log_action" ON "activity_log" ("action");

-- ----------------------------
-- Table structure for article
-- ----------------------------
DROP TABLE IF EXISTS "article";
CREATE TABLE "article" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "code" VARCHAR(255) DEFAULT NULL,
  "parent" UUID DEFAULT NULL,
  "name" VARCHAR(255) DEFAULT NULL,
  "author" VARCHAR(255) DEFAULT NULL,
  "annotation" TEXT,
  "content" TEXT,
  "attachments" TEXT,
  "active" BOOLEAN NOT NULL DEFAULT FALSE,
  "nocomments" BOOLEAN NOT NULL DEFAULT FALSE,
  "tags" TEXT,
  "created_at" BIGINT DEFAULT NULL,
  "deleted_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_article_parent" ON "article" ("parent");
CREATE INDEX "idx_article_active" ON "article" ("active");

-- ----------------------------
-- Table structure for news
-- ----------------------------
DROP TABLE IF EXISTS "news";
CREATE TABLE "news" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "type" SMALLINT DEFAULT NULL,
  "name" VARCHAR(255) DEFAULT NULL,
  "author" VARCHAR(255) DEFAULT NULL,
  "show_date" TIMESTAMP DEFAULT NULL,
  "from_date" DATE DEFAULT NULL,
  "to_date" DATE DEFAULT NULL,
  "active" BOOLEAN NOT NULL DEFAULT FALSE,
  "annotation" TEXT,
  "quote" TEXT,
  "content" TEXT,
  "attachments" TEXT,
  "attachments2" TEXT,
  "data_came_from" TEXT,
  "tags" TEXT,
  "created_at" BIGINT DEFAULT NULL,
  "deleted_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_news_active" ON "news" ("active");
CREATE INDEX "idx_news_type" ON "news" ("type");

-- ----------------------------
-- Table structure for regstamp
-- ----------------------------
DROP TABLE IF EXISTS "regstamp";
CREATE TABLE "regstamp" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "hash" VARCHAR(255) DEFAULT NULL,
  "code" VARCHAR(255) DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

-- ----------------------------
-- Table structure for relation
-- ----------------------------
DROP TABLE IF EXISTS "relation";
CREATE TABLE "relation" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "obj_type_from" VARCHAR(25) DEFAULT NULL,
  "obj_id_from" UUID DEFAULT NULL,
  "type" VARCHAR(25) DEFAULT NULL,
  "obj_type_to" VARCHAR(25) DEFAULT NULL,
  "obj_id_to" UUID DEFAULT NULL,
  "comment" TEXT,
  "created_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_relation_type" ON "relation" ("type");
CREATE INDEX "idx_relation_obj_type_to" ON "relation" ("obj_type_to");
CREATE INDEX "idx_relation_obj_type_from" ON "relation" ("obj_type_from");
CREATE INDEX "idx_relation_obj_id_from" ON "relation" ("obj_id_from");
CREATE INDEX "idx_relation_obj_id_to" ON "relation" ("obj_id_to");

-- ----------------------------
-- Table structure for subscription
-- ----------------------------
DROP TABLE IF EXISTS "subscription";
CREATE TABLE "subscription" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "author_name" VARCHAR(255) DEFAULT NULL,
  "author_email" VARCHAR(255) DEFAULT NULL,
  "user_id" UUID DEFAULT NULL,
  "name" VARCHAR(255) DEFAULT NULL,
  "content" TEXT,
  "obj_type" VARCHAR(50) DEFAULT NULL,
  "obj_id" UUID DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_subscription_user_id" ON "subscription" ("user_id");
CREATE INDEX "idx_subscription_obj_type" ON "subscription" ("obj_type");

-- ----------------------------
-- Table structure for subscription_push
-- ----------------------------
DROP TABLE IF EXISTS "subscription_push";
CREATE TABLE "subscription_push" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "user_id" UUID DEFAULT NULL,
  "message_img" VARCHAR(255) DEFAULT NULL,
  "header" VARCHAR(255) DEFAULT NULL,
  "content" VARCHAR(255) DEFAULT NULL,
  "obj_type" VARCHAR(50) DEFAULT NULL,
  "obj_id" UUID DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_subscription_push_user_id" ON "subscription_push" ("user_id");
CREATE INDEX "idx_subscription_push_obj_type" ON "subscription_push" ("obj_type");

-- ----------------------------
-- Table structure for tag
-- ----------------------------
DROP TABLE IF EXISTS "tag";
CREATE TABLE "tag" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "creator_id" UUID DEFAULT NULL,
  "parent" UUID DEFAULT NULL,
  "name" VARCHAR(255) DEFAULT NULL,
  "content" VARCHAR(6) DEFAULT NULL,
  "code" INTEGER DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL,
  "deleted_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL
);

CREATE INDEX "idx_tag_code" ON "tag" ("code");
CREATE INDEX "idx_tag_parent" ON "tag" ("parent");
CREATE INDEX "idx_tag_content" ON "tag" ("content");

-- ----------------------------
-- Table structure for user
-- ----------------------------
DROP TABLE IF EXISTS "user";
CREATE TABLE "user" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "sid" INTEGER DEFAULT NULL,
  "login" VARCHAR(255) DEFAULT NULL,
  "password_hashed" VARCHAR(255) DEFAULT NULL,
  "full_name" VARCHAR(255) DEFAULT NULL,
  "em" VARCHAR(255) DEFAULT NULL,
  "em_verified" BOOLEAN NOT NULL DEFAULT FALSE,
  "phone" VARCHAR(255) DEFAULT NULL,
  "google_id" VARCHAR(255) DEFAULT NULL,
  "avatar" VARCHAR(255) DEFAULT NULL,
  "bazecount" INTEGER DEFAULT NULL,
  "subs_type" INTEGER DEFAULT NULL,
  "subs_objects" TEXT,
  "rights" VARCHAR(255) DEFAULT NULL,
  "agreement" BOOLEAN NOT NULL DEFAULT FALSE,
  "block_save_referer" BOOLEAN NOT NULL DEFAULT FALSE,
  "block_auto_redirect" BOOLEAN NOT NULL DEFAULT FALSE,
  "last_activity" BIGINT DEFAULT NULL,
  "refresh_token" TEXT,
  "refresh_token_exp" TIMESTAMP DEFAULT NULL,
  "created_at" BIGINT DEFAULT NULL,
  "deleted_at" BIGINT DEFAULT NULL,
  "updated_at" BIGINT DEFAULT NULL,
  CONSTRAINT "uniq_user_sid" UNIQUE ("sid")
);

CREATE INDEX "idx_user_subs_type" ON "user" ("subs_type");
CREATE INDEX "idx_user_refresh_token_gin" ON "user" USING GIN (to_tsvector('english', "refresh_token"));

-- ----------------------------
-- Table structure for user__push_subscriptions
-- ----------------------------
DROP TABLE IF EXISTS "user__push_subscriptions";
CREATE TABLE "user__push_subscriptions" (
  "id" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "user_id" UUID DEFAULT NULL,
  "device_id" VARCHAR(64) NOT NULL,
  "endpoint" TEXT NOT NULL,
  "p256dh" VARCHAR(255) NOT NULL,
  "auth" VARCHAR(255) NOT NULL,
  "content_encoding" VARCHAR(32) DEFAULT 'aesgcm',
  "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT "uniq_endpoint" UNIQUE ("endpoint"),
  CONSTRAINT "uniq_user_device" UNIQUE ("user_id", "device_id"),
  CONSTRAINT "fk_user__push_subscriptions_user_id" FOREIGN KEY ("user_id") REFERENCES "user" ("id") ON DELETE CASCADE
);