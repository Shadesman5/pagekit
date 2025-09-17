-- Create Pagekit database and user
CREATE DATABASE IF NOT EXISTS pagekit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pagekit'@'%' IDENTIFIED BY 'pagekit';
GRANT ALL PRIVILEGES ON pagekit.* TO 'pagekit'@'%';
FLUSH PRIVILEGES;
