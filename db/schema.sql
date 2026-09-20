-- LG Transparency Spend — schema dump 2026-09-20
-- Load order note: tables are dumped alphabetically, so foreign-key targets and
-- the ct_spend_display view (moved to the end) may appear after their dependants.
-- FK checks are disabled during load and re-enabled at the end (standard for dumps).
SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ============================ ct_api_keys ============================
CREATE TABLE `ct_api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key_prefix` char(12) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `owner_name` varchar(120) NOT NULL,
  `owner_email` varchar(190) DEFAULT NULL,
  `rate_per_min` int(10) unsigned NOT NULL DEFAULT 60,
  `rate_per_day` int(10) unsigned NOT NULL DEFAULT 20000,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_api_log ============================
CREATE TABLE `ct_api_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key_id` int(10) unsigned NOT NULL,
  `ts` datetime NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `method` varchar(8) DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL,
  `status` smallint(6) DEFAULT NULL,
  `ms` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_key_ts` (`key_id`,`ts`)
) ENGINE=InnoDB AUTO_INCREMENT=184 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_blocked_councils ============================
CREATE TABLE `ct_blocked_councils` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `council_name` varchar(200) NOT NULL,
  `reason` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `checked_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_council` (`council_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_ca_config ============================
CREATE TABLE `ct_ca_config` (
  `ca_name` varchar(100) NOT NULL,
  `gss_code` varchar(10) DEFAULT NULL,
  `ca_type` varchar(50) NOT NULL DEFAULT '',
  `region` varchar(50) NOT NULL DEFAULT '',
  `encoding` enum('utf8','utf16le','utf16be','win1252') NOT NULL DEFAULT 'utf8',
  `col_supplier` tinyint(4) NOT NULL DEFAULT 0,
  `col_amount` tinyint(4) NOT NULL DEFAULT 1,
  `col_date` tinyint(4) NOT NULL DEFAULT 2,
  `col_service` tinyint(4) NOT NULL DEFAULT -1,
  `skip_rows` tinyint(4) NOT NULL DEFAULT 1,
  `col_category` tinyint(4) NOT NULL DEFAULT -1,
  `category_filter` varchar(200) NOT NULL DEFAULT '',
  `notes` varchar(300) DEFAULT NULL,
  `listing_url` varchar(500) DEFAULT NULL,
  `cloudflare_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ca_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_ca_spend ============================
CREATE TABLE `ct_ca_spend` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ca_name` varchar(100) NOT NULL,
  `supplier_raw` varchar(300) NOT NULL,
  `supplier_canon` varchar(200) NOT NULL,
  `service` varchar(200) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `paid_date` date DEFAULT NULL,
  `period` varchar(7) NOT NULL,
  `source_id` int(10) unsigned DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ca` (`ca_name`(80)),
  KEY `idx_supplier` (`supplier_canon`(100)),
  KEY `idx_period` (`period`)
) ENGINE=InnoDB AUTO_INCREMENT=24330 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_ca_spend_sources ============================
CREATE TABLE `ct_ca_spend_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ca_name` varchar(100) NOT NULL,
  `url` varchar(500) NOT NULL,
  `period` varchar(7) NOT NULL,
  `format` enum('csv','xlsx','post_csv','pdf') NOT NULL DEFAULT 'csv',
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `last_fetched` datetime DEFAULT NULL,
  `last_count` int(11) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ca_period` (`ca_name`(80),`period`)
) ENGINE=InnoDB AUTO_INCREMENT=585 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_contract_register_entries ============================
CREATE TABLE `ct_contract_register_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `council` varchar(100) NOT NULL,
  `supplier` varchar(500) NOT NULL,
  `supplier_canon` varchar(300) DEFAULT NULL,
  `contract_title` varchar(500) DEFAULT NULL,
  `contract_ref` varchar(100) DEFAULT NULL,
  `department` varchar(200) DEFAULT NULL,
  `value_amount` decimal(18,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `classification` varchar(100) DEFAULT NULL,
  `tender_process` varchar(100) DEFAULT NULL,
  `is_tech` tinyint(4) NOT NULL DEFAULT 0,
  `source_id` int(10) unsigned DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  `lgam_layer` varchar(60) DEFAULT NULL,
  `lgam_sublayer` varchar(60) DEFAULT NULL,
  `lgam_sublayer_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_council` (`council`),
  KEY `idx_supplier` (`supplier_canon`(100)),
  KEY `idx_end_date` (`end_date`),
  KEY `idx_tech` (`is_tech`,`council`),
  KEY `idx_grid` (`council`,`is_tech`,`value_amount`,`start_date`,`end_date`)
) ENGINE=InnoDB AUTO_INCREMENT=458840 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_contract_registers ============================
CREATE TABLE `ct_contract_registers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `council` varchar(100) NOT NULL,
  `url` varchar(500) NOT NULL,
  `format` enum('csv','xlsx') NOT NULL DEFAULT 'csv',
  `encoding` enum('utf8','utf16le','win1252') NOT NULL DEFAULT 'utf8',
  `col_supplier` tinyint(4) NOT NULL DEFAULT 0,
  `col_title` tinyint(4) NOT NULL DEFAULT 1,
  `col_value` tinyint(4) NOT NULL DEFAULT 2,
  `col_start` tinyint(4) NOT NULL DEFAULT 3,
  `col_end` tinyint(4) NOT NULL DEFAULT 4,
  `col_dept` tinyint(4) NOT NULL DEFAULT -1,
  `col_ref` tinyint(4) NOT NULL DEFAULT -1,
  `col_tender_process` tinyint(4) NOT NULL DEFAULT -1,
  `skip_rows` tinyint(4) NOT NULL DEFAULT 1,
  `last_fetched` datetime DEFAULT NULL,
  `last_count` int(11) DEFAULT NULL,
  `listing_url` varchar(500) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `retrieval_method` text DEFAULT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `not_pollable` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_council` (`council`)
) ENGINE=InnoDB AUTO_INCREMENT=377 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_contracts ============================
CREATE TABLE `ct_contracts` (
  `ocid` varchar(120) NOT NULL,
  `title` text DEFAULT NULL,
  `buyer_name` varchar(255) DEFAULT NULL,
  `buyer_lad_code` varchar(10) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `stage` varchar(30) DEFAULT NULL,
  `value_amount` decimal(15,2) DEFAULT NULL,
  `awarded_amount` decimal(15,2) DEFAULT NULL,
  `value_currency` varchar(5) DEFAULT NULL,
  `cpv_division` varchar(5) DEFAULT NULL,
  `cpv_codes` varchar(200) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `published_date` date DEFAULT NULL,
  `tender_end_date` date DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `official_url` text DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL,
  `supplier_names` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `lgam_layer` varchar(60) DEFAULT NULL,
  `lgam_sublayer` varchar(60) DEFAULT NULL,
  `lgam_sublayer_name` varchar(100) DEFAULT NULL,
  `fetched_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ocid`),
  KEY `idx_buyer` (`buyer_name`(100)),
  KEY `idx_status` (`status`),
  KEY `idx_cpv` (`cpv_division`),
  KEY `idx_published` (`published_date`),
  KEY `idx_last_updated` (`last_updated`),
  KEY `idx_lgam_layer` (`lgam_layer`),
  KEY `idx_source` (`source`),
  KEY `idx_ct_buyer_lad` (`buyer_lad_code`),
  FULLTEXT KEY `ft_search` (`title`,`buyer_name`,`description`),
  FULLTEXT KEY `ft_supplier_names` (`supplier_names`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_council_ca ============================
CREATE TABLE `ct_council_ca` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lad_code` varchar(9) NOT NULL,
  `ca_name` varchar(100) NOT NULL,
  `partner_type` varchar(30) NOT NULL DEFAULT 'Constituent partner',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lad_ca` (`lad_code`,`ca_name`),
  KEY `k_ca` (`ca_name`)
) ENGINE=InnoDB AUTO_INCREMENT=475 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_council_config ============================
CREATE TABLE `ct_council_config` (
  `council` varchar(100) NOT NULL,
  `lad_code` varchar(9) DEFAULT NULL COMMENT 'ONS LAD25CD code, https://www.arcgis.com Local_Authority_Districts_DEC_2025_Boundaries_UK_BFC',
  `council_type` varchar(50) NOT NULL DEFAULT '',
  `region` varchar(50) NOT NULL DEFAULT '',
  `combined_authority` varchar(80) NOT NULL DEFAULT 'No current combined authority',
  `population` int(10) unsigned DEFAULT NULL COMMENT 'ONS mid-year population estimate, for spend-per-person calc',
  `population_year` smallint(6) DEFAULT NULL COMMENT 'Year the population estimate is for',
  `encoding` enum('utf8','utf16le','utf16be','win1252') NOT NULL DEFAULT 'utf8',
  `col_supplier` tinyint(4) NOT NULL DEFAULT 0,
  `col_amount` tinyint(4) NOT NULL DEFAULT 1,
  `col_date` tinyint(4) NOT NULL DEFAULT 2,
  `col_service` tinyint(4) NOT NULL DEFAULT -1,
  `skip_rows` tinyint(4) NOT NULL DEFAULT 1,
  `col_category` tinyint(4) NOT NULL DEFAULT -1,
  `category_filter` varchar(200) NOT NULL DEFAULT '',
  `notes` varchar(300) DEFAULT NULL,
  `listing_url` varchar(500) DEFAULT NULL COMMENT 'Page listing/linking to the periodic spend report files, for re-check agents',
  `cloudflare_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `not_pollable` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `use_source_cols` tinyint(4) NOT NULL DEFAULT 0,
  `quarterly_by_date` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`council`),
  UNIQUE KEY `idx_lad_code` (`lad_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_council_lineage ============================
CREATE TABLE `ct_council_lineage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pred_lad_code` varchar(9) DEFAULT NULL,
  `pred_name` varchar(150) NOT NULL,
  `succ_key` varchar(9) NOT NULL,
  `succ_name` varchar(150) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'proposed',
  `go_live_year` smallint(6) DEFAULT NULL,
  `edge_type` varchar(30) NOT NULL DEFAULT 'Merge/Split',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pred_succ` (`pred_lad_code`,`succ_key`),
  KEY `k_succ` (`succ_key`),
  KEY `k_pred` (`pred_lad_code`)
) ENGINE=InnoDB AUTO_INCREMENT=793 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_council_summary ============================
CREATE TABLE `ct_council_summary` (
  `lad_code` varchar(10) NOT NULL,
  `total_spend` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`lad_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_council_summary_fy ============================
CREATE TABLE `ct_council_summary_fy` (
  `lad_code` varchar(10) NOT NULL,
  `fy_year` smallint(6) NOT NULL,
  `total_spend` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `supplier_count` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lad_code`,`fy_year`),
  KEY `idx_fy_spend` (`fy_year`,`total_spend` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_cr_council_summary ============================
CREATE TABLE `ct_cr_council_summary` (
  `council` varchar(200) NOT NULL,
  `total` int(11) NOT NULL DEFAULT 0,
  `tech` int(11) NOT NULL DEFAULT 0,
  `tech_value` decimal(16,2) DEFAULT NULL,
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`council`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_la_geo ============================
CREATE TABLE `ct_la_geo` (
  `lad_code` varchar(9) NOT NULL,
  `lad_name` varchar(100) DEFAULT NULL,
  `lat` decimal(8,5) NOT NULL,
  `lon` decimal(8,5) NOT NULL,
  `source` varchar(30) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lad_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_la_reference ============================
CREATE TABLE `ct_la_reference` (
  `lad_code` varchar(9) NOT NULL,
  `net_revenue_exp` decimal(15,2) DEFAULT NULL,
  `budget_year` varchar(9) DEFAULT NULL,
  `imd_avg_score` decimal(6,2) DEFAULT NULL,
  `imd_rank` int(11) DEFAULT NULL,
  `imd_year` smallint(6) DEFAULT NULL,
  `imd_source` varchar(12) DEFAULT NULL,
  `imd_pct_q1` decimal(5,2) DEFAULT NULL,
  `imd_avg_rank` decimal(10,1) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`lad_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_lgam_app_types ============================
CREATE TABLE `ct_lgam_app_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(60) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `label` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` tinyint(3) unsigned DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_node_slug` (`node_id`,`slug`),
  KEY `idx_node` (`node_id`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_lgam_entry_products ============================
CREATE TABLE `ct_lgam_entry_products` (
  `entry_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`entry_id`,`product_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `ct_lgam_entry_products_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `ct_lgam_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_lgam_product_app_types ============================
CREATE TABLE `ct_lgam_product_app_types` (
  `product_id` int(10) unsigned NOT NULL,
  `app_type_id` int(10) unsigned NOT NULL,
  `is_primary` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`product_id`,`app_type_id`),
  KEY `idx_app_type` (`app_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_lgam_product_areas ============================
CREATE TABLE `ct_lgam_product_areas` (
  `product_id` int(10) unsigned NOT NULL,
  `node_id` varchar(60) NOT NULL,
  PRIMARY KEY (`product_id`,`node_id`),
  CONSTRAINT `ct_lgam_product_areas_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `ct_lgam_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_lgam_product_capabilities ============================
CREATE TABLE `ct_lgam_product_capabilities` (
  `product_id` int(10) unsigned NOT NULL,
  `node_id` varchar(60) NOT NULL,
  PRIMARY KEY (`product_id`,`node_id`),
  CONSTRAINT `ct_lgam_product_capabilities_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `ct_lgam_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_lgam_products ============================
CREATE TABLE `ct_lgam_products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_canon` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vendor` varchar(200) DEFAULT NULL,
  `company_number` varchar(8) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_product` (`supplier_canon`,`product_name`),
  KEY `idx_company` (`company_number`)
) ENGINE=InnoDB AUTO_INCREMENT=10283 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_service_keywords ============================
CREATE TABLE `ct_service_keywords` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `keyword` varchar(100) NOT NULL,
  `ruling` enum('valid','invalid') NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keyword` (`keyword`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_spend_sources ============================
CREATE TABLE `ct_spend_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `council` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL DEFAULT '',
  `lad_code` varchar(10) DEFAULT NULL,
  `url` varchar(500) NOT NULL,
  `period` varchar(7) NOT NULL COMMENT 'YYYY-MM',
  `format` enum('csv','xlsx','post_csv') NOT NULL DEFAULT 'csv',
  `encoding` enum('utf8','utf16le','utf16be','win1252') NOT NULL DEFAULT 'utf8',
  `col_supplier` tinyint(4) NOT NULL DEFAULT 0,
  `col_amount` tinyint(4) NOT NULL DEFAULT 1,
  `col_date` tinyint(4) NOT NULL DEFAULT 2,
  `col_service` tinyint(4) NOT NULL DEFAULT -1,
  `skip_rows` tinyint(4) NOT NULL DEFAULT 1,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `last_fetched` datetime DEFAULT NULL,
  `last_count` int(11) DEFAULT NULL COMMENT 'Tech payments found on last fetch',
  `notes` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_council_dept_period` (`council`,`department`,`period`),
  KEY `idx_ss_lad` (`lad_code`)
) ENGINE=InnoDB AUTO_INCREMENT=18798 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_supplier_patterns ============================
CREATE TABLE `ct_supplier_patterns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pattern` varchar(255) NOT NULL,
  `canonical_name` varchar(255) NOT NULL,
  `match_type` enum('substring','word_boundary') NOT NULL DEFAULT 'substring',
  `company_number` varchar(16) DEFAULT NULL,
  `confirmed_valid` tinyint(1) NOT NULL DEFAULT 0,
  `confirmed_invalid` tinyint(1) NOT NULL DEFAULT 0,
  `ruling_notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pattern` (`pattern`)
) ENGINE=InnoDB AUTO_INCREMENT=1801 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_supplier_service_exclusions ============================
CREATE TABLE `ct_supplier_service_exclusions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_pattern` varchar(100) NOT NULL,
  `service_keyword` varchar(100) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_svc` (`supplier_pattern`,`service_keyword`)
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_supplier_summary ============================
CREATE TABLE `ct_supplier_summary` (
  `canonical_name` varchar(200) NOT NULL,
  `total_spend` decimal(18,2) NOT NULL DEFAULT 0.00,
  `council_count` int(11) NOT NULL DEFAULT 0,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `contract_count` int(11) NOT NULL DEFAULT 0,
  `contract_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`canonical_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_supplier_summary_fy ============================
CREATE TABLE `ct_supplier_summary_fy` (
  `canonical_name` varchar(200) NOT NULL,
  `fy_year` smallint(6) NOT NULL COMMENT 'Start year of FY, e.g. 2025 = Apr 2025 to Mar 2026',
  `total_spend` decimal(18,2) NOT NULL DEFAULT 0.00,
  `council_count` int(11) NOT NULL DEFAULT 0,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`canonical_name`,`fy_year`),
  KEY `idx_fy_spend` (`fy_year`,`total_spend` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_supplier_summary_nation_fy ============================
CREATE TABLE `ct_supplier_summary_nation_fy` (
  `canonical_name` varchar(255) NOT NULL,
  `fy_year` smallint(6) NOT NULL,
  `lad_prefix` varchar(1) NOT NULL DEFAULT '',
  `council_count` int(11) NOT NULL DEFAULT 0,
  `payment_count` int(11) NOT NULL DEFAULT 0,
  `total_spend` decimal(15,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`canonical_name`,`fy_year`,`lad_prefix`),
  KEY `idx_snfy_fy_prefix` (`fy_year`,`lad_prefix`,`total_spend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_suppliers ============================
CREATE TABLE `ct_suppliers` (
  `company_number` varchar(16) DEFAULT NULL,
  `registry` varchar(4) NOT NULL DEFAULT 'uk',
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canonical_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `previous_names` text DEFAULT NULL,
  `sic_codes` varchar(100) DEFAULT NULL,
  `registered_address` text DEFAULT NULL,
  `incorporation_date` date DEFAULT NULL,
  `company_status` varchar(30) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `asc_platform` tinyint(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_company_number` (`company_number`),
  KEY `idx_canonical` (`canonical_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ ct_sync_state ============================
CREATE TABLE `ct_sync_state` (
  `k` varchar(50) NOT NULL,
  `v` text DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ ct_transparency_spend ============================
CREATE TABLE `ct_transparency_spend` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `council` varchar(60) NOT NULL,
  `lad_code` varchar(10) DEFAULT NULL,
  `supplier_raw` varchar(300) NOT NULL,
  `supplier_canon` varchar(60) NOT NULL,
  `internal_provider` tinyint(1) NOT NULL DEFAULT 0,
  `service` varchar(200) DEFAULT NULL,
  `category` varchar(200) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `paid_date` date DEFAULT NULL,
  `period` char(7) NOT NULL,
  `source_id` int(10) unsigned DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  `validation_status` enum('valid','invalid','uncertain') DEFAULT NULL,
  `validation_notes` varchar(600) DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_supplier` (`supplier_canon`),
  KEY `idx_council_period` (`council`,`period`),
  KEY `idx_ts_lad` (`lad_code`),
  KEY `idx_period_supplier_cover` (`period`,`supplier_canon`,`lad_code`,`amount`),
  KEY `idx_ip_council_date_amt` (`internal_provider`,`council`,`paid_date`,`amount`),
  KEY `idx_validation_status` (`validation_status`),
  KEY `idx_ip_lad_period_vs` (`internal_provider`,`lad_code`,`period`,`validation_status`,`supplier_canon`,`amount`),
  KEY `idx_lad_supplier_date_amt` (`lad_code`,`supplier_canon`,`paid_date`,`amount`),
  KEY `idx_ip_council_sup_cover` (`internal_provider`,`council`,`supplier_canon`,`amount`,`paid_date`,`validation_status`,`lad_code`)
) ENGINE=InnoDB AUTO_INCREMENT=6449006 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

-- ============================ lgam_users ============================
CREATE TABLE `lgam_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('editor','admin') NOT NULL DEFAULT 'editor',
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `invite_token` varchar(64) DEFAULT NULL,
  `invite_expiry` datetime DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;

-- ============================ lgam_versions ============================
CREATE TABLE `lgam_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `scope` enum('private','master') NOT NULL DEFAULT 'private',
  `data` longtext NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
;
-- (view moved to end: depends on ct_transparency_spend)
-- ============================ ct_spend_display ============================
CREATE OR REPLACE VIEW `ct_spend_display` AS select `ct_transparency_spend`.`council` AS `council`,`ct_transparency_spend`.`lad_code` AS `lad_code`,`ct_transparency_spend`.`amount` AS `amount`,`ct_transparency_spend`.`id` AS `id`,`ct_transparency_spend`.`supplier_raw` AS `supplier_raw`,`ct_transparency_spend`.`supplier_canon` AS `supplier_canon`,`ct_transparency_spend`.`internal_provider` AS `internal_provider`,`ct_transparency_spend`.`service` AS `service`,`ct_transparency_spend`.`category` AS `category`,`ct_transparency_spend`.`paid_date` AS `paid_date`,`ct_transparency_spend`.`period` AS `period`,`ct_transparency_spend`.`source_id` AS `source_id`,`ct_transparency_spend`.`fetched_at` AS `fetched_at`,`ct_transparency_spend`.`validation_status` AS `validation_status`,`ct_transparency_spend`.`validation_notes` AS `validation_notes`,`ct_transparency_spend`.`validated_at` AS `validated_at` from `ct_transparency_spend` where `ct_transparency_spend`.`council` <> 'Adur and Worthing Councils Joint' union all select 'Adur District Council' AS `council`,'E07000223' AS `lad_code`,`ct_transparency_spend`.`amount` / 2 AS `amount`,`ct_transparency_spend`.`id` AS `id`,`ct_transparency_spend`.`supplier_raw` AS `supplier_raw`,`ct_transparency_spend`.`supplier_canon` AS `supplier_canon`,`ct_transparency_spend`.`internal_provider` AS `internal_provider`,`ct_transparency_spend`.`service` AS `service`,`ct_transparency_spend`.`category` AS `category`,`ct_transparency_spend`.`paid_date` AS `paid_date`,`ct_transparency_spend`.`period` AS `period`,`ct_transparency_spend`.`source_id` AS `source_id`,`ct_transparency_spend`.`fetched_at` AS `fetched_at`,`ct_transparency_spend`.`validation_status` AS `validation_status`,`ct_transparency_spend`.`validation_notes` AS `validation_notes`,`ct_transparency_spend`.`validated_at` AS `validated_at` from `ct_transparency_spend` where `ct_transparency_spend`.`council` = 'Adur and Worthing Councils Joint' union all select 'Worthing Borough Council' AS `council`,'E07000229' AS `lad_code`,`ct_transparency_spend`.`amount` / 2 AS `amount`,`ct_transparency_spend`.`id` AS `id`,`ct_transparency_spend`.`supplier_raw` AS `supplier_raw`,`ct_transparency_spend`.`supplier_canon` AS `supplier_canon`,`ct_transparency_spend`.`internal_provider` AS `internal_provider`,`ct_transparency_spend`.`service` AS `service`,`ct_transparency_spend`.`category` AS `category`,`ct_transparency_spend`.`paid_date` AS `paid_date`,`ct_transparency_spend`.`period` AS `period`,`ct_transparency_spend`.`source_id` AS `source_id`,`ct_transparency_spend`.`fetched_at` AS `fetched_at`,`ct_transparency_spend`.`validation_status` AS `validation_status`,`ct_transparency_spend`.`validation_notes` AS `validation_notes`,`ct_transparency_spend`.`validated_at` AS `validated_at` from `ct_transparency_spend` where `ct_transparency_spend`.`council` = 'Adur and Worthing Councils Joint'
;


SET FOREIGN_KEY_CHECKS = 1;
