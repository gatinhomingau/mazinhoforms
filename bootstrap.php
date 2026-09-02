<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

const APP_NAME = 'Cliente Fiel';
const DB_PATH = __DIR__ . '/data/cliente-fiel.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dataDirectory = dirname(DB_PATH);
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0775, true) && !is_dir($dataDirectory)) {
        throw new RuntimeException('Não foi possível criar a pasta de dados.');
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_date TEXT NOT NULL,
        title TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    )');
    $campaignColumns = array_column($pdo->query('PRAGMA table_info(campaigns)')->fetchAll(), 'name');
    foreach (['purchase_start', 'purchase_end', 'intro_text', 'deadline_text', 'rules_text', 'contact_text', 'seller_note', 'customer_instructions'] as $column) {
        if (!in_array($column, $campaignColumns, true)) {
            $pdo->exec('ALTER TABLE campaigns ADD COLUMN ' . $column . ' TEXT');
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER NOT NULL,
        seller_name TEXT NOT NULL,
        raw_text TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER NOT NULL,
        customer_name TEXT NOT NULL,
        region TEXT NOT NULL,
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
    )');

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string) $_POST['csrf'])) {
        http_response_code(419);
        exit('Sessão expirada. Volte à página anterior e tente novamente.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setting(string $key): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
    $stmt->execute([$key, $value]);
}

function active_campaign(): ?array
{
    $row = db()->query('SELECT * FROM campaigns WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch();
    return $row ?: null;
}

function parse_customer_lines(string $text): array
{
    $items = [];
    $errors = [];
    $warnings = [];
    $lines = preg_split('/\R/u', trim($text)) ?: [];

    foreach ($lines as $index => $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        if ($line === '') {
            continue;
        }

        $name = '';
        $region = '';
        if (preg_match('/^(.+)\s+(de|da|do|dos|das)\s+(.+)$/iu', $line, $match)) {
            $name = trim($match[1]);
            $region = trim($match[2] . ' ' . $match[3]);
        } elseif (preg_match('/^(.+?)\s*(?:\||;|\s+-\s+)\s*(.+)$/u', $line, $match)) {
            $name = trim($match[1]);
            $region = trim($match[2]);
        }

        if ($name === '' || $region === '') {
            // Não bloqueia o cadastro: preserva a informação digitada para que
            // nenhum participante fique de fora por um erro de formatação.
            $name = $line;
            $region = 'Não informada';
            $warnings[] = 'Linha ' . ($index + 1) . ' sem região identificada.';
        }
        $items[] = ['name' => $name, 'region' => $region];
    }

    if (!$items && !$errors) {
        $errors[] = 'Informe pelo menos um cliente.';
    }

    return ['items' => $items, 'errors' => $errors, 'warnings' => $warnings];
}

function format_date_br(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('d/m/Y') : $date;
}

function campaign_text(array $campaign, string $field): string
{
    $defaults = [
        'intro_text' => 'Este formulário foi criado para que os vendedores do Sorteio Mazinho Solidário enviem os nomes dos clientes que compraram durante o período da promoção.',
        'deadline_text' => 'ESTÁ AUTORIZADO ENVIAR OS NOMES ATÉ AS 16H DO DIA DA CAMPANHA.',
        'rules_text' => "Para o cliente participar, ele precisa ter comprado com você no mínimo uma folha VIP, cartela ou bolão todos os dias da promoção.\n\nTodos os seus blocos da semana serão conferidos para localizar o nome do cliente.",
        'contact_text' => 'Qualquer dúvida na hora de preencher, chame o Antonio no WhatsApp (85) 996331479.',
        'seller_note' => 'Não é necessário colocar o nome do seu líder.',
        'customer_instructions' => "Coloque apenas um nome de cliente e sua região por linha. Você pode adicionar quantos nomes precisar.\n\nNão coloque ponto ou traço entre os nomes, pois isso pode dificultar a identificação do cliente. Evite enviar o formulário várias vezes.",
    ];
    $value = trim((string) ($campaign[$field] ?? ''));
    return $value !== '' ? $value : ($defaults[$field] ?? '');
}

// Inicializa o banco já no primeiro acesso e exibe um erro amigável se o driver não existir.
try {
    db();
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Não foi possível iniciar o banco de dados. Ative a extensão pdo_sqlite no PHP/WAMP.');
}
