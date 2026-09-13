-- The database the PHPUnit repository tests build and tear down in.
-- Kept apart from `gob` so a suite run can never chew through the data a
-- developer is playing with; tests/Support/DatabaseTestCase refuses any
-- database whose name does not end in _test.
CREATE DATABASE IF NOT EXISTS gob_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON gob_test.* TO 'gob'@'%';
