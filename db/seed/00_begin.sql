-- Seed-load prelude.
-- Files load in alphabetical order, which does not always match foreign-key
-- dependency order, so disable FK checks for the load.
SET FOREIGN_KEY_CHECKS = 0;
-- Some legacy rows hold values a strict SQL mode would reject (e.g. an empty
-- ENUM value). Use a permissive mode for the load so the data imports as-is on
-- a default (strict) install, matching the source database.
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
