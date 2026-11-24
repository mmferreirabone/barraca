<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

function rota_pedido(): void
{
    proteger_escrita();
    $c       = ler_json();
    $pdo     = conectar();
    $usuario = usuario_logado();

    $recebidos = $c['itens'] ?? null;
    if (!is_array($recebidos) || count($recebidos) === 0 || count($recebidos) > 100) {
        throw new ErroHttp(400, 'O carrinho está vazio.');
    }

    
    $quantidades = [];
    foreach ($recebidos as $item) {
        $id  = is_array($item) ? ($item['id'] ?? null) : null;
        $qtd = is_array($item) ? ($item['quantidade'] ?? null) : null;
        if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || !is_int($qtd) || $qtd < 1 || $qtd > 999) {
            throw new ErroHttp(400, 'Item inválido no carrinho.');
        }
        $chave = (string) (int) $id;
        $quantidades[$chave] = ($quantidades[$chave] ?? 0) + $qtd;
    }

    $busca = $pdo->prepare('SELECT id, nome, preco, emoji, imagem FROM produtos WHERE id = ? AND ativo = 1');
    $linhas = [];
    $indisponiveis = [];
    foreach ($quantidades as $id => $qtd) {
        $busca->execute([(int) $id]);
        $produto = $busca->fetch();
        if (!$produto) {
            $indisponiveis[] = (string) $id;
        } else {
            $linhas[] = ['produto' => $produto, 'quantidade' => $qtd, 'centavos' => para_centavos($produto['preco'])];
        }
    }
    if ($indisponiveis) {
        throw new ErroHttp(409, 'Alguns produtos do carrinho não estão mais disponíveis.', ['indisponiveis' => $indisponiveis]);
    }

    $total = 0;
    foreach ($linhas as $l) {
        $total += $l['centavos'] * $l['quantidade'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO pedidos (usuario_id, total, criado_em) VALUES (?, ?, ?)')
            ->execute([$usuario ? (int) $usuario['id'] : null, de_centavos($total), date('Y-m-d H:i:s')]);
        $pedidoId = (int) $pdo->lastInsertId();

        $inserir = $pdo->prepare(
            'INSERT INTO pedido_itens (pedido_id, produto_id, nome, preco_unitario, quantidade) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($linhas as $l) {
            $inserir->execute([$pedidoId, (int) $l['produto']['id'], $l['produto']['nome'], de_centavos($l['centavos']), $l['quantidade']]);
        }
        $pdo->commit();
    } catch (Throwable $erro) {
        $pdo->rollBack();
        throw $erro;
    }

    responder([
        'pedido' => [
            'numero' => $pedidoId,
            'total'  => $total / 100,
            'itens'  => array_map(static function ($l) {
                return [
                    'id'         => (string) $l['produto']['id'],
                    'nome'       => $l['produto']['nome'],
                    'emoji'      => $l['produto']['emoji'],
                    'imagem'     => $l['produto']['imagem'],
                    'quantidade' => $l['quantidade'],
                    'preco'      => $l['centavos'] / 100,
                ];
            }, $linhas),
        ],
    ], 201);
}
