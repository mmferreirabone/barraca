<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

function produto_para_json(array $p): array
{
    return [
        'id'        => (string) $p['id'],
        'nome'      => $p['nome'],
        'descricao' => $p['descricao'],
        'categoria' => $p['categoria'],
        'preco'     => (float) $p['preco'],
        'unidade'   => $p['unidade'],
        'emoji'     => $p['emoji'],
        'imagem'    => $p['imagem'],
        'padrao'    => (bool) (int) $p['padrao'],
    ];
}

const SQL_PRODUTO =
    'SELECT p.id, p.nome, p.descricao, c.nome AS categoria, p.preco, p.unidade, p.emoji, p.imagem, p.padrao
       FROM produtos p
       JOIN categorias c ON c.id = p.categoria_id';


function rota_catalogo(): void
{
    $pdo = conectar();

    $categorias = [];
    foreach ($pdo->query('SELECT nome, emoji, imagem, cor, frase, destaque FROM categorias ORDER BY id') as $c) {
        $categorias[] = [
            'nome'     => $c['nome'],
            'emoji'    => $c['emoji'],
            'imagem'   => $c['imagem'],
            'cor'      => $c['cor'],
            'frase'    => $c['frase'],
            'destaque' => (bool) (int) $c['destaque'],
        ];
    }

    $produtos = [];
    foreach ($pdo->query(SQL_PRODUTO . ' WHERE p.ativo = 1 ORDER BY p.padrao DESC, p.id ASC') as $p) {
        $produtos[] = produto_para_json($p);
    }

    responder(['categorias' => $categorias, 'unidades' => UNIDADES_VALIDAS, 'produtos' => $produtos]);
}


function salvar_imagem(array $arquivo): string
{
    $erro = (int) $arquivo['error'];
    if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE || (int) $arquivo['size'] > LIMITE_IMAGEM) {
        throw new ErroHttp(413, 'A imagem é grande demais (máximo de 2 MB).');
    }
    if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        throw new ErroHttp(400, 'Não foi possível receber a imagem. Tente novamente.');
    }


    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensoes[$mime])) {
        throw new ErroHttp(400, 'Use uma imagem JPG, PNG ou WebP.');
    }
    $medidas = @getimagesize($arquivo['tmp_name']);
    if (!$medidas || $medidas[0] > 6000 || $medidas[1] > 6000) {
        throw new ErroHttp(400, 'Imagem inválida ou com dimensões grandes demais.');
    }

    if (!is_dir(PASTA_UPLOADS) && !@mkdir(PASTA_UPLOADS, 0755, true)) {
        throw new ErroHttp(500, 'Não foi possível criar a pasta "uploads".');
    }
    if (!is_writable(PASTA_UPLOADS)) {
        throw new ErroHttp(500, 'A pasta "uploads" não tem permissão de escrita no servidor.');
    }

    $nome = bin2hex(random_bytes(8)) . '.' . $extensoes[$mime];     // nome aleatório, extensão escolhida por nós
    if (!move_uploaded_file($arquivo['tmp_name'], PASTA_UPLOADS . '/' . $nome)) {
        throw new ErroHttp(500, 'Não foi possível salvar a imagem no servidor.');
    }
    @chmod(PASTA_UPLOADS . '/' . $nome, 0644);
    return 'uploads/' . $nome;
}

function apagar_imagem(string $caminho): void
{

    if (preg_match('#^uploads/[a-f0-9]{16}\.(jpg|png|webp)$#', $caminho)) {
        @unlink(PASTA_UPLOADS . '/' . basename($caminho));
    }
}

function rota_produto_criar(): void
{
    proteger_escrita();
    exigir_admin();
    $pdo = conectar();

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new ErroHttp(413, 'Os dados enviados são grandes demais.');
    }

    $nome      = campo_texto($_POST['nome'] ?? null, 2, 60, 'nome do produto');
    $descricao = trim((string) ($_POST['descricao'] ?? ''));
    if (tamanho_texto($descricao) > 300) {
        throw new ErroHttp(400, 'A descrição deve ter no máximo 300 caracteres.');
    }
    $emoji = trim((string) ($_POST['emoji'] ?? ''));
    if (tamanho_texto($emoji) > 8) {
        throw new ErroHttp(400, 'O ícone deve ser um único emoji.');
    }

    $unidade = (string) ($_POST['unidade'] ?? '');
    if (!in_array($unidade, UNIDADES_VALIDAS, true)) {
        throw new ErroHttp(400, 'Escolha uma unidade válida.');
    }

    $precoTexto = str_replace(',', '.', trim((string) ($_POST['preco'] ?? '')));
    if (!is_numeric($precoTexto) || (float) $precoTexto < 0.01 || (float) $precoTexto > 99999.99) {
        throw new ErroHttp(400, 'Informe um preço entre R$ 0,01 e R$ 99.999,99.');
    }
    $centavos = para_centavos($precoTexto);

    $st = $pdo->prepare('SELECT id FROM categorias WHERE nome = ?');
    $st->execute([(string) ($_POST['categoria'] ?? '')]);
    $categoriaId = $st->fetchColumn();
    if (!$categoriaId) {
        throw new ErroHttp(400, 'Escolha uma categoria válida.');
    }

    $st = $pdo->prepare('SELECT id FROM produtos WHERE ativo = 1 AND LOWER(nome) = LOWER(?)');
    $st->execute([$nome]);
    if ($st->fetch()) {
        throw new ErroHttp(409, 'Já existe um produto com esse nome. Use um nome diferente.');
    }

    $imagem = '';
    if (isset($_FILES['imagem']) && (int) $_FILES['imagem']['error'] !== UPLOAD_ERR_NO_FILE) {
        $imagem = salvar_imagem($_FILES['imagem']);
    }

    try {
        $pdo->prepare(
            'INSERT INTO produtos (nome, descricao, categoria_id, preco, unidade, emoji, imagem, padrao, ativo, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?)'
        )->execute([$nome, $descricao, (int) $categoriaId, de_centavos($centavos), $unidade, $emoji, $imagem, date('Y-m-d H:i:s')]);
    } catch (Throwable $erro) {
        if ($imagem !== '') {
            apagar_imagem($imagem);             
        }
        throw $erro;
    }

    $id = (int) $pdo->lastInsertId();
    $st = $pdo->prepare(SQL_PRODUTO . ' WHERE p.id = ?');
    $st->execute([$id]);
    responder(['produto' => produto_para_json($st->fetch())], 201);
}

function rota_produto_remover(): void
{
    proteger_escrita();
    exigir_admin();
    $c  = ler_json();
    $id = $c['id'] ?? null;
    if (!is_string($id) && !is_int($id)) {
        throw new ErroHttp(400, 'Produto inválido.');
    }
    $id = (string) $id;
    if (!ctype_digit($id)) {
        throw new ErroHttp(400, 'Produto inválido.');
    }

    $pdo = conectar();
    $st = $pdo->prepare('SELECT imagem FROM produtos WHERE id = ? AND ativo = 1');
    $st->execute([(int) $id]);
    $produto = $st->fetch();
    if (!$produto) {
        throw new ErroHttp(404, 'Produto não encontrado.');
    }

    $pdo->prepare('UPDATE produtos SET ativo = 0 WHERE id = ?')->execute([(int) $id]);
    if ($produto['imagem'] !== '') {
        apagar_imagem($produto['imagem']);
    }
    responder(['ok' => true]);
}
