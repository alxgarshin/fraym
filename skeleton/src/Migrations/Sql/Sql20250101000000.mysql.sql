DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_ip` varchar(40) DEFAULT NULL,
  `address` text,
  `obj_type` varchar(25) DEFAULT NULL,
  `obj_id` int(11) DEFAULT NULL,
  `action` varchar(25) DEFAULT NULL,
  `action_obj_type` varchar(25) DEFAULT NULL,
  `action_obj_id` varchar(100) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `obj_type` (`obj_type`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


DROP TABLE IF EXISTS `article`;
CREATE TABLE `article` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `parent` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `annotation` text,
  `content` longtext,
  `attachments` text,
  `active` enum('0','1') NOT NULL DEFAULT '0',
  `nocomments` enum('0','1') NOT NULL DEFAULT '0',
  `tags` text,
  `created_at` int(11) DEFAULT NULL,
  `deleted_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parent` (`parent`),
  KEY `active` (`active`),
  FULLTEXT KEY `tags` (`tags`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;



DROP TABLE IF EXISTS `news`;
CREATE TABLE `news` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `author` varchar(255) DEFAULT NULL,
  `show_date` datetime DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `active` enum('0','1') NOT NULL DEFAULT '0',
  `annotation` text,
  `quote` text,
  `content` longtext,
  `attachments` text,
  `attachments2` text,
  `data_came_from` text,
  `tags` text,
  `created_at` int(11) DEFAULT NULL,
  `deleted_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `active` (`active`),
  KEY `type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `regstamp`;
CREATE TABLE `regstamp` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `hash` varchar(255) DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;



DROP TABLE IF EXISTS `relation`;
CREATE TABLE `relation` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `obj_type_from` varchar(25) DEFAULT NULL,
  `obj_id_from` int(11) DEFAULT NULL,
  `type` varchar(25) DEFAULT NULL,
  `obj_type_to` varchar(25) DEFAULT NULL,
  `obj_id_to` int(11) DEFAULT NULL,
  `comment` text,
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `obj_type_to` (`obj_type_to`),
  KEY `obj_type_from` (`obj_type_from`),
  KEY `obj_id_from` (`obj_id_from`),
  KEY `obj_id_to` (`obj_id_to`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `subscription`;
CREATE TABLE `subscription` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `author_name` varchar(255) DEFAULT NULL,
  `author_email` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `content` text,
  `obj_type` varchar(50) DEFAULT NULL,
  `obj_id` int(11) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `obj_type` (`obj_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `subscription_push`;
CREATE TABLE `subscription_push` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message_img` varchar(255) DEFAULT NULL,
  `header` varchar(255) DEFAULT NULL,
  `content` varchar(255) DEFAULT NULL,
  `obj_type` varchar(50) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `obj_type` (`obj_type`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `tag`;
CREATE TABLE `tag` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `creator_id` int(11) DEFAULT NULL,
  `parent` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `content` varchar(6) DEFAULT NULL,
  `code` int(11) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `deleted_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `code` (`code`),
  KEY `parent` (`parent`),
  KEY `content` (`content`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sid` int(11) DEFAULT NULL,
  `login` varchar(255) DEFAULT NULL,
  `password_hashed` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `em` varchar(255) DEFAULT NULL,
  `em_verified` enum('0','1') NOT NULL DEFAULT '0',
  `phone` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bazecount` int(11) DEFAULT NULL,
  `subs_type` int(11) DEFAULT NULL,
  `subs_objects` text,
  `rights` varchar(255) DEFAULT NULL,
  `agreement` enum('0','1') NOT NULL DEFAULT '0',
  `block_save_referer` enum('0','1') NOT NULL DEFAULT '0',
  `block_auto_redirect` enum('0','1') NOT NULL DEFAULT '0',
  `last_activity` int(11) DEFAULT NULL,
  `refresh_token` text,
  `refresh_token_exp` timestamp NULL DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `deleted_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sid` (`sid`) USING BTREE,
  KEY `subs_type` (`subs_type`),
  FULLTEXT KEY `user_refresh_token_IDX` (`refresh_token`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

DROP TABLE IF EXISTS `user__push_subscriptions`;
CREATE TABLE `user__push_subscriptions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `device_id` varchar(64) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `content_encoding` varchar(32) DEFAULT 'aesgcm',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_endpoint` (`endpoint`(255)),
  UNIQUE KEY `uniq_user_device` (`user_id`,`device_id`),
  CONSTRAINT `user__push_subscriptions_user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;

INSERT INTO tag (creator_id,parent,name,content,code,updated_at,created_at) VALUES
    (1,NULL,'Tag',NULL,1,1213954236,1213954236);

INSERT INTO `user` (sid,login,password_hashed,full_name,em,em_verified,bazecount,subs_type,subs_objects,rights,agreement,block_save_referer,block_auto_redirect,created_at,updated_at) VALUES
    (1,'admin@fraym.loc','$argon2id$v=19$m=131072,t=3,p=1$RHlIYkZSNkhOcWhSMEJOdA$v4sEEExvnM+rIFE8WZIQa0n0lyCrn3bgDEAoWnJBFUs','Админ','admin@fraym.loc','1',50,1,'-{conversation}-','-admin-help-','1','0','0',1758395113,1758310785);