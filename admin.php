<?php
require __DIR__ . '/bootstrap.php';

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

$passwordHash = setting('admin_password_hash');
$view = $passwordHash === null ? 'setup' : (admin_logged_in() ? 'dashboard' : 'login');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'setup' && $passwordHash === null) {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            $errors[] = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($password !== $confirm) {
            $errors[] = 'As senhas não são iguais.';
        } else {
            save_setting('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            flash('success', 'Painel configurado. Bem-vindo!');
            redirect('admin.php');
        }
    } elseif ($action === 'login' && $passwordHash !== null) {
        if (password_verify((string) ($_POST['password'] ?? ''), $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            redirect('admin.php');
        }
        $errors[] = 'Senha incorreta.';
    } elseif ($action === 'logout') {
        unset($_SESSION['admin_logged_in']);
        session_regenerate_id(true);
        redirect('admin.php');
    } elseif (admin_logged_in()) {
        if ($action === 'create_campaign') {
            $date = trim((string) ($_POST['campaign_date'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$validDate || $validDate->format('Y-m-d') !== $date) {
                $errors[] = 'Escolha uma data válida.';
            }
            if ($title === '' || mb_strlen($title) > 100) {
                $errors[] = 'Informe um título com até 100 caracteres.';
            }
            if (!$errors) {
                $stmt = db()->prepare('INSERT INTO campaigns (campaign_date, title, is_active, created_at) VALUES (?, ?, 0, ?)');
                $stmt->execute([$date, $title, date('Y-m-d H:i:s')]);
                flash('success', 'Novo dia criado. Clique em “Abrir cadastros” para publicá-lo.');
                redirect('admin.php');
            }
        } elseif ($action === 'edit_campaign') {
            $id = filter_input(INPUT_POST, 'campaign_id', FILTER_VALIDATE_INT);
            $date = trim((string) ($_POST['campaign_date'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $purchaseStart = trim((string) ($_POST['purchase_start'] ?? ''));
            $purchaseEnd = trim((string) ($_POST['purchase_end'] ?? ''));
            $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$id || !$validDate || $validDate->format('Y-m-d') !== $date) {
                $errors[] = 'Informe uma data válida para o dia da campanha.';
            }
            if ($title === '' || mb_strlen($title) > 150) {
                $errors[] = 'Informe um título com até 150 caracteres.';
            }
            foreach ([$purchaseStart, $purchaseEnd] as $periodDate) {
                if ($periodDate !== '') {
                    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodDate);
                    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $periodDate) {
                        $errors[] = 'Confira as datas do período da promoção.';
                        break;
                    }
                }
            }
            if (!$errors) {
                $stmt = db()->prepare('UPDATE campaigns SET campaign_date = ?, title = ?, purchase_start = ?, purchase_end = ?, intro_text = ?, deadline_text = ?, rules_text = ?, contact_text = ?, seller_note = ?, customer_instructions = ? WHERE id = ?');
                $stmt->execute([
                    $date, $title, $purchaseStart ?: null, $purchaseEnd ?: null,
                    trim((string) ($_POST['intro_text'] ?? '')),
                    trim((string) ($_POST['deadline_text'] ?? '')),
                    trim((string) ($_POST['rules_text'] ?? '')),
                    trim((string) ($_POST['contact_text'] ?? '')),
                    trim((string) ($_POST['seller_note'] ?? '')),
                    trim((string) ($_POST['customer_instructions'] ?? '')),
                    $id,
                ]);
                flash('success', 'Data e informações da campanha atualizadas.');
                redirect('admin.php?campaign=' . (int) $id);
            }
        } elseif ($action === 'activate_campaign') {
            $id = filter_input(INPUT_POST, 'campaign_id', FILTER_VALIDATE_INT);
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->exec('UPDATE campaigns SET is_active = 0');
            $stmt = $pdo->prepare('UPDATE campaigns SET is_active = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
            flash('success', 'Dia publicado. O formulário já está recebendo cadastros.');
            redirect('admin.php?campaign=' . (int) $id);
        } elseif ($action === 'close_campaigns') {
            db()->exec('UPDATE campaigns SET is_active = 0');
            flash('success', 'Cadastros fechados no site.');
            redirect('admin.php');
        } elseif ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            if (!password_verify($current, (string) setting('admin_password_hash'))) {
                $errors[] = 'A senha atual está incorreta.';
            } elseif (strlen($new) < 8) {
                $errors[] = 'A nova senha deve ter pelo menos 8 caracteres.';
            } else {
                save_setting('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
                flash('success', 'Senha alterada com sucesso.');
                redirect('admin.php');
            }
        }
    }
    $passwordHash = setting('admin_password_hash');
    $view = $passwordHash === null ? 'setup' : (admin_logged_in() ? 'dashboard' : 'login');
}

if (isset($_GET['export']) && admin_logged_in()) {
    $campaignId = filter_input(INPUT_GET, 'export', FILTER_VALIDATE_INT);
    $stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$campaignId]);
    $exportCampaign = $stmt->fetch();
    if (!$exportCampaign) {
        http_response_code(404);
        exit('Dia não encontrado.');
    }
    $stmt = db()->prepare('SELECT s.seller_name, c.customer_name, c.region
        FROM customers c JOIN submissions s ON s.id = c.submission_id
        WHERE s.campaign_id = ? ORDER BY s.created_at, c.id');
    $stmt->execute([$campaignId]);
    $rows = $stmt->fetchAll();
    $filename = 'cliente-fiel-' . $exportCampaign['campaign_date'] . '.txt';
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    foreach ($rows as $row) {
        echo 'Vendedor: ' . $row['seller_name'] . "\r\n";
        echo 'Cliente: ' . $row['customer_name'] . "\r\n";
        echo 'Região: ' . $row['region'] . "\r\n\r\n";
    }
    exit;
}

$flash = take_flash();
$campaigns = [];
$selected = null;
$submissions = [];
$totals = ['customers' => 0, 'sellers' => 0];
if ($view === 'dashboard') {
    $campaigns = db()->query('SELECT c.*,
        (SELECT COUNT(*) FROM submissions s WHERE s.campaign_id = c.id) AS submission_count,
        (SELECT COUNT(*) FROM customers cu JOIN submissions s2 ON s2.id = cu.submission_id WHERE s2.campaign_id = c.id) AS customer_count
        FROM campaigns c ORDER BY c.campaign_date DESC, c.id DESC')->fetchAll();
    $selectedId = filter_input(INPUT_GET, 'campaign', FILTER_VALIDATE_INT) ?: ($campaigns[0]['id'] ?? null);
    foreach ($campaigns as $candidate) {
        if ((int) $candidate['id'] === (int) $selectedId) {
            $selected = $candidate;
            break;
        }
    }
    if ($selected) {
        $stmt = db()->prepare('SELECT s.id, s.seller_name, s.created_at, COUNT(c.id) AS customer_count
            FROM submissions s LEFT JOIN customers c ON c.submission_id = s.id
            WHERE s.campaign_id = ? GROUP BY s.id ORDER BY s.created_at DESC');
        $stmt->execute([$selected['id']]);
        $submissions = $stmt->fetchAll();
        $totals['customers'] = (int) $selected['customer_count'];
        $totals['sellers'] = count(array_unique(array_column($submissions, 'seller_name')));
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="admin-body">
<?php if ($view !== 'dashboard'): ?>
    <main class="auth-shell">
        <a class="brand centered" href="index.php"><span class="brand-mark">CF</span><span><strong>Cliente Fiel</strong><small>Painel administrativo</small></span></a>
        <section class="auth-card">
            <span class="eyebrow"><?= $view === 'setup' ? 'Primeiro acesso' : 'Acesso restrito' ?></span>
            <h1><?= $view === 'setup' ? 'Crie sua senha' : 'Bem-vindo de volta' ?></h1>
            <p><?= $view === 'setup' ? 'Defina a senha que será usada para administrar as campanhas.' : 'Digite sua senha para acessar os cadastros.' ?></p>
            <?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $view === 'setup' ? 'setup' : 'login' ?>">
                <label for="password">Senha</label>
                <input id="password" type="password" name="password" minlength="8" required autofocus>
                <?php if ($view === 'setup'): ?>
                    <label for="password_confirm">Confirme a senha</label>
                    <input id="password_confirm" type="password" name="password_confirm" minlength="8" required>
                <?php endif; ?>
                <button class="primary-button" type="submit"><?= $view === 'setup' ? 'Criar painel' : 'Entrar' ?> <span>→</span></button>
            </form>
        </section>
        <a class="back-link" href="index.php">← Voltar ao formulário</a>
    </main>
<?php else: ?>
    <div class="dashboard-shell">
        <aside class="sidebar">
            <a class="brand light" href="admin.php"><span class="brand-mark">CF</span><span><strong>Cliente Fiel</strong><small>Painel administrativo</small></span></a>
            <nav>
                <a class="active" href="admin.php"><span>▦</span> Campanhas</a>
                <a href="index.php" target="_blank"><span>↗</span> Ver formulário</a>
            </nav>
            <form method="post" class="logout-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Sair do painel</button>
            </form>
        </aside>
        <main class="dashboard-main">
            <header class="dashboard-header">
                <div><span class="eyebrow">Visão geral</span><h1>Campanhas</h1></div>
                <button class="secondary-button" type="button" data-modal-open="new-day">+ Novo dia</button>
            </header>

            <?php if ($flash): ?><div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

            <section class="campaign-strip">
                <?php if (!$campaigns): ?>
                    <div class="empty-inline">Nenhum dia criado ainda. Crie o primeiro para começar.</div>
                <?php else: foreach ($campaigns as $item): ?>
                    <a class="campaign-chip <?= $selected && $selected['id'] == $item['id'] ? 'selected' : '' ?>" href="?campaign=<?= (int) $item['id'] ?>">
                        <span><?= e(format_date_br($item['campaign_date'])) ?><?= $item['is_active'] ? ' · ABERTO' : '' ?></span>
                        <strong><?= e($item['title']) ?></strong>
                        <small><?= (int) $item['customer_count'] ?> clientes</small>
                    </a>
                <?php endforeach; endif; ?>
            </section>

            <?php if ($selected): ?>
                <div class="section-title-row">
                    <div><h2><?= e($selected['title']) ?></h2><p><?= e(format_date_br($selected['campaign_date'])) ?></p></div>
                    <div class="actions">
                        <?php if ($selected['is_active']): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="close_campaigns"><button class="outline-button" type="submit">Fechar cadastros</button></form>
                        <?php else: ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="activate_campaign"><input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>"><button class="outline-button" type="submit">Abrir cadastros</button></form>
                        <?php endif; ?>
                        <a class="primary-button small" href="?export=<?= (int) $selected['id'] ?>">Baixar TXT ↓</a>
                    </div>
                </div>

                <details class="campaign-settings" <?= isset($_GET['editar']) || $errors ? 'open' : '' ?>>
                    <summary>Editar data e informações do formulário</summary>
                    <form method="post" class="campaign-settings-form">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="edit_campaign">
                        <input type="hidden" name="campaign_id" value="<?= (int) $selected['id'] ?>">
                        <div class="settings-two-columns">
                            <div><label for="edit_title">Título do formulário</label><input id="edit_title" name="title" maxlength="150" value="<?= e($selected['title']) ?>" required></div>
                            <div><label for="edit_date">Data do dia</label><input id="edit_date" type="date" name="campaign_date" value="<?= e($selected['campaign_date']) ?>" required></div>
                            <div><label for="purchase_start">Início das compras válidas</label><input id="purchase_start" type="date" name="purchase_start" value="<?= e($selected['purchase_start']) ?>"></div>
                            <div><label for="purchase_end">Fim das compras válidas</label><input id="purchase_end" type="date" name="purchase_end" value="<?= e($selected['purchase_end']) ?>"></div>
                        </div>
                        <label for="intro_text">Apresentação</label><textarea id="intro_text" name="intro_text" rows="3"><?= e(campaign_text($selected, 'intro_text')) ?></textarea>
                        <label for="deadline_text">Aviso de prazo e horário</label><textarea id="deadline_text" name="deadline_text" rows="2"><?= e(campaign_text($selected, 'deadline_text')) ?></textarea>
                        <label for="rules_text">Regras da promoção</label><textarea id="rules_text" name="rules_text" rows="4"><?= e(campaign_text($selected, 'rules_text')) ?></textarea>
                        <label for="contact_text">Contato para dúvidas</label><textarea id="contact_text" name="contact_text" rows="2"><?= e(campaign_text($selected, 'contact_text')) ?></textarea>
                        <label for="seller_note">Observação do campo vendedor</label><textarea id="seller_note" name="seller_note" rows="2"><?= e(campaign_text($selected, 'seller_note')) ?></textarea>
                        <label for="customer_instructions">Instruções do campo de clientes</label><textarea id="customer_instructions" name="customer_instructions" rows="3"><?= e(campaign_text($selected, 'customer_instructions')) ?></textarea>
                        <button class="primary-button small" type="submit">Salvar alterações</button>
                    </form>
                </details>

                <section class="stats-grid">
                    <article><span>Clientes cadastrados</span><strong><?= $totals['customers'] ?></strong><small>neste dia</small></article>
                    <article><span>Vendedores</span><strong><?= $totals['sellers'] ?></strong><small>nomes únicos</small></article>
                    <article><span>Envios recebidos</span><strong><?= count($submissions) ?></strong><small>listas enviadas</small></article>
                </section>

                <section class="table-card">
                    <div class="table-heading"><h3>Últimos envios</h3><span><?= count($submissions) ?> registros</span></div>
                    <div class="table-scroll"><table><thead><tr><th>Vendedor</th><th>Clientes</th><th>Data e hora</th></tr></thead><tbody>
                    <?php if (!$submissions): ?><tr><td colspan="3" class="empty-cell">Ainda não há envios neste dia.</td></tr>
                    <?php else: foreach ($submissions as $submission): ?><tr><td><strong><?= e($submission['seller_name']) ?></strong></td><td><?= (int) $submission['customer_count'] ?></td><td><?= e(date('d/m/Y \à\s H:i', strtotime($submission['created_at']))) ?></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                </section>
            <?php endif; ?>

            <details class="settings-card">
                <summary>Alterar senha administrativa</summary>
                <form method="post" class="inline-settings">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="change_password">
                    <input type="password" name="current_password" placeholder="Senha atual" required>
                    <input type="password" name="new_password" minlength="8" placeholder="Nova senha (mín. 8)" required>
                    <button class="outline-button" type="submit">Alterar senha</button>
                </form>
            </details>
        </main>
    </div>

    <dialog id="new-day" class="modal">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_campaign">
            <button class="modal-close" type="button" data-modal-close aria-label="Fechar">×</button>
            <span class="eyebrow">Nova campanha</span><h2>Criar um novo dia</h2><p>Depois de criar, você poderá liberar esse dia no formulário.</p>
            <label for="campaign_date">Data</label><input id="campaign_date" type="date" name="campaign_date" value="<?= date('Y-m-d') ?>" required>
            <label for="title">Nome do dia</label><input id="title" name="title" maxlength="100" value="Cliente Fiel - Sorteio Mazinho Solidário" required>
            <button class="primary-button" type="submit">Criar dia <span>→</span></button>
        </form>
    </dialog>
<?php endif; ?>
<script src="assets/app.js"></script>
</body>
</html>
