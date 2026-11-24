<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

function conectar(): PDO
{
    static $pdo = null;            
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = DB_DSN !== ''
        ? DB_DSN
        : 'mysql:host=' . DB_HOST . ';port=' . DB_PORTA . ';dbname=' . DB_NOME . ';charset=utf8mb4';

    $opcoes = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         
        PDO::ATTR_EMULATE_PREPARES   => false,                    
    ];

    try {
        $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, $opcoes);
    } catch (PDOException $erro) {
        error_log('Mercearia: falha ao conectar no banco: ' . $erro->getMessage());
        throw new ErroHttp(
            503,
            'Não foi possível conectar ao banco de dados. Confira se o MySQL está ligado, '
            . 'se o arquivo banco/schema.sql foi importado e os dados em api/config.php.'
        );
    }

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');   
    }
    return $pdo;
}
