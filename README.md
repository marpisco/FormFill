# FormFill

Plataforma de preenchimento de formulários com geração automática de PDF.

## Funcionalidades

- **Formulários dinâmicos** — construtor visual drag-and-drop para criar formulários personalizados
- **Geração de PDF** — cada submissão gera automaticamente um documento PDF com os dados preenchidos
- **Notificações por email** — confirmação de submissão e resposta do administrador via SMTP
- **Autenticação flexível** — login por email OTP **ou** Microsoft OAuth2, com autenticação de dois fatores (TOTP) para administradores
- **Níveis de privacidade** — formulários públicos, internos (por domínio de email) ou privados (lista de acesso)
- **Painel administrativo** — gestão de formulários, respostas, utilizadores, registos de auditoria e configurações
- **Modo escuro** — interface adaptável às preferências do sistema

## Requisitos

- PHP ≥ 8.2
- MySQL / MariaDB
- Composer
- Servidor web (Apache com mod_rewrite)

## Instalação

```bash
git clone https://github.com/marpisco/FormFill.git
cd FormFill
composer install
cp src/config.sample.php src/config.php
```

Edite `src/config.php` com as credenciais da base de dados, SMTP e OAuth2 (opcional).

Aponte o servidor web para a raiz do projeto. As tabelas da base de dados são criadas automaticamente no primeiro acesso.

### Configuração SMTP

```php
$smtp_config = [
    'enabled'      => true,                  // false = desativar envio de emails
    'host'         => 'smtp.exemplo.com',
    'port'         => 587,
    'auth'         => true,
    'security'     => 'tls',
    'username'     => 'LOGIN_SMTP',
    'password'     => '...',
    'from_address' => 'noreply@exemplo.com',  // endereço no From: (pode diferir do username)
    'from_name'    => 'FormFill',             // nome no From: (sobreponível via DB)
];
```

### Configuração OAuth2 (Microsoft)

```php
$oauth2_config = [
    'enabled'      => true,                   // false = apenas login por email OTP
    'clientId'     => '00000000-0000-0000-0000-000000000000',
    'clientSecret' => '...',
    'tenant'       => 'common',
    'redirectUri'  => 'https://exemplo.com/login/',
];
```

Registe a aplicação em [Azure AD](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps) e configure o `redirectUri` para apontar para `/login/` do seu domínio.

## Estrutura

```
src/lib/       — classes de segurança e autenticação (Session, Csrf, RateLimit, Auth, Mailer, etc.)
login/         — página de autenticação multi-passo
admin/         — painel administrativo (formulários, respostas, utilizadores, registos, configurações)
assets/        — JavaScript (form builder) e imagens
```

## Licença

Este projeto faz parte dos projetos "UNLICENSE". O código é aberto e livre para utilizar, editar e adaptar.

## Contribuições

Problemas e sugestões podem ser reportados no separador "Issues". Pull requests são bem-vindos.
