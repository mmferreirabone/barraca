<?php

if (!defined('MERCEARIA')) { http_response_code(403); exit; }

date_default_timezone_set(FUSO_HORARIO);

const UNIDADES_VALIDAS  = ['unidade', 'pacote', 'caixa', 'garrafa', 'lata', 'kg', 'dúzia', 'peça', 'litro'];
const PAGINAS_CONTADAS  = ['index.html', 'produtos.html', 'login.html', 'sobre.html', 'contato.html'];
const LIMITE_CORPO      = 1500000;   
const LIMITE_IMAGEM     = 2097152;  
const PASTA_UPLOADS     = __DIR__ . '/../uploads';


const HASH_FALSO = '$2y$10$U9QSN6imtpqNCAlv/t.N2OQ8ZvyG/NGogQvjgPu1gEKLINBNpkRDG';


class ErroHttp extends Exception
{
    public int $status;
    public array $extra;

    public function __construct(int $status, string $mensagem, array $extra = [])
    {
        parent::__construct($mensagem);
        $this->status = $status;
        $this->extra = $extra;
    }
}

function responder($dados = [], int $status = 200)
{
    http_response_code($status);
    if ($status !== 204) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}


function proteger_escrita(): void
{

    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'mercearia') {
        throw new ErroHttp(403, 'Requisição não permitida.');
    }
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origem !== '') {
        $host  = (string) parse_url($origem, PHP_URL_HOST);
        $porta = parse_url($origem, PHP_URL_PORT);
        $origemHost = strtolower($host . ($porta ? ':' . $porta : ''));
        $meuHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        if (CONFIAR_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $meuHost = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]));
        }
        if ($origemHost !== $meuHost) {
            throw new ErroHttp(403, 'Origem não permitida.');
        }
    }
}

function ler_json(): array
{
    $tipo = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($tipo !== 'application/json') {
        throw new ErroHttp(415, 'Envie os dados no formato JSON.');
    }
    $bruto = file_get_contents('php://input', false, null, 0, LIMITE_CORPO + 1);
    if ($bruto === false) {
        throw new ErroHttp(400, 'Não foi possível ler os dados enviados.');
    }
    if (strlen($bruto) > LIMITE_CORPO) {
        throw new ErroHttp(413, 'Os dados enviados são grandes demais.');
    }
    if ($bruto === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    $ehLista = is_array($dados) && $dados !== [] && array_keys($dados) === range(0, count($dados) - 1);
    if (!is_array($dados) || $ehLista) {
        throw new ErroHttp(400, 'JSON inválido.');
    }
    return $dados;
}

function tamanho_texto(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}

function campo_texto($valor, int $min, int $max, string $rotulo): string
{
    if (!is_string($valor)) {
        throw new ErroHttp(400, 'Preencha o campo ' . $rotulo . '.');
    }
    $limpo = trim($valor);
    $tam = tamanho_texto($limpo);
    if ($tam < $min || $tam > $max) {
        throw new ErroHttp(400, ucfirst($rotulo) . ' deve ter entre ' . $min . ' e ' . $max . ' caracteres.');
    }
    return $limpo;
}

function usando_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return CONFIAR_PROXY && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function ip_cliente(): string
{
    if (CONFIAR_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
}


function caminho_cookie(): string
{
    $pasta = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
    return $pasta . '/';
}


function sessao_abrir(bool $escrita = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('mercearia_sid');
    session_set_cookie_params([
        'lifetime' => 0,                    
        'path'     => caminho_cookie(),
        'secure'   => usando_https(),
        'httponly' => true,              
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
   
    session_start($escrita ? [] : ['read_and_close' => true]);
}

function usuario_logado(): ?array
{
    sessao_abrir(false);
    $logId = (int) ($_SESSION['log_id'] ?? 0);
    if ($logId <= 0) {
        return null;
    }

    $pdo = conectar();
    $st = $pdo->prepare(
        'SELECT l.ultimo, u.id, u.nome, u.email, u.telefone, u.admin
           FROM sessoes_login l
           JOIN usuarios u ON u.id = l.usuario_id
          WHERE l.id = ? AND l.fim IS NULL'
    );
    $st->execute([$logId]);
    $linha = $st->fetch();
    if (!$linha) {
        return null;                       
    }

    $agora = time();
    if ($agora - (int) $linha['ultimo'] > SESSAO_OCIOSA_SEG) {
     
        $pdo->prepare('UPDATE sessoes_login SET fim = ultimo WHERE id = ?')->execute([$logId]);
        return null;
    }
    if ($agora - (int) $linha['ultimo'] > 5) {
        $pdo->prepare('UPDATE sessoes_login SET ultimo = ? WHERE id = ?')->execute([$agora, $logId]);
    }
    return $linha;
}

function usuario_publico(array $u): array
{
    return [
        'nome'     => $u['nome'],
        'email'    => $u['email'],
        'telefone' => $u['telefone'],
        'admin'    => (bool) (int) $u['admin'],
    ];
}

function exigir_admin(): array
{
    $usuario = usuario_logado();
    if (!$usuario) {
        throw new ErroHttp(401, 'Entre na sua conta para continuar.');
    }
    if (!(int) $usuario['admin']) {
        throw new ErroHttp(403, 'Apenas administradores podem fazer isso.');
    }
    return $usuario;
}

function encerrar_login_atual(PDO $pdo): void
{
    $logId = (int) ($_SESSION['log_id'] ?? 0);
    if ($logId > 0) {
        $pdo->prepare('UPDATE sessoes_login SET fim = ? WHERE id = ? AND fim IS NULL')->execute([time(), $logId]);
    }
}

function abrir_login(PDO $pdo, int $usuarioId): void
{
    sessao_abrir(true);
    encerrar_login_atual($pdo);             
    session_regenerate_id(true);            
    $agora = time();
    $pdo->prepare('INSERT INTO sessoes_login (usuario_id, inicio, ultimo) VALUES (?, ?, ?)')
        ->execute([$usuarioId, $agora, $agora]);
    $_SESSION = ['uid' => $usuarioId, 'log_id' => (int) $pdo->lastInsertId()];
    session_write_close();
}

function fechar_login(PDO $pdo): void
{
    sessao_abrir(true);
    encerrar_login_atual($pdo);
    $_SESSION = [];
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => caminho_cookie(),
        'secure'   => usando_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_destroy();
}


function tentativas_contar(string $tipo, string $chave, int $janelaSeg): int
{
    $st = conectar()->prepare('SELECT COUNT(*) FROM tentativas WHERE tipo = ? AND chave = ? AND criado_em >= ?');
    $st->execute([$tipo, substr($chave, 0, 190), time() - $janelaSeg]);
    return (int) $st->fetchColumn();
}

function tentativa_registrar(string $tipo, string $chave): void
{
    $pdo = conectar();
    $pdo->prepare('INSERT INTO tentativas (tipo, chave, criado_em) VALUES (?, ?, ?)')
        ->execute([$tipo, substr($chave, 0, 190), time()]);
    if (random_int(1, 50) === 1) {          
        $pdo->prepare('DELETE FROM tentativas WHERE criado_em < ?')->execute([time() - 86400]);
    }
}

function tentativas_zerar(string $tipo, string $chave): void
{
    conectar()->prepare('DELETE FROM tentativas WHERE tipo = ? AND chave = ?')->execute([$tipo, substr($chave, 0, 190)]);
}


function para_centavos($valor): int
{
    return (int) round(((float) $valor) * 100);
}

function de_centavos(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', '');
}
