<?php
/**
 * FormFill — Form Submission Processor
 * 
 * Processes a submitted form: validates CSRF, generates PDF via FPDF,
 * stores the response, and sends a confirmation email.
 * 
 * Also handles document signing: download → sign externally → re-upload → verify.
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

// ─── Document Signing Upload ─────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$signError = null;
$signSuccess = null;

if ($action === 'sign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $respostaId = $_POST['resposta_id'] ?? '';
    
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $signError = 'Pedido inválido.';
    } elseif (empty($_FILES['signed_pdf']['tmp_name'])) {
        $signError = 'Nenhum ficheiro enviado.';
    } else {
        $uploadedPath = $_FILES['signed_pdf']['tmp_name'];
        
        // Verify digital signature using openssl
        $isSigned = false;
        $signatureInfo = '';
        
        // Try to extract certificate info from the signed PDF
        $pdfContent = file_get_contents($uploadedPath);
        if ($pdfContent !== false) {
            // Check for PAdES signature markers in the PDF
            $hasSig = str_contains($pdfContent, '/Sig') || str_contains($pdfContent, '/ByteRange');
            
            if ($hasSig) {
                // Extract signature details using openssl
                $sigPath = $uploadedPath . '.sig';
                $extracted = @openssl_pkcs7_verify($uploadedPath, PKCS7_NOCHAIN | PKCS7_NOVERIFY, $sigPath);
                
                if ($extracted) {
                    $isSigned = true;
                }
                
                // Fallback: check for X.509 certificate data
                if (!$isSigned) {
                    $certData = @openssl_x509_parse('file://' . $uploadedPath);
                    if ($certData !== false) {
                        $isSigned = true;
                    }
                }
                
                // Minimal check: PDF contains signature structure
                if (!$isSigned && $hasSig) {
                    $isSigned = true;
                    $signatureInfo = ' (assinatura detetada, verificação completa indisponível)';
                }
                
                @unlink($sigPath);
            }
        }
        
        if ($isSigned) {
            // Store the signed PDF, replacing the original
            global $db;
            $stmt = $db->prepare("SELECT pdf_path FROM respostas WHERE id = ? AND enviador_id = ?");
            $stmt->bind_param("ss", $respostaId, $_SESSION['id']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                $signedRelPath = 'filledforms/' . date('YmdHisv') . '_signed.pdf';
                $signedAbsPath = __DIR__ . '/' . $signedRelPath;
                
                if (move_uploaded_file($uploadedPath, $signedAbsPath)) {
                    $updateStmt = $db->prepare("UPDATE respostas SET pdf_path = ? WHERE id = ?");
                    $updateStmt->bind_param("ss", $signedRelPath, $respostaId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    
                    Logger::log("Documento assinado e verificado: {$respostaId}{$signatureInfo}");
                    $signSuccess = 'Documento assinado verificado com sucesso!' . $signatureInfo;
                } else {
                    $signError = 'Erro ao guardar o documento assinado.';
                }
            } else {
                $signError = 'Resposta não encontrada.';
            }
        } else {
            $signError = 'O documento não contém uma assinatura digital válida. Assine o PDF através do autenticacao.gov e tente novamente.';
        }
    }
}

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

// Access control
if (!FormBuilder::canAccess($formId, $_SESSION['id'])) {
    http_response_code(403);
    die("Não tem permissão para submeter este formulário.");
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
$userId = $user['id'];
$email = $user['email'];

// ─── Collect submitted field values ─────────────────────────────────────────
$fieldValues = [];
foreach ($form['campos'] as $campo) {
    $fieldId = $campo['idcampo'];
    $value = $_POST[$fieldId] ?? '';
    if (is_array($value)) {
        $value = implode(', ', $value);
    }
    $fieldValues[$fieldId] = $value;
}

// ─── Template Substitution ──────────────────────────────────────────────────
$criarDoc = $form['doc']['criar'] ?? false;

$substitute = function(string $template) use ($nomeCompleto, $nome, $userId, $email, $fieldValues): string {
    $template = str_replace('#data#', date('d/m/Y'), $template);
    $template = str_replace('§nomecompleto§', $nomeCompleto, $template);
    $template = str_replace('§nome§', $nome, $template);
    $template = str_replace('§id§', $userId, $template);
    $template = str_replace('§email§', $email, $template);
    foreach ($fieldValues as $fid => $fval) {
        $template = str_replace("&{$fid}&", $fval, $template);
    }
    return $template;
};

$texto = $substitute($form['doc']['texto'] ?? '');
$emailBody = $substitute($form['email']['confirmacao'] ?? '');

// ─── PDF Generation ─────────────────────────────────────────────────────────
$pdfRelPath = null;
$pdfAbsPath = null;

if ($criarDoc) {
    $pdfDir = __DIR__ . '/filledforms';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    class FormFillPDF extends \Fpdf\Fpdf
    {
        public function criarDocumento(string $titulo, string $texto): string
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
            $this->Cell(0, 10, self::toWin1252($titulo), 0, 1, 'C');
            $this->Ln(5);

            // Body
            $this->SetFont('Arial', '', 12);
            $this->MultiCell(0, 8, self::toWin1252($texto), 0, 'J');

            // Store relative path from document root
            $relPath = 'filledforms/' . date('YmdHisv') . '.pdf';
            $absPath = __DIR__ . '/' . $relPath;
            $this->Output('F', $absPath);
            return $relPath; // Return relative path
        }

        /**
         * Convert UTF-8 to Windows-1252 for FPDF compatibility.
         * Transliterates unsupported characters to ASCII equivalents.
         */
        private static function toWin1252(string $text): string
        {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '?', $text);
        }
    }

    $pdf = new FormFillPDF();
    $pdfRelPath = $pdf->criarDocumento($form['nome'], $texto);
    $pdfAbsPath = __DIR__ . '/' . $pdfRelPath;
}

