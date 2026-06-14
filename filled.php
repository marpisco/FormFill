<?php
/**
 * FormFill — Form Submission Processor
 * 
 * Processes a submitted form, generates PDF, handles optional digital signing.
 * 
 * Flow with signing required: fill → get PDF → sign externally → upload → verify → complete
 * Flow without signing:      fill → get PDF → complete immediately
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

// ─── Shared: Load form and user ─────────────────────────────────────────────
global $db;

$signError = null;
$signSuccess = null;
$action = $_GET['action'] ?? '';

// ─── Document Signing Upload (handled before main submission) ────────────────
if ($action === 'sign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $respostaId = $_POST['resposta_id'] ?? '';
    $formId = $_POST['form_id'] ?? '';

    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        $signError = 'Pedido inválido.';
    } elseif (empty($_FILES['signed_pdf']['tmp_name'])) {
        $signError = 'Nenhum ficheiro enviado.';
    } else {
        $uploadedPath = $_FILES['signed_pdf']['tmp_name'];
        // Verify the uploaded PDF contains a valid digital signature.
        // Extract CMS data from the PDF byte range and verify with openssl.
        $pdfContent = file_get_contents($uploadedPath);
        $isSigned = false;

        if ($pdfContent !== false && preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdfContent, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; $c = (int)$m[3]; $d = (int)$m[4];
            // Extract CMS signature from byte range: bytes [a .. b-1]
            $cmsData = substr($pdfContent, $a, $b);
            // Signed data: bytes [0 .. a-1] + [c .. c+d-1]
            $signedData = substr($pdfContent, 0, $a) . substr($pdfContent, $c, $d);
            // Write extracted data to temp files for openssl
            $tmpCms = tempnam(sys_get_temp_dir(), 'pdfsig_');
            $tmpData = tempnam(sys_get_temp_dir(), 'pdfdat_');
            if ($tmpCms && $tmpData) {
                file_put_contents($tmpCms, $cmsData);
                file_put_contents($tmpData, $signedData);
                // Verify CMS signature against signed data
                $sigOut = $tmpCms . '.out';
                $isSigned = @openssl_cms_verify($tmpData, OPENSSL_CMS_NOVERIFY, null, [], $tmpCms) 
                         || @openssl_pkcs7_verify($tmpData, PKCS7_NOVERIFY, $sigOut, [], $tmpCms);
                @unlink($tmpData);
                @unlink($tmpCms);
                @unlink($sigOut);
            }
        }

        if ($isSigned) {
            // Verify the response exists, belongs to user, and the form requires signing
            $stmt = $db->prepare(
                "SELECT r.pdf_path, r.form_id, r.respondido, r.signing_pending 
                 FROM respostas r JOIN forms f ON r.form_id = f.id 
                 WHERE r.id = ? AND r.enviador_id = ? AND f.requires_signature = TRUE"
            );
            $stmt->bind_param("ss", $respostaId, $_SESSION['id']);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$existing) {
                $signError = 'Este formulário não requer assinatura digital ou a resposta não foi encontrada.';
            } elseif (empty($existing['signing_pending'])) {
                $signError = 'Este documento já foi assinado e submetido.';
            } else {
                $signedRelPath = 'filledforms/' . date('Ymd') . '_' . Validator::uuid4() . '_signed.pdf';
                $signedAbsPath = __DIR__ . '/' . $signedRelPath;

                if (move_uploaded_file($uploadedPath, $signedAbsPath)) {
                    $db->query("UPDATE respostas SET pdf_path = '" . $db->real_escape_string($signedRelPath) . "', signing_pending = FALSE WHERE id = '" . $db->real_escape_string($respostaId) . "'");

                    // Send confirmation email with full placeholder substitution
                    $form = FormBuilder::get($existing['form_id']);
                    if ($form) {
                        // Load user and submission data for placeholder substitution
                        $uStmt = $db->prepare("SELECT nome, email FROM cache WHERE id = ?");
                        $uStmt->bind_param("s", $_SESSION['id']);
                        $uStmt->execute();
                        $user = $uStmt->get_result()->fetch_assoc();
                        $uStmt->close();

                        // Load stored field values for &field& placeholders
                        $dStmt = $db->prepare("SELECT dados FROM respostas WHERE id = ?");
                        $dStmt->bind_param("s", $respostaId);
                        $dStmt->execute();
                        $dRow = $dStmt->get_result()->fetch_assoc();
                        $dStmt->close();
                        $fieldValues = $dRow && $dRow['dados'] ? json_decode($dRow['dados'], true) : [];

                        if ($user) {
                            $nome = explode(' ', $user['nome'])[0];
                            $emailBody = $form['email']['confirmacao'] ?? '';
                            $emailBody = str_replace('#data#', date('d/m/Y'), $emailBody);
                            $emailBody = str_replace('§nomecompleto§', $user['nome'], $emailBody);
                            $emailBody = str_replace('§nome§', $nome, $emailBody);
                            $emailBody = str_replace('§id§', $_SESSION['id'], $emailBody);
                            $emailBody = str_replace('§email§', $user['email'], $emailBody);
                            foreach ((array)$fieldValues as $fid => $fval) {
                                $emailBody = str_replace("&{$fid}&", $fval, $emailBody);
                            }
                            Mailer::sendFormConfirmation($user['email'], $nome, $form['email']['assuntoconfirmacao'] ?? 'Confirmação', $emailBody, $signedAbsPath);
                        }
                    }

                    Logger::log("Documento assinado verificado: {$respostaId}");
                    $signSuccess = 'Documento assinado verificado com sucesso!';
                } else {
                    $signError = 'Erro ao guardar o documento.';
                }
            }
        } else {
            $signError = 'O documento não contém uma assinatura digital válida. Assine o PDF através do autenticacao.gov.pt.';
        }
    }
}

// ─── Main Form Submission ────────────────────────────────────────────────────
$submitted = false;
$pdfRelPath = null;
$pdfAbsPath = null;
$requiresSignature = false;
$form = null;

if ($action !== 'sign' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_id'])) {
    if (!Csrf::verify($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die("Pedido inválido.");
    }

    $formId = $_POST['form_id'];
    $form = FormBuilder::get($formId);
    if (!$form || !FormBuilder::canAccess($formId, $_SESSION['id'])) {
        http_response_code(404);
        die("Formulário não encontrado.");
    }

    // Reject submissions for disabled forms
    if (empty($form['ativado'])) {
        http_response_code(403);
        die("Este formulário não está ativo.");
    }

    $requiresSignature = !empty($form['requires_signature']);

    $stmt = $db->prepare("SELECT id, nome, email FROM cache WHERE id = ?");
    $stmt->bind_param("s", $_SESSION['id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nomeCompleto = $user['nome'];
    $nome = explode(' ', $nomeCompleto)[0];

    $fieldValues = [];
    $validationErrors = [];
    foreach ($form['campos'] as $campo) {
        $idcampo = $campo['idcampo'];
        $tipo = $campo['tipo'] ?? 'text';
        $v = '';

        if ($tipo === 'file') {
            $fileInfo = $_FILES[$idcampo] ?? null;
            if ($fileInfo && ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $v = $fileInfo['name'];
            }
        } else {
            $v = $_POST[$idcampo] ?? '';
            if (is_array($v)) $v = implode(', ', $v);
        }

        // Server-side validation of required fields
        $isEmpty = is_string($v) ? trim($v) === '' : empty($v);
        if ($tipo === 'file') {
            $isEmpty = empty($_FILES[$idcampo]['tmp_name']) || ($_FILES[$idcampo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK;
        }
        if (!empty($campo['obrigatorio']) && $isEmpty) {
            $validationErrors[] = "O campo '" . ($campo['descricao'] ?? $idcampo) . "' é obrigatório.";
        }

        // Validate select/radio/checkbox against configured options
        if (in_array($tipo, ['select', 'radio', 'checkbox'], true) && !empty($campo['opcoes']) && !empty(trim((string)$v))) {
            $allowed = is_array($campo['opcoes']) ? $campo['opcoes'] : explode(',', $campo['opcoes']);
            $allowed = array_map('trim', $allowed);
            $values = ($tipo === 'checkbox' && is_array($_POST[$idcampo] ?? null)) ? $_POST[$idcampo] : [$v];
            foreach ($values as $val) {
                if (!empty(trim((string)$val)) && !in_array(trim((string)$val), $allowed, true)) {
                    $validationErrors[] = "O campo '" . ($campo['descricao'] ?? $idcampo) . "' contém um valor inválido.";
                    break;
                }
            }
        }

        $fieldValues[$idcampo] = $v;
    }

    if (!empty($validationErrors)) {
        http_response_code(400);
        die("Erros de validação:\n" . implode("\n", $validationErrors));
    }

    $substitute = function(string $t) use ($nomeCompleto, $nome, $user, $fieldValues): string {
        $t = str_replace('#data#', date('d/m/Y'), $t);
        $t = str_replace('§nomecompleto§', $nomeCompleto, $t);
        $t = str_replace('§nome§', $nome, $t);
        $t = str_replace('§id§', $user['id'], $t);
        $t = str_replace('§email§', $user['email'], $t);
        foreach ($fieldValues as $fid => $fval) {
            $t = str_replace("&{$fid}&", $fval, $t);
        }
        return $t;
    };

    $texto = $substitute($form['doc']['texto'] ?? '');
    $criarDoc = $form['doc']['criar'] ?? false;
    // Force PDF generation when digital signing is required
    if ($requiresSignature && !$criarDoc) {
        $criarDoc = true;
    }

    if ($criarDoc) {
        $pdfDir = __DIR__ . '/filledforms';
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        class FormFillPDF extends \Fpdf\Fpdf {
            public function criarDocumento(string $titulo, string $texto): string {
                $this->AddPage(); $this->SetXY(10, 40);
                $la = __DIR__ . '/assets/img/logoaejics.png';
                $lm = __DIR__ . '/assets/img/logominedu.png';
                if (file_exists($la)) $this->Image($la, 10, 10, 20);
                if (file_exists($lm)) $this->Image($lm, 130, 15, 65);
                $this->SetFont('Arial', 'B', 20);
                $this->Cell(0, 10, self::enc($titulo), 0, 1, 'C'); $this->Ln(5);
                $this->SetFont('Arial', '', 12);
                $this->MultiCell(0, 8, self::enc($texto), 0, 'J');
                $rp = 'filledforms/' . date('Ymd') . '_' . Validator::uuid4() . '.pdf';
                $this->Output('F', __DIR__ . '/' . $rp);
                return $rp;
            }
            private static function enc(string $t): string {
                $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $t);
                return $c !== false ? $c : preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '?', $t);
            }
        }

        $pdf = new FormFillPDF();
        $pdfRelPath = $pdf->criarDocumento($form['nome'], $texto);
        $pdfAbsPath = __DIR__ . '/' . $pdfRelPath;
    }

    // Handle file uploads — move to storage outside web root
    $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
    foreach ($form['campos'] as $campo) {
        if (($campo['tipo'] ?? '') === 'file' && !empty($_FILES[$campo['idcampo']]['tmp_name'])) {
            $fileInfo = $_FILES[$campo['idcampo']];
            // Validate upload succeeded
            if (($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $origName = basename($fileInfo['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                continue;
            }
            $uploadsDir = __DIR__ . '/../data/uploads';
            if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
            $storedName = date('Ymd') . '_' . Validator::uuid4() . '_' . $origName;
            $destPath = $uploadsDir . '/' . $storedName;
            if (move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
                $fieldValues[$campo['idcampo']] = 'data/uploads/' . $storedName;
            }
        }
    }

    $respostaId = Validator::uuid4();
    $dadosJson = json_encode($fieldValues, JSON_UNESCAPED_UNICODE);
    $signingPending = $requiresSignature ? 1 : 0;
    $stmt = $db->prepare("INSERT INTO respostas (id, form_id, enviador_id, pdf_path, dados, respondido, signing_pending) VALUES (?, ?, ?, ?, ?, FALSE, ?)");
    if ($stmt) {
        $stmt->bind_param("sssssi", $respostaId, $formId, $_SESSION['id'], $pdfRelPath ?? '', $dadosJson, $signingPending);
        $stmt->execute();
        $stmt->close();
    }

    // If no signing required, send email immediately
    if (!$requiresSignature && $pdfAbsPath && file_exists($pdfAbsPath)) {
        $emailBody = $substitute($form['email']['confirmacao'] ?? '');
        Mailer::sendFormConfirmation($user['email'], $nome, $form['email']['assuntoconfirmacao'] ?? 'Confirmação', $emailBody, $pdfAbsPath);
    } elseif (!$requiresSignature && !$criarDoc) {
        // No PDF generated — send confirmation without attachment
        $emailBody = $substitute($form['email']['confirmacao'] ?? '');
        Mailer::sendFormConfirmation($user['email'], $nome, $form['email']['assuntoconfirmacao'] ?? 'Confirmação', $emailBody, '');
    }

    Logger::log("Formulário preenchido: {$form['nome']} [{$formId}]" . ($requiresSignature ? ' (assinatura pendente)' : ''));

    $submitted = true;
}

// ─── Render ──────────────────────────────────────────────────────────────────
if (!$submitted && !$signSuccess && !$signError) { exit(); } // only render after submit

// When rendering sign errors, reload form context for the signing form
if ($signError && empty($form) && !empty($_POST['resposta_id'])) {
    $stmt = $db->prepare("SELECT r.form_id, r.pdf_path, f.requires_signature
        FROM respostas r JOIN forms f ON r.form_id = f.id
        WHERE r.id = ? AND r.enviador_id = ?");
    $stmt->bind_param("ss", $_POST['resposta_id'], $_SESSION['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $requiresSignature = !empty($row['requires_signature']);
        $pdfRelPath = $row['pdf_path'];
    }
}

$brand = Config::brandName();
?>
<!DOCTYPE html>
<html lang="pt" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submetido — <?= htmlspecialchars($brand) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{colors:{brand:{600:'#4f46e5',700:'#4338ca'}}}}}</script>
    <script>if(window.matchMedia('(prefers-color-scheme:dark)').matches||localStorage.getItem('theme')==='dark'){document.documentElement.classList.add('dark')}</script>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-emerald-50 via-white to-teal-50 dark:from-slate-950 dark:via-slate-900 dark:to-emerald-950">
<div class="w-full max-w-lg text-center">
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-lg border border-slate-200 dark:border-slate-700 p-8">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/30 mb-4">
        <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    </div>

    <?php if ($signSuccess): ?>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Documento assinado!</h1>
        <p class="text-slate-500 dark:text-slate-400 mt-2">O seu documento assinado foi verificado e submetido.</p>
    <?php elseif ($requiresSignature): ?>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Quase pronto!</h1>
        <p class="text-slate-500 dark:text-slate-400 mt-2">Descarregue o PDF, assine-o e faça upload do documento assinado.</p>
    <?php else: ?>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Formulário submetido!</h1>
        <p class="text-slate-500 dark:text-slate-400 mt-2">Foi enviada uma cópia para o seu email.</p>
    <?php endif; ?>

    <?php if ($pdfRelPath): ?>
    <div class="mt-6 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
        <iframe src="/<?= htmlspecialchars($pdfRelPath) ?>" class="w-full h-80" style="border:none"></iframe>
    </div>

    <?php if ($requiresSignature && !$signSuccess): ?>
    <!-- Signing required — show download + upload -->
    <div class="mt-4 flex gap-3 justify-center">
        <a href="/<?= htmlspecialchars($pdfRelPath) ?>" download class="py-2 px-4 text-sm border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Descarregar PDF
        </a>
    </div>

    <div class="mt-6 border-t border-slate-200 dark:border-slate-700 pt-6 text-left">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">Assinar documento</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
            1. Descarregue o PDF acima<br>
            2. Assine-o através do <a href="https://autenticacao.gov.pt" target="_blank" rel="noopener" class="text-brand-600 dark:text-brand-400 underline">autenticacao.gov.pt</a><br>
            3. Faça upload do ficheiro assinado
        </p>
        <?php if ($signError): ?>
        <div class="mb-3 px-3 py-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg"><p class="text-red-700 dark:text-red-300 text-xs"><?= htmlspecialchars($signError) ?></p></div>
        <?php endif; ?>
        <form method="POST" action="?action=sign" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="resposta_id" value="<?= htmlspecialchars($respostaId ?? '') ?>">
            <input type="hidden" name="form_id" value="<?= htmlspecialchars($formId ?? '') ?>">
            <input type="file" name="signed_pdf" accept=".pdf" required class="flex-1 text-xs text-slate-600 dark:text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-brand-50 dark:file:bg-brand-900 file:text-brand-700 dark:file:text-brand-300">
            <button type="submit" class="py-2 px-4 bg-brand-600 hover:bg-brand-700 text-white text-xs font-medium rounded-lg transition whitespace-nowrap">Enviar documento assinado</button>
        </form>
    </div>
    <?php else: ?>
    <div class="mt-4">
        <a href="/<?= htmlspecialchars($pdfRelPath) ?>" download class="py-2 px-4 text-sm border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Descarregar PDF
        </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <a href="/" class="inline-block mt-6 py-2.5 px-6 bg-brand-600 hover:bg-brand-700 text-white font-medium rounded-lg transition">Voltar ao início</a>
</div></div>
<?= Csrf::globalInjector() ?>
</body></html>
