<?php
/**
 * Autenticacao via Azure Container Apps "Easy Auth" (Entra ID).
 *
 * Quando a autenticacao esta habilitada no Container App, o proxy injeta
 * os cabecalhos abaixo APOS validar o login (e remove quaisquer iguais
 * vindos do cliente), portanto sao confiaveis:
 *   X-MS-CLIENT-PRINCIPAL-NAME  -> UPN/e-mail do usuario
 *   X-MS-CLIENT-PRINCIPAL-ID    -> object id
 *   X-MS-CLIENT-PRINCIPAL       -> base64(JSON) com todos os claims
 *
 * Usado para proteger a aba de Licenciamento (dados pessoais / LGPD).
 * O portal de custos continua anonimo.
 */

declare(strict_types=1);

/** Nome do usuario autenticado, ou '' se anonimo. */
function auth_user(): string
{
    return trim((string)($_SERVER['HTTP_X_MS_CLIENT_PRINCIPAL_NAME'] ?? ''));
}

function auth_is_logged_in(): bool
{
    return auth_user() !== '';
}

/** Exige login para endpoints de API; sem login -> 401 JSON. */
function require_auth_api(): string
{
    $u = auth_user();
    if ($u === '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code(401);
        echo json_encode([
            'error'     => 'auth_required',
            'login_url' => '/.auth/login/aad',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    return $u;
}

/** Exige login para paginas; sem login -> redireciona para o Entra ID. */
function require_auth_page(string $returnPath = '/'): string
{
    $u = auth_user();
    if ($u === '') {
        $target = '/.auth/login/aad?post_login_redirect_uri=' . rawurlencode($returnPath);
        header('Location: ' . $target, true, 302);
        exit;
    }
    return $u;
}
