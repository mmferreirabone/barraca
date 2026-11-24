<?php

define('MERCEARIA', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');     
ini_set('log_errors', '1');        

require __DIR__ . '/config.php';
require __DIR__ . '/nucleo.php';
require __DIR__ . '/conexao.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/produtos.php';
require __DIR__ . '/pedidos.php';
require __DIR__ . '/painel.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$rotas = [
    'GET catalogo'          => 'rota_catalogo',
    'GET eu'                => 'rota_eu',
    'POST cadastro'         => 'rota_cadastro',
    'POST login'            => 'rota_login',
    'POST logout'           => 'rota_logout',
    'POST produto_criar'    => 'rota_produto_criar',
    'POST produto_remover'  => 'rota_produto_remover',
    'POST pedido'           => 'rota_pedido',
    'POST acesso'           => 'rota_acesso',
    'GET painel'            => 'rota_painel',
    'GET saude'             => static function () { responder(['ok' => true]); },
];

try {
    $nome   = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
    $chave  = $_SERVER['REQUEST_METHOD'] . ' ' . $nome;

    if (!isset($rotas[$chave])) {
        $existe = false;
        foreach (array_keys($rotas) as $k) {
            if (substr($k, strpos($k, ' ') + 1) === $nome) {
                $existe = true;
            }
        }
        throw new ErroHttp($existe ? 405 : 404, $existe ? 'Método não permitido.' : 'Rota não encontrada.');
    }

    $rotas[$chave]();
} catch (ErroHttp $erro) {
    responder(['erro' => $erro->getMessage()] + $erro->extra, $erro->status);
} catch (Throwable $erro) {
    error_log('Mercearia: ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());
    responder(['erro' => 'Erro interno do servidor. Tente novamente.'], 500);
}
