<?php
declare(strict_types=1);

/* ===================== CONFIGURAÇÃO DO BANCO ===================== */
const DB_HOST = 'localhost';
const DB_NAME = 'formulario_db';
const DB_USER = 'root';
const DB_PASS = '';

/* ===================== FUNÇÕES AUXILIARES ===================== */

function limpar(?string $valor): string
{
    return trim(strip_tags($valor ?? ''));
}

function validarCPF(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int)$cpf[$i] * (($t + 1) - $i);
        }
        $digito = ((10 * $soma) % 11) % 10;
        if ((int)$cpf[$t] !== $digito) {
            return false;
        }
    }
    return true;
}

function renderizar(string $titulo, string $mensagem, array $itens = [], bool $sucesso = true): void
{
    $cor = $sucesso ? '#28a745' : '#dc3545';
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($titulo) . '</title>';
    echo '<style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}
        body{min-height:100vh;display:flex;justify-content:center;align-items:center;padding:20px;
             background:linear-gradient(135deg,#4facfe 0%,#00f2fe 50%,#43e97b 100%)}
        .card{width:100%;max-width:700px;background:#fff;padding:30px;border-radius:16px;
              box-shadow:0 10px 30px rgba(0,0,0,.2)}
        h1{color:' . $cor . ';margin-bottom:15px;font-size:22px}
        ul{margin:15px 0 20px 20px;color:#444;line-height:1.7}
        a{display:inline-block;padding:12px 25px;background:#4facfe;color:#fff;
          border-radius:8px;text-decoration:none}
    </style></head><body><div class="card">';
    echo '<h1>' . htmlspecialchars($titulo) . '</h1>';
    echo '<p>' . htmlspecialchars($mensagem) . '</p>';
    if ($itens) {
        echo '<ul>';
        foreach ($itens as $item) {
            echo '<li>' . htmlspecialchars($item) . '</li>';
        }
        echo '</ul>';
    }
    echo '<a href="javascript:history.back()">Voltar</a>';
    echo '</div></body></html>';
}

/* ===================== SÓ ACEITA POST ===================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    renderizar('Método não permitido', 'Acesse esta página enviando o formulário.', [], false);
    exit;
}

/* ===================== COLETA DOS DADOS ===================== */
$nome            = limpar($_POST['nome'] ?? '');
$dataNascimento  = limpar($_POST['data_nascimento'] ?? '');
$cpf             = limpar($_POST['cpf'] ?? '');
$email           = limpar($_POST['email'] ?? '');
$tipoEndereco    = limpar($_POST['tipo_endereco'] ?? '');
$logradouro      = limpar($_POST['logradouro_endereco'] ?? '');
$numero          = limpar($_POST['numero_endereco'] ?? '');
$complemento     = limpar($_POST['complemento_endereco'] ?? '');
$cep             = limpar($_POST['cep_endereco'] ?? '');

/* ===================== VALIDAÇÃO (lado do servidor) ===================== */
$erros = [];

if (mb_strlen($nome) < 3) {
    $erros[] = 'O nome deve ter pelo menos 3 caracteres.';
}

$data = DateTime::createFromFormat('Y-m-d', $dataNascimento);
if (!$data || $data->format('Y-m-d') !== $dataNascimento) {
    $erros[] = 'Data de nascimento inválida.';
} elseif ($data > new DateTime('today')) {
    $erros[] = 'A data de nascimento não pode estar no futuro.';
}

if (!preg_match('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/', $cpf) || !validarCPF($cpf)) {
    $erros[] = 'CPF inválido.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros[] = 'E-mail inválido.';
}

if (!in_array($tipoEndereco, ['residencial', 'comercial'], true)) {
    $erros[] = 'Tipo de endereço inválido.';
}

if ($logradouro === '') {
    $erros[] = 'Informe o logradouro.';
}

if ($numero === '') {
    $erros[] = 'Informe o número.';
}

if (!preg_match('/^\d{5}-\d{3}$/', $cep)) {
    $erros[] = 'CEP inválido.';
}

if ($erros) {
    http_response_code(422);
    renderizar('Erro no envio', 'Corrija os itens abaixo e tente novamente:', $erros, false);
    exit;
}

/* ===================== SALVAR NO BANCO ===================== */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Guarda CPF e CEP apenas com números
    $cpfNumeros = preg_replace('/\D/', '', $cpf);
    $cepNumeros = preg_replace('/\D/', '', $cep);

    // Evita CPF duplicado
    $check = $pdo->prepare('SELECT id FROM pessoas WHERE cpf = :cpf');
    $check->execute([':cpf' => $cpfNumeros]);
    if ($check->fetch()) {
        renderizar('CPF já cadastrado', 'Já existe um cadastro com este CPF.', [], false);
        exit;
    }

    $sql = 'INSERT INTO pessoas
                (nome, data_nascimento, cpf, email,
                 tipo_endereco, logradouro, numero, complemento, cep)
            VALUES
                (:nome, :data_nascimento, :cpf, :email,
                 :tipo_endereco, :logradouro, :numero, :complemento, :cep)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nome'            => $nome,
        ':data_nascimento' => $dataNascimento,
        ':cpf'             => $cpfNumeros,
        ':email'           => $email,
        ':tipo_endereco'   => $tipoEndereco,
        ':logradouro'      => $logradouro,
        ':numero'          => $numero,
        ':complemento'     => $complemento !== '' ? $complemento : null,
        ':cep'             => $cepNumeros,
    ]);

    renderizar('Cadastro realizado!', 'Os dados foram salvos com sucesso:', [
        "Nome: $nome",
        'Nascimento: ' . $data->format('d/m/Y'),
        "CPF: $cpf",
        "E-mail: $email",
        'Endereço (' . ucfirst($tipoEndereco) . "): $logradouro, $numero"
            . ($complemento !== '' ? " - $complemento" : ''),
        "CEP: $cep",
    ]);
} catch (PDOException $e) {
    // Em produção, grave $e->getMessage() em um log; nunca mostre ao usuário.
    error_log('Erro ao salvar formulário: ' . $e->getMessage());
    http_response_code(500);
    renderizar('Erro interno', 'Não foi possível salvar os dados. Tente novamente mais tarde.', [], false);
}
