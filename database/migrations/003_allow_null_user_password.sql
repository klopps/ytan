-- The legacy PESR schema allowed a NULL password (used by an internal
-- "system" user that never logs in). Match that so real data can be
-- migrated without inventing a fake password.
ALTER TABLE `user` MODIFY `password` VARCHAR(255) DEFAULT NULL;
