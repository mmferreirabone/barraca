<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

function rota_eu(): void
{
    $usuario = usuario_logado();
    responder(['usuario' => $usuario ? usuario_publico($usuario) : null]);
}

function rota_cadastro(): void
{
    proteger_escrita();
    $c   = ler_json();
    $pdo = conectar();
    $ip  = ip_cliente();

    if (tentativas_contar('cadastro_ip', $ip, 3600) >= 10) {
        throw new ErroHttp(429, 'Muitos cadastros em pouco tempo. Tente de novo mais tarde.');
    }
    tentativa_registrar('cadastro_ip', $ip);

    $nome     = campo_texto($c['nome'] ?? null, 2, 80, 'nome');
    $email    = strtolower(campo_texto($c['email'] ?? null, 5, 120, 'e-mail'));
    $telefone = campo_texto($c['telefone'] ?? null, 8, 30, 'telefone');
    $senha    = $c['senha'] ?? null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ErroHttp(400, 'Digite um e-mail válido.');
    }
    if (!is_string($senha) || strlen($senha) < 6 || strlen($senha) > 72) {
        throw new ErroHttp(400, 'A senha deve ter entre 6 e 72 caracteres.');
    }

    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) {
        throw new ErroHttp(409, 'Este e-mail já está cadastrado. Tente entrar.');
    }

    $hash  = password_hash($senha, PASSWORD_DEFAULT);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $admin = ($total === 0 || (ADMIN_EMAIL !== '' && $email === strtolower(ADMIN_EMAIL))) ? 1 : 0;

    try {
        $pdo->prepare('INSERT INTO usuarios (nome, email, telefone, senha_hash, admin, criado_em) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $email, $telefone, $hash, $admin, date('Y-m-d H:i:s')]);
    } catch (PDOException $erro) {
        if ($erro->getCode() === '23000') {
            throw new ErroHttp(409, 'Este e-mail já está cadastrado. Tente entrar.');
        }
        throw $erro;
    }

    abrir_login($pdo, (int) $pdo->lastInsertId());
    responder(['usuario' => ['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'admin' => (bool) $admin]], 201);
}

function rota_login(): void
{
    proteger_escrita();
    $c   = ler_json();
    $pdo = conectar();
    $ip  = ip_cliente();

    $email = substr(strtolower(trim((string) ($c['email'] ?? ''))), 0, 120);
    $senha = is_string($c['senha'] ?? null) ? substr($c['senha'], 0, 200) : '';

    if (tentativas_contar('login_email', $email, 900) >= 5 || tentativas_contar('login_ip', $ip, 900) >= 30) {
        throw new ErroHttp(429, 'Muitas tentativas erradas. Aguarde alguns minutos e tente de novo.');
    }

    $st = $pdo->prepare('SELECT id, nome, email, telefone, admin, senha_hash FROM usuarios WHERE email = ?');
    $st->execute([$email]);
    $usuario = $st->fetch();

    if ($usuario) {
        $confere = password_verify($senha, $usuario['senha_hash']);
    } else {
        password_verify($senha, HASH_FALSO);      // gasta o mesmo tempo, para não revelar quais e-mails existem
        $confere = false;
    }

    if (!$confere) {
        tentativa_registrar('login_email', $email);
        tentativa_registrar('login_ip', $ip);
        throw new ErroHttp(401, 'E-mail ou senha incorretos. Confira os dados ou crie uma conta.');
    }

    if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $usuario['id']]);
    }

    tentativas_zerar('login_email', $email);
    abrir_login($pdo, (int) $usuario['id']);
    responder(['usuario' => usuario_publico($usuario)]);
}

function rota_logout(): void
{
    proteger_escrita();
    fechar_login(conectar());
    responder(['ok' => true]);
}
