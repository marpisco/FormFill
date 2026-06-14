<?php
/**
 * FormFill — Form Submission Processor
 * 
 * Processes a submitted form: validates CSRF, generates PDF via FPDF,
 * stores the response, and sends a confirmation email.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/db.php';

use FormFill\Lib\Session;
use FormFill\Lib\Csrf;
use FormFill\Lib\Config;
use FormFill\Lib\FormBuilder;
use FormFill\Lib\Mailer;
use FormFill\Lib\Validator;
use FormFill\Lib\Logger;

Session::requireLogin();

// ─── CSRF Verification ──────────────────────────────────────────────────────
if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die("<div class='p-8 text-center text-red-500'>Pedido inválido. Atualize a página e tente novamente.</div>");
}

// ─── Load Form Definition ───────────────────────────────────────────────────
$formId = $_POST['form_id'] ?? '';
if (empty($formId)) {
    die("Formulário não especificado.");
}

$form = FormBuilder::get($formId);
if (!$form) {
    http_response_code(404);
    die("Formulário não encontrado.");
}

// ─── Gather User Data ───────────────────────────────────────────────────────
global $db;
$stmt = $db->prepare("SELECT id, nome, email FROM cache WHERE id = ?");
$stmt->bind_param("s", $_SESSION['id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Utilizador não encontrado.");
}

$nomeCompleto = $user['nome'];
$nome = explode(' ', $nomeCompleto)[0];
$id = $user['id'];
$email = $user['email'];

// ─── Template Substitution ──────────────────────────────────────────────────
$texto = $form['doc']['texto'] ?? '';
$criarDoc = $form['doc']['criar'] ?? false;

// System placeholders (#data#)
$texto = str_replace('#data#', date('d/m/Y'), $texto);

// User placeholders (§nome§, §nomecompleto§, §id§, §email§)
$texto = str_replace('§nomecompleto§', $nomeCompleto, $texto);
$texto = str_replace('§nome§', $nome, $texto);
$texto = str_replace('§id§', $id, $texto);
$texto = str_replace('§email§', $email, $texto);

// Field placeholders (&fieldid&)
foreach ($form['campos'] as $campo) {
    $fieldId = $campo['idcampo'];
    $fieldValue = $_POST[$fieldId] ?? '';

    if (is_array($fieldValue)) {
        $fieldValue = implode(', ', $fieldValue);
    }

    $texto = str_replace("&{$fieldId}&", $fieldValue, $texto);
}

// ─── Email Body Preparation ─────────────────────────────────────────────────
$emailBody = $form['email']['confirmacao'] ?? '';
$emailBody = str_replace('#data#', date('d/m/Y'), $emailBody);
$emailBody = str_replace('§nomecompleto§', $nomeCompleto, $emailBody);
$emailBody = str_replace('§nome§', $nome, $emailBody);

// ─── PDF Generation ─────────────────────────────────────────────────────────
$pdfPath = null;
if ($criarDoc) {
    $pdfDir = __DIR__ . '/filledforms';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    class FormFillPDF extends \FPDF
    {
        public function criarDocumento(string $titulo, string $texto, array $form): string
        {
            $this->AddPage();
            $this->SetXY(10, 40);

            // Logos
            $logoAejics = __DIR__ . '/assets/img/logoaejics.png';
            $logoMinedu = __DIR__ . '/assets/img/logominedu.png';
            if (file_exists($logoAejics)) {
                $this->Image($logoAejics, 10, 10, 20);
            }
            if (file_exists($logoMinedu)) {
                $this->Image($logoMinedu, 130, 15, 65);
            }

            // Title
            $this->SetFont('Arial', 'B', 20);
            $this->Cell(0, 10, $titulo, 0, 1, 'C');
            $this->Ln(5);

            // Body
            $this->SetFont('Arial', '', 12);
            $this->MultiCell(0, 8, $texto, 0, 'J');

            $filename = $pdfDir = __DIR__ . '/filledforms/' . date('YmdHisv') . '.pdf';
            $this->Output('F', $filename);
            return $filename;
        }
    }

    $pdf = new FormFillPDF();
    $pdfPath = $pdf->criarDocumento($form['nome'], $texto, $form);
}

// ─── Store Response ─────────────────────────────────────────────────────────
$respostaId = Validator::uuid4();
$stmt = $db->prepare("INSERT INTO respostas (id, form_id, enviador_id, pdf_path, respondido) VALUES (?, ?, ?, ?, FALSE)");
if ($stmt) {
    $pdfPathDb = $pdfPath ?? '';
    $stmt->bind_param("ssss", $respostaId, $formId, $_SESSION['id'], $pdfPathDb);
    $stmt->execute();
    $stmt->close();
}

// ─── Send Confirmation Email ────────────────────────────────────────────────
if ($pdfPath) {
    Mailer::sendFormConfirmation(
        $email,
        $nome,
        $form['email']['assuntoconfirmacao'] ?? 'Confirmação de formulário',
        $emailBody,
        $pdfPath
    );
}

// ─── Log ────────────────────────────────────────────────────────────────────
Logger::log("Formulário preenchido: {$form['nome']} [{$formId}]", ['form_id' => $formId]);

// ─── Render Success Page ────────────────────────────────────────────────────
$brand = Config::brandName();
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submetido — <?= htmlspecialchars($brand) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { colors: { brand: { 600: '#4f46e5', 700: '#4338ca' } } } }
        }
    </script>
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches || localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-emerald-50 via-white to-teal-50 dark:from-slate-950 dark:via-slate-900 dark:to-emerald-950">
    <div class="w-full max-w-lg text-center">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-slate-200 dark:border-slate-700 p-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/30 mb-4">
                <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Formulário submetido!</h1>
            <p class="text-slate-500 dark:text-slate-400 mt-2">
                <?= htmlspecialchars($form['email']['confirmacao'] ?? 'Obrigado pelo seu envio.') ?>
            </p>
            <p class="text-sm text-slate-400 dark:text-slate-500 mt-4">
                Foi enviada uma cópia do documento para o seu email.
            </p>
            <?php if ($pdfPath): ?>
            <div class="mt-6 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <iframe src="/<?= str_replace(__DIR__ . '/', '', $pdfPath) ?>" 
                        class="w-full h-96" style="border: none;"></iframe>
            </div>
            <?php endif; ?>
            <a href="/" class="inline-block mt-6 py-2.5 px-6 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition">
                Voltar ao início
            </a>
        </div>
    </div>
    <?= Csrf::globalInjector() ?>
</body>
</html>
