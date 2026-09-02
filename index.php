<?php
require __DIR__ . '/bootstrap.php';

$campaign = active_campaign();
$errors = [];
$sellerName = '';
$customersText = '';
$rulesAccepted = false;
$contactAccepted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $sellerName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['seller_name'] ?? '')) ?? '');
    $customersText = trim((string) ($_POST['customers'] ?? ''));
    $rulesAccepted = isset($_POST['rules_accepted']);
    $contactAccepted = isset($_POST['contact_accepted']);
    $campaign = active_campaign();

    if (!$campaign) {
        $errors[] = 'Os cadastros estão fechados no momento.';
    }
    if (mb_strlen($sellerName) < 2) {
        $errors[] = 'Informe o nome do vendedor.';
    }
    if (mb_strlen($sellerName) > 120) {
        $errors[] = 'O nome do vendedor deve ter no máximo 120 caracteres.';
    }
    if (!$rulesAccepted) {
        $errors[] = 'Confirme que está ciente das regras da promoção.';
    }
    if (!$contactAccepted) {
        $errors[] = 'Confirme que leu a informação de contato.';
    }

    $parsed = parse_customer_lines($customersText);
    $errors = array_merge($errors, $parsed['errors']);
    if (count($parsed['items']) > 300) {
        $errors[] = 'Envie no máximo 300 clientes por vez.';
    }

    if (!$errors && $campaign) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO submissions (campaign_id, seller_name, raw_text, created_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$campaign['id'], $sellerName, $customersText, date('Y-m-d H:i:s')]);
            $submissionId = (int) $pdo->lastInsertId();
            $customerStmt = $pdo->prepare('INSERT INTO customers (submission_id, customer_name, region) VALUES (?, ?, ?)');
            foreach ($parsed['items'] as $item) {
                $customerStmt->execute([$submissionId, $item['name'], $item['region']]);
            }
            $pdo->commit();
            $_SESSION['submission_success'] = [
                'seller_name' => $sellerName,
                'customer_count' => count($parsed['items']),
            ];
            redirect('index.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Não foi possível salvar agora. Tente novamente.';
        }
    }
}

$submissionSuccess = $_SESSION['submission_success'] ?? null;
unset($_SESSION['submission_success']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> — Cadastro</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="public-body">
<main class="mobile-form-shell">
    <header class="public-header">
        <span class="public-logo">CF</span>
        <div>
            <strong>Cliente Fiel</strong>
            <small>Cadastro de clientes</small>
        </div>
    </header>

    <section class="public-content">
        <?php if ($campaign && !$submissionSuccess): ?>
            <div class="campaign-heading">
                <div>
                    <span>Cadastro liberado</span>
                    <h1><?= e($campaign['title']) ?></h1>
                </div>
                <time datetime="<?= e($campaign['campaign_date']) ?>"><?= e(format_date_br($campaign['campaign_date'])) ?></time>
            </div>
            <div class="campaign-summary">
                <p><?= nl2br(e(campaign_text($campaign, 'intro_text'))) ?></p>
                <?php if (!empty($campaign['purchase_start']) && !empty($campaign['purchase_end'])): ?>
                    <p class="period-line"><strong>Período da promoção:</strong> <?= e(format_date_br($campaign['purchase_start'])) ?> a <?= e(format_date_br($campaign['purchase_end'])) ?></p>
                <?php endif; ?>
                <strong class="deadline-line"><?= nl2br(e(campaign_text($campaign, 'deadline_text'))) ?></strong>
            </div>
        <?php endif; ?>

        <div class="public-form-card">
            <?php if ($errors): ?>
                <div class="alert error"><strong>Revise o cadastro:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <?php if ($submissionSuccess): ?>
                <section class="submission-success">
                    <span class="success-check" aria-hidden="true">✓</span>
                    <p class="success-label">Cadastro enviado</p>
                    <h2><?= e($submissionSuccess['seller_name']) ?>, deu tudo certo!</h2>
                    <p>Recebemos <?= (int) $submissionSuccess['customer_count'] ?> <?= (int) $submissionSuccess['customer_count'] === 1 ? 'cliente' : 'clientes' ?> para o sorteio Cliente Fiel.</p>
                    <strong>Boa sorte!</strong>
                    <div class="success-contact">
                        Em caso de dúvidas, entre em contato com o Antonio pelo WhatsApp:<br>
                        <a href="https://wa.me/5585996331479" target="_blank" rel="noopener">(85) 99633-1479</a>
                    </div>
                    <a class="primary-button public-submit" href="index.php">Enviar mais nomes</a>
                </section>
            <?php elseif ($campaign): ?>
                <form method="post" id="registration-form" novalidate>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <section class="confirmation-block">
                        <h2>Regras da promoção</h2>
                        <p><?= nl2br(e(campaign_text($campaign, 'rules_text'))) ?></p>
                        <label class="check-row"><input type="checkbox" name="rules_accepted" value="1" <?= $rulesAccepted ? 'checked' : '' ?> required><span>Estou ciente das regras da promoção Cliente Fiel.</span></label>
                    </section>

                    <section class="confirmation-block compact">
                        <p><?= nl2br(e(campaign_text($campaign, 'contact_text'))) ?></p>
                        <label class="check-row"><input type="checkbox" name="contact_accepted" value="1" <?= $contactAccepted ? 'checked' : '' ?> required><span>Li e estou ciente.</span></label>
                    </section>

                    <label for="seller_name">Nome do vendedor</label>
                    <input id="seller_name" name="seller_name" maxlength="120" value="<?= e($sellerName) ?>" placeholder="Digite seu nome" required autocomplete="name">
                    <p class="seller-note"><strong>Observação:</strong> <?= nl2br(e(campaign_text($campaign, 'seller_note'))) ?></p>

                    <div class="label-row">
                        <label for="customers">Clientes e regiões</label>
                        <span id="line-count">0 clientes</span>
                    </div>
                    <textarea id="customers" name="customers" rows="10" placeholder="Pedro de Fortim&#10;Raquel de Fortaleza" required><?= e($customersText) ?></textarea>
                    <div class="format-help">
                        <strong>Um cliente por linha</strong>
                        <span>Exemplo: Pedro de Fortim</span>
                    </div>
                    <p class="customer-instructions"><?= nl2br(e(campaign_text($campaign, 'customer_instructions'))) ?></p>
                    <p class="raffle-notice">Para o sorteio, confira se cada linha contém o nome completo do cliente e sua região. O envio não será bloqueado se o formato estiver diferente.</p>

                    <div id="preview" class="preview" hidden></div>
                    <button class="primary-button public-submit" type="submit">Enviar cadastro</button>
                </form>
            <?php else: ?>
                <div class="closed-state">
                    <h2>Cadastros encerrados</h2>
                    <p>Não há cadastro liberado no momento.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<script src="assets/app.js"></script>
</body>
</html>
