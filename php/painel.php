<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

function rota_acesso(): void
{
    proteger_escrita();
    $c      = ler_json();
    $pagina = (string) ($c['pagina'] ?? '');
    if (!in_array($pagina, PAGINAS_CONTADAS, true)) {
        responder([], 204);                       }

    $visitante = $_COOKIE['mercearia_vid'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $visitante)) {
        $visitante = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        setcookie('mercearia_vid', $visitante, [
            'expires'  => time() + 365 * 86400,
            'path'     => caminho_cookie(),
            'secure'   => usando_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $pdo = conectar();
    $st = $pdo->prepare('SELECT COUNT(*) FROM acessos WHERE visitante = ? AND criado_em >= ?');
    $st->execute([$visitante, date('Y-m-d H:i:s', time() - 60)]);
    if ((int) $st->fetchColumn() >= 120) {
        responder([], 204);
    }

    $pdo->prepare('INSERT INTO acessos (pagina, visitante, dia, criado_em) VALUES (?, ?, ?, ?)')
        ->execute([$pagina, $visitante, date('Y-m-d'), date('Y-m-d H:i:s')]);
    responder([], 204);
}

function rota_painel(): void
{
    exigir_admin();
    $pdo = conectar();

    $acessos    = (int) $pdo->query('SELECT COUNT(*) FROM acessos')->fetchColumn();
    $visitantes = (int) $pdo->query('SELECT COUNT(DISTINCT visitante) FROM acessos')->fetchColumn();
    $cadastros  = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

    $tempo = $pdo->query('SELECT COUNT(*) AS total, AVG(COALESCE(fim, ultimo) - inicio) AS media FROM sessoes_login')->fetch();
    $logins = (int) $tempo['total'];

    $pedidos = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS soma FROM pedidos')->fetch();

    $dias = [];
    for ($i = 6; $i >= 0; $i--) {
        $momento = strtotime("-$i day");
        $dias[date('Y-m-d', $momento)] = ['dia' => date('Y-m-d', $momento), 'rotulo' => date('d/m', $momento), 'total' => 0];
    }
    $st = $pdo->prepare('SELECT dia, COUNT(*) AS total FROM acessos WHERE dia >= ? GROUP BY dia');
    $st->execute([array_key_first($dias)]);
    foreach ($st->fetchAll() as $linha) {
        if (isset($dias[$linha['dia']])) {
            $dias[$linha['dia']]['total'] = (int) $linha['total'];
        }
    }

    $paginas = [];
    foreach ($pdo->query('SELECT pagina, COUNT(*) AS total FROM acessos GROUP BY pagina ORDER BY total DESC LIMIT 6') as $p) {
        $paginas[] = ['pagina' => $p['pagina'], 'total' => (int) $p['total']];
    }

    $maisVendidos = [];
    foreach ($pdo->query('SELECT produto_id, nome, quantidade, receita FROM v_mais_vendidos ORDER BY quantidade DESC, receita DESC LIMIT 8') as $v) {
        $maisVendidos[] = [
            'id'         => (string) $v['produto_id'],
            'nome'       => $v['nome'],
            'quantidade' => (int) $v['quantidade'],
            'receita'    => (float) $v['receita'],
        ];
    }

    responder([
        'acessos'           => $acessos,
        'visitantes'        => $visitantes,
        'cadastros'         => $cadastros,
        'tempoMedioLoginMs' => $logins > 0 ? (int) round(((float) $tempo['media']) * 1000) : null,
        'loginsRegistrados' => $logins,
        'pedidos'           => (int) $pedidos['n'],
        'faturamento'       => (float) $pedidos['soma'],
        'acessosPorDia'     => array_values($dias),
        'paginas'           => $paginas,
        'maisVendidos'      => $maisVendidos,
    ]);
}
