<?php
/**
 * FormFill Form CRUD Operations
 * 
 * Manages form definitions in the database. Replaces the old formlist/*.json approach.
 */

namespace FormFill\Lib;

class FormBuilder
{
    /**
     * List all forms, optionally including disabled ones.
     */
    public static function list(bool $includeDisabled = false): array
    {
        global $db;

        $sql = "SELECT f.id, f.nome, f.ativado, f.descricao, f.criado_em, 
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
            "INSERT INTO forms (id, nome, ativado, descricao, instrucoes, campos, doc, email, criado_por) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($stmt) {
            $stmt->bind_param("ssissssss", $id, $nome, $ativado, $descricao, $instrucoes, $campos, $doc, $email, $criadoPor);
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
        $campos = isset($data['campos']) ? json_encode($data['campos'], JSON_UNESCAPED_UNICODE) : null;
        $doc = isset($data['doc']) ? json_encode($data['doc'], JSON_UNESCAPED_UNICODE) : null;
        $email = isset($data['email']) ? json_encode($data['email'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare(
            "UPDATE forms SET nome = ?, ativado = ?, descricao = ?, instrucoes = ?, campos = ?, doc = ?, email = ? WHERE id = ?"
        );
        if (!$stmt) return false;

        $stmt->bind_param("sissssss", $nome, $ativado, $descricao, $instrucoes, $campos, $doc, $email, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Delete a form by ID.
     */
    public static function delete(string $id): bool
    {
        global $db;
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

        foreach ($campos as $i => $campo) {
            if (empty($campo['idcampo'] ?? '')) {
                $errors[] = "Campo #" . ($i + 1) . ": ID em falta.";
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
