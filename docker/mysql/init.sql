
-- Grant app_user access to the test database.
-- The main database (fund_transfer) and app_user credentials are created
-- automatically by the MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD env vars.
-- This file only adds the extra privilege needed for the test database,
-- which Symfony's bootstrap creates via doctrine:database:create --env=test.
GRANT ALL PRIVILEGES ON `fund_transfer_test%`.* TO 'app_user'@'%';
FLUSH PRIVILEGES;