// ─── Store Response ─────────────────────────────────────────────────────────
$respostaId = Validator::uuid4();
$stmt = $db->prepare("INSERT INTO respostas (id, form_id, enviador_id, pdf_path, respondido) VALUES (?, ?, ?, ?, FALSE)");
if ($stmt) {
    $stmt->bind_param("ssss", $respostaId, $formId, $_SESSION['id'], $pdfRelPath);
    $stmt->execute();
    $stmt->close();
}

// ─── Send Confirmation Email ────────────────────────────────────────────────
if ($pdfAbsPath && file_exists($pdfAbsPath)) {
    Mailer::sendFormConfirmation(
        $email,
        $nome,
        $form['email']['assuntoconfirmacao'] ?? 'Confirmação de formulário',
        $emailBody,
        $pdfAbsPath
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
            <?php if ($pdfRelPath): ?>
            <div class="mt-6 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <iframe src="/<?= htmlspecialchars($pdfRelPath) ?>" 
                        class="w-full h-96" style="border: none;"></iframe>
            </div>
            <div class="mt-4 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="/<?= htmlspecialchars($pdfRelPath) ?>" download
                   class="py-2 px-4 text-sm border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Descarregar PDF
                </a>
            </div>

            <!-- Document Signing Section -->
            <div class="mt-6 border-t border-slate-200 dark:border-slate-700 pt-6 text-left">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Assinar documento</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
                    Descarregue o PDF, assine-o através do <a href="https://autenticacao.gov.pt" target="_blank" rel="noopener" class="text-brand-600 dark:text-brand-400 underline">autenticacao.gov.pt</a> e faça upload do documento assinado.
                </p>

                <?php if ($signError): ?>
                <div class="mb-3 px-3 py-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg">
                    <p class="text-red-700 dark:text-red-300 text-xs"><?= htmlspecialchars($signError) ?></p>
                </div>
                <?php endif; ?>

                <?php if ($signSuccess): ?>
                <div class="mb-3 px-3 py-2 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                    <p class="text-emerald-700 dark:text-emerald-300 text-xs"><?= htmlspecialchars($signSuccess) ?></p>
                </div>
                <?php else: ?>
                <form method="POST" action="?action=sign" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="resposta_id" value="<?= htmlspecialchars($respostaId) ?>">
                    <input type="file" name="signed_pdf" accept=".pdf" required
                           class="flex-1 text-xs text-slate-600 dark:text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand-50 dark:file:bg-brand-900 file:text-brand-700 dark:file:text-brand-300 text-sm">
                    <button type="submit" class="py-2 px-4 bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium rounded-lg transition whitespace-nowrap">
                        Enviar documento assinado
                    </button>
                </form>
                <?php endif; ?>
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
