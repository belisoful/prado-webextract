DROP DATABASE IF EXISTS `prado_compex_unitest`;
CREATE DATABASE `prado_compex_unitest`;
CREATE USER 'prado_compex_unitest'@'localhost' identified by 'prado_compex_unitest';
GRANT ALL ON `prado_compex_unitest`.* TO 'prado_compex_unitest'@'localhost';
FLUSH PRIVILEGES;

USE `prado_compex_unitest`;
