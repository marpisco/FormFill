<?php
/**
 * FormFill Form CRUD Operations
 * 
 * Manages form definitions and access control in the database.
 */

namespace FormFill\Lib;

class FormBuilder
{
    // Privacy constants
    public const PRIVACY_PUBLIC   = 0;
    public const PRIVACY_INTERNAL = 1;
    public const PRIVACY_PRIVATE  = 2;

    /**
     * List all forms, optionally including disabled ones.
     * For admin use — no access filtering.
     */
    public static function list(bool $includeDisabled = false): array
    {
        global $db;

        $sql = "SELECT f.id, f.nome, f.ativado, f.descricao, f.privacidade, f.criado_em, 
                       c.nome AS criador_nome,
                       (SELECT COUNT(*) FROM respostas r WHERE r.form_id = f.id) AS total_respostas
                FROM forms f
                LEFT JOIN cache c ON f.criado_por = c.id";
        
        if (!$includeDisabled) {
            $sql .= " WHERE f.ativado = TRUE";
        }
        
        $sql .= " ORDER BY f.criado_em DESC";

        $result = $db->query($sql);
        if (!$result) return [];

        $forms = [];
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row;
        }
        return $forms;
    }

    /**
     * List forms that a specific user is allowed to see.
     * Respects privacy levels: public (all), internal (matching domain), private (access list).
     */
    public static function listForUser(string $userId): array
    {
        global $db;

        $userStmt = $db->prepare("SELECT email FROM cache WHERE id = ?");
        $userStmt->bind_param("s", $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user) return [];

        $userEmail = $user['email'];
        $internalDomain = Config::get('internal_email_domain', '');

        // Build the query with all three privacy levels
        $sql = "SELECT f.id, f.nome, f.ativado, f.descricao, f.privacidade, f.criado_em,
                       (SELECT COUNT(*) FROM respostas r WHERE r.form_id = f.id) AS total_respostas
                FROM forms f
                WHERE f.ativado = TRUE
                AND (
                    f.privacidade = 0
                    OR (f.privacidade = 1 AND ? != '' AND ? LIKE CONCAT('%@', ?))
                    OR (f.privacidade = 2 AND EXISTS (
                        SELECT 1 FROM forms_access fa WHERE fa.form_id = f.id AND fa.user_id = ?
                    ))
                )
                ORDER BY f.criado_em DESC";

        $stmt = $db->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param("ssss", $internalDomain, $userEmail, $internalDomain, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $forms = [];
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row;
        }
        return $forms;
    }

    /**
     * Check if a user can access a specific form.
     */
    public static function canAccess(string $formId, string $userId): bool
    {
        global $db;

        // Get form privacy level
        $stmt = $db->prepare("SELECT privacidade FROM forms WHERE id = ? AND ativado = TRUE");
        $stmt->bind_param("s", $formId);
        $stmt->execute();
        $form = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$form) return false;

        $privacidade = (int)$form['privacidade'];

        // Public — everyone
        if ($privacidade === self::PRIVACY_PUBLIC) return true;

        // Get user email
        $userStmt = $db->prepare("SELECT email FROM cache WHERE id = ?");
        $userStmt->bind_param("s", $userId);
        $userStmt->execute();
        $user = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$user) return false;

        // Internal — same domain
        if ($privacidade === self::PRIVACY_INTERNAL) {
            $internalDomain = Config::get('internal_email_domain', '');
            if (empty($internalDomain)) return false;
            return str_ends_with(strtolower($user['email']), '@' . strtolower($internalDomain));
        }

        // Private — access list
        if ($privacidade === self::PRIVACY_PRIVATE) {
            $accessStmt = $db->prepare("SELECT 1 FROM forms_access WHERE form_id = ? AND user_id = ?");
            $accessStmt->bind_param("ss", $formId, $userId);
            $accessStmt->execute();
            $hasAccess = $accessStmt->get_result()->num_rows > 0;
            $accessStmt->close();
            return $hasAccess;
        }

        return false;
    }

    /**
     * Get a single form by ID. Returns null if not found.
     */
    public static function get(string $id): ?array
    {
        global $db;
        $stmt = $db->prepare("SELECT * FROM forms WHERE id = ?");
        if (!$stmt) return null;

        $stmt->bind_param("s", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return null;

        // Decode JSON fields
        $row['campos'] = json_decode($row['campos'], true) ?? [];
        $row['doc'] = json_decode($row['doc'], true) ?? [];
        $row['email'] = json_decode($row['email'], true) ?? [];

        return $row;
    }

    /**
     * Create a new form. Returns the new form ID.
     */
    public static function create(array $data): string
    {
        global $db;

        $id = Validator::uuid4();
        $nome = $data['nome'] ?? 'Novo Formulário';
        $ativado = $data['ativado'] ?? true;
        $descricao = $data['descricao'] ?? '';
        $instrucoes = $data['instrucoes'] ?? '';
        $privacidade = (int)($data['privacidade'] ?? self::PRIVACY_PUBLIC);
        $requiresSignature = !empty($data['requires_signature']);
        $campos = json_encode($data['campos'] ?? [], JSON_UNESCAPED_UNICODE);
        $doc = json_encode($data['doc'] ?? ['criar' => true, 'texto' => ''], JSON_UNESCAPED_UNICODE);
        $email = json_encode($data['email'] ?? [
            'assuntoconfirmacao' => '',
            'confirmacao' => '',
            'assuntonotificacao' => '',
            'notificacao' => '',
        ], JSON_UNESCAPED_UNICODE);
        $criadoPor = $_SESSION['id'] ?? null;

        $stmt = $db->prepare(
            "INSERT INTO forms (id, nome, ativado, descricao, instrucoes, campos, doc, email, privacidade, requires_signature, criado_por) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("ssisssssiis", $id, $nome, $ativado, $descricao, $instrucoes, $campos, $doc, $email, $privacidade, $requiresSignature, $criadoPor);
            $stmt->execute();
            $stmt->close();
        }

        return $id;
    }

    /**
     * Update an existing form.
     */
    public static function update(string $id, array $data): bool
    {
        global $db;

        $nome = $data['nome'] ?? null;
        $ativado = $data['ativado'] ?? null;
        $descricao = $data['descricao'] ?? null;
        $instrucoes = $data['instrucoes'] ?? null;
        $privacidade = isset($data['privacidade']) ? (int)$data['privacidade'] : null;
        $requiresSignature = isset($data['requires_signature']) ? (int)!empty($data['requires_signature']) : null;
        $campos = isset($data['campos']) ? json_encode($data['campos'], JSON_UNESCAPED_UNICODE) : null;
        $doc = isset($data['doc']) ? json_encode($data['doc'], JSON_UNESCAPED_UNICODE) : null;
        $email = isset($data['email']) ? json_encode($data['email'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare(
            "UPDATE forms SET nome = ?, ativado = ?, descricao = ?, instrucoes = ?, privacidade = ?, requires_signature = ?, campos = ?, doc = ?, email = ? WHERE id = ?"
        );
        if (!$stmt) return false;

        $stmt->bind_param("sissiissss", $nome, $ativado, $descricao, $instrucoes, $privacidade, $requiresSignature, $campos, $doc, $email, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Delete a form and all associated data.
     */
    public static function delete(string $id): bool
    {
        global $db;

        // Delete associated PDF files
        $stmt = $db->prepare("SELECT pdf_path, dados FROM respostas WHERE form_id = ? AND (pdf_path IS NOT NULL AND pdf_path != '' OR dados IS NOT NULL)");
        if ($stmt) {
            $stmt->bind_param("s", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ($row['pdf_path'] && $row['pdf_path'] !== '') {
                    $pdfFile = __DIR__ . '/../../' . ltrim($row['pdf_path'], '/');
                    if (file_exists($pdfFile)) @unlink($pdfFile);
                }
                if ($row['dados']) {
                    $fieldValues = json_decode($row['dados'], true);
                    if (is_array($fieldValues)) {
                        foreach ($fieldValues as $val) {
                            if (is_string($val) && preg_match('#^(\.\./)?data/uploads/#', $val)) {
                                $uploadFile = realpath(__DIR__ . '/../../' . ltrim($val, '/'));
                                $uploadDir = realpath(__DIR__ . '/../../data/uploads');
                                if ($uploadFile && $uploadDir && str_starts_with($uploadFile, $uploadDir . DIRECTORY_SEPARATOR) && file_exists($uploadFile)) {
                                    @unlink($uploadFile);
                                }
                            }
                        }
                    }
                }
            }
            $stmt->close();
        }

        // Delete responses, access entries, then the form
        $delStmt = $db->prepare("DELETE FROM respostas WHERE form_id = ?");
        if ($delStmt) {
            $delStmt->bind_param("s", $id);
            $delStmt->execute();
            $delStmt->close();
        }
        $delStmt2 = $db->prepare("DELETE FROM forms_access WHERE form_id = ?");
        if ($delStmt2) {
            $delStmt2->bind_param("s", $id);
            $delStmt2->execute();
            $delStmt2->close();
        }

        $stmt = $db->prepare("DELETE FROM forms WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Toggle a form's enabled/disabled state.
     */
    public static function toggle(string $id): bool
    {
        global $db;
        $stmt = $db->prepare("UPDATE forms SET ativado = NOT ativado WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    // ─── Access List Management (for privacidade = 2) ─────────────────────

    /**
     * Get the list of users who have access to a private form.
     */
    public static function getAccessList(string $formId): array
    {
        global $db;
        $stmt = $db->prepare(
            "SELECT c.id, c.nome, c.email FROM forms_access fa 
             JOIN cache c ON fa.user_id = c.id 
             WHERE fa.form_id = ?
             ORDER BY c.nome"
        );
        if (!$stmt) return [];
        $stmt->bind_param("s", $formId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        return $users;
    }

    /**
     * Grant a user access to a private form.
     */
    public static function addAccess(string $formId, string $userId): bool
    {
        global $db;
        $stmt = $db->prepare("INSERT IGNORE INTO forms_access (form_id, user_id) VALUES (?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $formId, $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Revoke a user's access to a private form.
     */
    public static function removeAccess(string $formId, string $userId): bool
    {
        global $db;
        $stmt = $db->prepare("DELETE FROM forms_access WHERE form_id = ? AND user_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ss", $formId, $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Search users by name or email (for access list autocomplete).
     */
    public static function searchUsers(string $query): array
    {
        global $db;
        $q = "%{$query}%";
        $stmt = $db->prepare("SELECT id, nome, email FROM cache WHERE nome LIKE ? OR email LIKE ? ORDER BY nome LIMIT 20");
        if (!$stmt) return [];
        $stmt->bind_param("ss", $q, $q);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        return $users;
    }

    /**
     * Validate form data. Returns ['valid' => bool, 'errors' => []].
     */
    public static function validate(array $data): array
    {
        $errors = [];

        if (empty($data['nome'] ?? '')) {
            $errors[] = 'O nome do formulário é obrigatório.';
        }

        $campos = $data['campos'] ?? [];
        if (!is_array($campos) || empty($campos)) {
            $errors[] = 'O formulário precisa de pelo menos um campo.';
        }

        $seenIds = [];
        $reserved = ['form_id', 'csrf_token', 'step', 'action'];
        foreach ($campos as $i => $campo) {
            $idcampo = $campo['idcampo'] ?? '';
            if (empty($idcampo)) {
                $errors[] = "Campo #" . ($i + 1) . ": ID em falta.";
            } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $idcampo)) {
                $errors[] = "Campo #" . ($i + 1) . ": ID '{$idcampo}' inválido (use apenas letras, números e underscore, começando com letra).";
            } elseif (in_array($idcampo, $reserved, true)) {
                $errors[] = "Campo #" . ($i + 1) . ": ID '{$idcampo}' é reservado pelo sistema.";
            } elseif (isset($seenIds[$idcampo])) {
                $errors[] = "Campo #" . ($i + 1) . ": ID '{$idcampo}' duplicado (também usado no campo #" . $seenIds[$idcampo] . ").";
            } else {
                $seenIds[$idcampo] = $i + 1;
            }
            if (empty($campo['descricao'] ?? '')) {
                $errors[] = "Campo #" . ($i + 1) . ": Descrição em falta.";
            }
            if (empty($campo['tipo'] ?? '')) {
                $errors[] = "Campo #" . ($i + 1) . ": Tipo em falta.";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
