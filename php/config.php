<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }


define('DB_HOST',    getenv('MERCEARIA_DB_HOST')    ?: 'localhost');
define('DB_PORTA',   getenv('MERCEARIA_DB_PORTA')   ?: '3306');
define('DB_NOME',    getenv('MERCEARIA_DB_NOME')    ?: 'mercearia');
define('DB_USUARIO', getenv('MERCEARIA_DB_USUARIO') ?: 'root');
define('DB_SENHA',   getenv('MERCEARIA_DB_SENHA') !== false ? getenv('MERCEARIA_DB_SENHA') : '');

define('DB_DSN', getenv('MERCEARIA_DSN') ?: '');

define('ADMIN_EMAIL', getenv('MERCEARIA_ADMIN_EMAIL') ?: '');

define('FUSO_HORARIO', 'America/Sao_Paulo');   
define('SESSAO_OCIOSA_SEG', 1800);             
define('CONFIAR_PROXY', false);                