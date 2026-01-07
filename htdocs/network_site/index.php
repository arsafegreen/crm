<?php
// Network site isolado: usa MySQL dedicado, painel de admin e rotas próprias.
declare(strict_types=1);

require __DIR__ . '/lib/db.php';

$secureCookie = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
ensureSchema();
ensureBaseGroups();


$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---------- ROTEAMENTO CENTRAL ----------
class Router {
    private array $routes = [];
    public function add(string $method, string $pattern, callable $handler, bool $regex = false): void {
        $this->routes[] = [$method, $pattern, $handler, $regex];
    }
    public function dispatch(string $method, string $path): bool {
        foreach ($this->routes as [$m, $p, $h, $regex]) {
            if ($m !== $method) continue;
            if ($regex) {
                if (preg_match($p, $path, $matches)) {
                    $h($matches);
                    return true;
                }
            } else {
                if ($p === $path) {
                    $h([]);
                    return true;
                }
            }
        }
        return false;
    }
}

$router = new Router();

// ---------- ROTAS PÚBLICAS ----------
$router->add('GET', '/', function() {
    $ads = loadActiveAds();
    return render('landing.php', [
        'cta_lead' => '/network',
        'ads' => $ads,
        'banners' => array_map(fn($ad) => $ad['title'], $ads) ?: [
            'Anuncie aqui seu negócio e alcance novos clientes.',
            'Impulsione sua marca com visibilidade imediata.',
            'Segmentação inteligente: fale com quem importa.',
            'Plano futuro pronto para crescer junto com você.',
        ],
        'features' => [
            ['title' => 'Isolado do CRM', 'desc' => 'Ambiente próprio, sem compartilhar banco ou usuários.'],
            ['title' => 'Pronto para anúncios', 'desc' => 'Slots controlados via painel seguro de admin.'],
            ['title' => 'Captura qualificada', 'desc' => 'Formulário completo com validações e rate-limit.'],
            ['title' => 'Dados seguros', 'desc' => 'Persistência em MySQL dedicado + hash Argon2ID para admins.'],
        ],
        'steps' => [
            ['title' => 'Envie sua proposta', 'desc' => 'Conte o que vende, público-alvo e alcance desejado.'],
            ['title' => 'Publique banners', 'desc' => 'Cadastre destaques ativos direto no painel.'],
            ['title' => 'Acompanhe', 'desc' => 'Visualize leads recebidos e tome decisões rápidas.'],
        ],
    ]);
});

$router->add('GET', '/network', function() {
    $feedback = $_SESSION['network_feedback'] ?? null;
    $old = $_SESSION['network_old'] ?? defaultOld();
    unset($_SESSION['network_feedback'], $_SESSION['network_old']);

    return render('network.php', [
        'feedback' => $feedback,
        'old' => $old,
        'csrf' => csrfToken(),
    ]);
});

$router->add('POST', '/network/lead', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return backWith('Solicitação expirada. Atualize a página.', 'error');
    }

    $input = sanitizeLead($_POST);
    $_SESSION['network_old'] = $input;

    $lastTs = $_SESSION['network_last_ts'] ?? 0;
    if (is_numeric($lastTs) && (time() - (int)$lastTs) < 20) {
        return backWith('Aguarde alguns segundos antes de reenviar.', 'error');
    }

    $errors = validateLead($input);
    if (!empty($errors)) {
        return backWith(implode(' ', $errors), 'error');
    }

    $payload = buildLeadPayload($input);
    saveLead($payload);

    $_SESSION['network_last_ts'] = time();
    $_SESSION['network_old'] = defaultOld();
    return backWith('Interesse enviado. Retornaremos em breve.', 'success');
});

// ---------- AUTH USER (PF/PJ) ----------
$router->add('GET', '/network/auth/login', function() {
    $feedback = $_SESSION['auth_feedback_login'] ?? null;
    unset($_SESSION['auth_feedback_login']);
    $captcha = generateCaptcha('user_login');
    return render('auth_login.php', [
        'feedback' => $feedback,
        'csrf' => csrfToken(),
        'captcha' => $captcha,
    ]);
});

$router->add('POST', '/network/auth/login', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return authBack('login', 'error', 'Sessão expirada. Recarregue.');
    }
    if (!validateCaptcha('user_login', (string)($_POST['captcha_choice'] ?? ''))) {
        return authBack('login', 'error', 'Captcha inválido.');
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        return authBack('login', 'error', 'Informe e-mail e senha.');
    }
    $account = findAccountByEmail($email);
    if (!$account) {
        return authBack('login', 'error', 'Credenciais inválidas.');
    }
    if (isAccountLocked($account)) {
        return authBack('login', 'error', 'Conta bloqueada por tentativas. Contate o admin para liberar.');
    }
    if (!password_verify($password, $account['password_hash'])) {
        registerFailedAttempt('user', (int)$account['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), $_SERVER['HTTP_USER_AGENT'] ?? '');
        $attempts = incrementAccountFails((int)$account['id']);
        if ($attempts >= 5) {
            lockAccount((int)$account['id']);
            registerAudit('user', (int)$account['id'], 'account.locked', $email, ['reason' => 'tentativas_excedidas']);
            return authBack('login', 'error', 'Conta bloqueada após 5 tentativas. Contate o admin para liberar.');
        }
        return authBack('login', 'error', 'Credenciais inválidas.');
    }
    resetAccountFails((int)$account['id']);
    session_regenerate_id(true);
    $_SESSION['account_id'] = (int)$account['id'];
    $_SESSION['account_name'] = (string)$account['name'];
    registerAudit('user', (int)$account['id'], 'account.login', $email, []);
    header('Location: /network');
    exit;
});

$router->add('POST', '/network/auth/logout', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF');
    }
    unset($_SESSION['account_id'], $_SESSION['account_name']);
    session_regenerate_id(true);
    header('Location: /network');
    exit;
});

$router->add('GET', '/network/auth/register', function() {
    $feedback = $_SESSION['auth_feedback_register'] ?? null;
    $old = $_SESSION['auth_old'] ?? [];
    unset($_SESSION['auth_feedback_register'], $_SESSION['auth_old']);
    return render('auth_register.php', [
        'feedback' => $feedback,
        'old' => $old,
        'csrf' => csrfToken(),
        'captcha' => generateCaptcha('user_register'),
    ]);
});

$router->add('POST', '/network/auth/register', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return authBack('register', 'error', 'Sessão expirada. Recarregue.', $_POST);
    }
    if (!validateCaptcha('user_register', (string)($_POST['captcha_choice'] ?? ''))) {
        return authBack('register', 'error', 'Captcha inválido.', $_POST);
    }
    $input = sanitizeAccountInput($_POST);
    $errors = validateAccount($input);
    if (!empty($errors)) {
        return authBack('register', 'error', implode(' ', $errors), $_POST);
    }
    $accountId = createAccount($input);
    session_regenerate_id(true);
    $_SESSION['account_id'] = $accountId;
    $_SESSION['account_name'] = $input['name'];
    registerAudit('user', $accountId, 'account.created', $input['email'], []);
    header('Location: /network');
    exit;
});

$router->add('GET', '/network/auth/forgot', function() {
    $feedback = $_SESSION['auth_feedback_forgot'] ?? null;
    unset($_SESSION['auth_feedback_forgot']);
    return render('auth_reset_request.php', [
        'feedback' => $feedback,
        'csrf' => csrfToken(),
        'captcha' => generateCaptcha('user_forgot'),
    ]);
});

$router->add('POST', '/network/auth/forgot', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return authBack('forgot', 'error', 'Sessão expirada.');
    }
    if (!validateCaptcha('user_forgot', (string)($_POST['captcha_choice'] ?? ''))) {
        return authBack('forgot', 'error', 'Captcha inválido.');
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($email === '') return authBack('forgot', 'error', 'Informe o e-mail.');
    $account = findAccountByEmail($email);
    if (!$account) {
        return authBack('forgot', 'success', 'Se o e-mail existir, o link foi gerado.');
    }
    $token = createPasswordReset('user', (int)$account['id'], $_SERVER['REMOTE_ADDR'] ?? '');
    logResetLink($email, $token, 'user');
    registerAudit('user', (int)$account['id'], 'account.reset.request', $email, []);
    return authBack('forgot', 'success', 'Link de redefinição gerado. Verifique o e-mail cadastrado.');
});

$router->add('GET', '/network/auth/reset', function() {
    $token = (string)($_GET['token'] ?? '');
    $feedback = $_SESSION['auth_feedback_reset'] ?? null;
    unset($_SESSION['auth_feedback_reset']);
    if ($token === '') {
        http_response_code(400);
        exit('Token ausente');
    }
    return render('auth_reset.php', [
        'feedback' => $feedback,
        'csrf' => csrfToken(),
        'token' => $token,
        'captcha' => generateCaptcha('user_reset'),
    ]);
});

$router->add('POST', '/network/auth/reset', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return authBack('reset', 'error', 'Sessão expirada.', ['token' => $_POST['token'] ?? '']);
    }
    if (!validateCaptcha('user_reset', (string)($_POST['captcha_choice'] ?? ''))) {
        return authBack('reset', 'error', 'Captcha inválido.', ['token' => $_POST['token'] ?? '']);
    }
    $token = trim((string)($_POST['token'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirmation'] ?? '');
    if ($password !== $confirm) {
        return authBack('reset', 'error', 'Senhas não conferem.', ['token' => $token]);
    }
    if (!isStrongPassword($password)) {
        return authBack('reset', 'error', 'Use letras, números e caractere especial (mín. 8).', ['token' => $token]);
    }
    $reset = findValidResetToken($token, 'user');
    if (!$reset) {
        return authBack('reset', 'error', 'Token inválido ou expirado.', ['token' => $token]);
    }
    updateAccountPassword((int)$reset['account_id'], $password);
    markResetUsed((int)$reset['id']);
    resetAccountFails((int)$reset['account_id']);
    registerAudit('user', (int)$reset['account_id'], 'account.reset.done', null, []);
    return authBack('login', 'success', 'Senha redefinida. Acesse com a nova senha.');
});

// ---------- PERFIL, GRUPOS, MENSAGENS ----------
$router->add('GET', '/network/profile', function() {
    $account = requireAccount();
    $feedback = $_SESSION['profile_feedback'] ?? null;
    unset($_SESSION['profile_feedback']);
    return render('profile.php', [
        'feedback' => $feedback,
        'account' => $account,
        'csrf' => csrfToken(),
    ]);
});

$router->add('POST', '/network/profile', function() {
    $account = requireAccount();
    if (!validateCsrf($_POST['_token'] ?? '')) {
        $_SESSION['profile_feedback'] = ['type' => 'error', 'message' => 'Sessão expirada.'];
        header('Location: /network/profile');
        exit;
    }
    $input = sanitizeProfileInput($_POST, $account);
    $errors = validateProfile($input);
    if (!empty($errors)) {
        $_SESSION['profile_feedback'] = ['type' => 'error', 'message' => implode(' ', $errors)];
        header('Location: /network/profile');
        exit;
    }
    updateAccountProfile($account['id'], $input);
    assignGroupsForAccount($account['id']);
    $_SESSION['profile_feedback'] = ['type' => 'success', 'message' => 'Perfil atualizado.'];
    header('Location: /network/profile');
    exit;
});

$router->add('GET', '/network/messages', function() {
    $account = requireAccount();
    if (!isProfileComplete($account)) {
        header('Location: /network/profile');
        exit;
    }
    return render('messages.php', [
        'csrf' => csrfToken(),
        'account' => $account,
    ]);
});

$router->add('GET', '/network/api/messages', function() {
    $account = requireAccount();
    header('Content-Type: application/json');
    echo json_encode(['data' => listMessages($account)], JSON_UNESCAPED_SLASHES);
    return;
});

$router->add('POST', '/network/api/messages/send', function() {
    $account = requireAccount();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    $targetEmail = strtolower(trim((string)($_POST['to_email'] ?? '')));
    $body = trim((string)($_POST['body'] ?? ''));
    if ($targetEmail === '' || $body === '') {
        return jsonError('Destinatário e mensagem são obrigatórios', 422);
    }
    if (!canSendMessage($account['id'])) {
        return jsonError('Muitas mensagens. Aguarde um pouco.', 429);
    }
    $recipient = findAccountByEmail($targetEmail);
    if (!$recipient) {
        return jsonError('Destinatário não encontrado', 404);
    }
    if (!canInteractByPolitics($account, $recipient)) {
        return jsonError('Interação bloqueada pela preferência política.', 403);
    }
    $msgId = sendMessage($account['id'], (int)$recipient['id'], null, $body);
    rateLimitMessage($account['id']);
    registerAudit('user', (int)$account['id'], 'message.sent', (string)$msgId, ['to' => $targetEmail]);
    return jsonOk();
});

$router->add('GET', '/network/groups', function() {
    $account = requireAccount();
    if (!isProfileComplete($account)) {
        header('Location: /network/profile');
        exit;
    }
    return render('groups.php', [
        'csrf' => csrfToken(),
        'account' => $account,
    ]);
});

$router->add('GET', '/network/api/groups', function() {
    $account = requireAccount();
    header('Content-Type: application/json');
    echo json_encode(['data' => listGroupsForAccount((int)$account['id'])], JSON_UNESCAPED_SLASHES);
    return;
});

// ---------- AUTH ADMIN ----------
$router->add('GET', '/network/admin/login', function() {
    return render('admin_login.php', [
        'feedback' => $_SESSION['admin_feedback'] ?? null,
        'csrf' => csrfToken(),
        'captcha' => generateCaptcha('admin_login'),
    ]);
});

$router->add('POST', '/network/admin/login', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        return adminBack('Solicitação expirada.');
    }
    if (!validateCaptcha('admin_login', (string)($_POST['captcha_choice'] ?? ''))) {
        return adminBack('Captcha inválido.');
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        return adminBack('Informe e-mail e senha.');
    }
    $admin = findAdminByEmail($email);
    if (!$admin) {
        return adminBack('Credenciais inválidas.');
    }
    if (isAdminLocked($admin)) {
        return adminBack('Conta bloqueada por tentativas. Contate o admin principal.');
    }
    if (!password_verify($password, $admin['password_hash'])) {
        registerFailedAttempt('admin', (int)$admin['id'], (string)($_SERVER['REMOTE_ADDR'] ?? ''), $_SERVER['HTTP_USER_AGENT'] ?? '');
        $attempts = incrementAdminFails((int)$admin['id']);
        if ($attempts >= 5) {
            lockAdmin((int)$admin['id']);
            registerAudit('admin', (int)$admin['id'], 'admin.locked', $email, ['reason' => 'tentativas_excedidas']);
            return adminBack('Conta bloqueada após 5 tentativas. Contate o admin principal.');
        }
        return adminBack('Credenciais inválidas.');
    }
    resetAdminFails((int)$admin['id']);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_name'] = (string)$admin['name'];
    header('Location: /network/admin');
    exit;
});

$router->add('POST', '/network/admin/logout', function() {
    if (!validateCsrf($_POST['_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF');
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_name']);
    session_regenerate_id(true);
    header('Location: /network/admin/login');
    exit;
});

$router->add('GET', '/network/admin', function() {
    $admin = requireAdmin();
    return render('admin.php', [
        'admin' => $admin,
        'csrf' => csrfToken(),
    ]);
});

$router->add('GET', '/network/api/admin/accounts', function() {
    requireAdmin();
    header('Content-Type: application/json');
    echo json_encode(['data' => listAccounts()], JSON_UNESCAPED_SLASHES);
    return;
});

$router->add('POST', '#^/network/api/admin/accounts/(\d+)/politics$#', function(array $m) {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    $pref = (string)($_POST['political_pref'] ?? 'neutral');
    if (!in_array($pref, ['left','right','neutral'], true)) {
        return jsonError('Pref inválida', 422);
    }
    updateAccountPolitics((int)$m[1], $pref);
    registerAudit('admin', (int)($_SESSION['admin_id'] ?? 0), 'account.politics.update', (string)$m[1], ['pref' => $pref]);
    return jsonOk();
}, true);

$router->add('POST', '#^/network/api/admin/accounts/(\d+)/unlock$#', function(array $m) {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    unlockAccount((int)$m[1]);
    registerAudit('admin', (int)($_SESSION['admin_id'] ?? 0), 'account.unlocked', (string)$m[1], []);
    return jsonOk();
}, true);

// ---------- API ADMIN (JSON) ----------
$router->add('GET', '/network/api/leads', function() {
    $admin = requireAdmin();
    header('Content-Type: application/json');
    echo json_encode(['data' => listLeads()], JSON_UNESCAPED_SLASHES);
    return;
});

$router->add('POST', '#^/network/api/leads/([^/]+)/(approve|deny)$#', function(array $m) {
    $admin = requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    $id = (string)$m[1];
    $status = $m[2] === 'approve' ? 'approved' : 'denied';
    $note = trim((string)($_POST['note'] ?? ''));
    updateLeadStatus($id, $status, $note, $admin['name'] ?? 'admin');
    return jsonOk();
}, true);

$router->add('POST', '#^/network/api/leads/([^/]+)/groups$#', function(array $m) {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    $groupsRaw = (string)($_POST['groups'] ?? '');
    $groups = array_values(array_filter(array_unique(preg_split('/[\s,]+/', trim($groupsRaw)) ?: [])));
    setLeadGroups((string)$m[1], $groups);
    return jsonOk();
}, true);

$router->add('GET', '/network/api/ads', function() {
    requireAdmin();
    header('Content-Type: application/json');
    echo json_encode(['data' => listAds()], JSON_UNESCAPED_SLASHES);
    return;
});

$router->add('POST', '/network/api/ads', function() {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    createAd($_POST);
    return jsonOk();
});

$router->add('POST', '#^/network/api/ads/(\d+)$#', function(array $m) {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    updateAd((int)$m[1], $_POST);
    return jsonOk();
}, true);

$router->add('POST', '#^/network/api/ads/(\d+)/toggle$#', function(array $m) {
    requireAdmin();
    if (!validateCsrf($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        return jsonError('CSRF', 419);
    }
    toggleAd((int)$m[1]);
    return jsonOk();
}, true);

if ($router->dispatch($method, $path)) {
    return;
}
http_response_code(404);
echo 'Not found';
return;

// ---------- Funções ----------
function render(string $view, array $data = []): void
{
    extract($data, EXTR_OVERWRITE);
    $content = include __DIR__ . '/views/' . $view;
    if ($content === 1) {
        return;
    }
}

function backWith(string $message, string $type, array $old = []): void
{
    $_SESSION['network_feedback'] = ['type' => $type, 'message' => $message];
    $_SESSION['network_old'] = $old ?: defaultOld();
    header('Location: /network');
    exit;
}

function adminBack(string $message): void
{
    $_SESSION['admin_feedback'] = ['type' => 'error', 'message' => $message];
    header('Location: /network/admin/login');
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function validateCsrf(string $token): bool
{
    return !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

function defaultOld(): array
{
    return [
        'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'cnpj' => '', 'cpf' => '',
        'birthdate' => '', 'address' => '', 'region' => '', 'area' => '', 'objective' => '',
        'interest' => '', 'message' => '', 'consumer_mode' => '', 'cv_link' => '', 'skills' => '',
        'ecommerce_interest' => '', 'political_pref' => 'neutral', 'consent' => false,
    ];
}

function sanitizeLead(array $input): array
{
    $out = [];
    $out['name'] = trim((string)($input['name'] ?? ''));
    $out['email'] = strtolower(trim((string)($input['email'] ?? '')));
    $out['phone'] = preg_replace('/[^\d+]/', '', (string)($input['phone'] ?? '')) ?? '';
    $out['company'] = trim((string)($input['company'] ?? ''));
    $out['cnpj'] = preg_replace('/\D+/', '', (string)($input['cnpj'] ?? '')) ?? '';
    $out['cpf'] = preg_replace('/\D+/', '', (string)($input['cpf'] ?? '')) ?? '';
    $out['birthdate'] = trim((string)($input['birthdate'] ?? ''));
    $out['address'] = trim((string)($input['address'] ?? ''));
    $out['region'] = trim((string)($input['region'] ?? ''));
    $out['area'] = trim((string)($input['area'] ?? ''));
    $out['objective'] = trim((string)($input['objective'] ?? ''));
    $out['interest'] = trim((string)($input['interest'] ?? ''));
    $out['message'] = mb_substr(trim((string)($input['message'] ?? '')), 0, 800);
    $out['consumer_mode'] = (string)($input['consumer_mode'] ?? '') === '1';
    $out['cv_link'] = trim((string)($input['cv_link'] ?? ''));
    $out['skills'] = mb_substr(trim((string)($input['skills'] ?? '')), 0, 400);
    $out['ecommerce_interest'] = (string)($input['ecommerce_interest'] ?? '') === '1';
    $out['political_pref'] = trim((string)($input['political_pref'] ?? 'neutral'));
    $out['consent'] = (string)($input['consent'] ?? '') === '1';
    return $out;
}

function validateLead(array $lead): array
{
    $errors = [];
    if ($lead['name'] === '' || $lead['email'] === '' || $lead['phone'] === '' || $lead['area'] === '' || $lead['objective'] === '' || $lead['region'] === '') {
        $errors[] = 'Informe nome, e-mail, telefone, área, objetivo e região.';
    }
    if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail inválido.';
    }
    if ($lead['cv_link'] !== '') {
        $ok = filter_var($lead['cv_link'], FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $lead['cv_link']);
        if (!$ok) {
            $errors[] = 'Link de currículo inválido.';
        }
    }
    if ($lead['cnpj'] !== '' && strlen($lead['cnpj']) !== 14) {
        $errors[] = 'CNPJ deve ter 14 dígitos.';
    }
    if ($lead['cpf'] !== '' && strlen($lead['cpf']) !== 11) {
        $errors[] = 'CPF deve ter 11 dígitos.';
    }
    if (!$lead['consent']) {
        $errors[] = 'É preciso concordar com o uso dos dados para prosseguir.';
    }
    return $errors;
}

function buildLeadPayload(array $input): array
{
    $cnpjs = $input['cnpj'] !== '' ? [$input['cnpj']] : [];
    $areas = $input['area'] !== '' ? [$input['area']] : [];
    $politics = in_array($input['political_pref'], ['neutral', 'progressive', 'conservative', 'no_info'], true)
        ? $input['political_pref']
        : 'neutral';
    $entityType = !empty($cnpjs) ? 'pj' : 'pf';

    return [
        'id' => bin2hex(random_bytes(8)),
        'request_id' => bin2hex(random_bytes(8)),
        'name' => $input['name'],
        'email' => $input['email'],
        'phone' => $input['phone'],
        'company' => $input['company'],
        'cnpj' => $input['cnpj'],
        'cnpjs' => $cnpjs,
        'cpf' => $input['cpf'],
        'birthdate' => $input['birthdate'] ?: null,
        'address' => $input['address'],
        'region' => $input['region'],
        'area' => $input['area'],
        'areas' => $areas,
        'objective' => $input['objective'],
        'interest' => $input['interest'],
        'message' => $input['message'],
        'consumer_mode' => $input['consumer_mode'],
        'cv_link' => $input['cv_link'],
        'skills' => $input['skills'],
        'ecommerce_interest' => $input['ecommerce_interest'],
        'political_pref' => $politics,
        'political_access' => politicalAccess($politics),
        'entity_type' => $entityType,
        'consent' => true,
        'status' => 'pending',
        'suggested_groups' => suggestGroups($input['area'], $input['region'], $politics, $cnpjs, $entityType, $input['consumer_mode']),
        'assigned_groups' => [],
        'pending_cnpjs' => $cnpjs,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ts' => date(DATE_ATOM),
    ];
}

function politicalAccess(string $politics): array
{
    return match ($politics) {
        'progressive' => ['politics:progressive'],
        'conservative' => ['politics:conservative'],
        'no_info' => ['politics:neutral'],
        default => ['politics:neutral', 'politics:progressive', 'politics:conservative'],
    };
}

function suggestGroups(string $area, string $region, string $politics, array $cnpjs, string $entityType, bool $isConsumer): array
{
    $groups = ['general'];
    if ($area !== '') {
        $groups[] = 'area:' . slug($area);
    }
    if ($region !== '') {
        $groups[] = 'region:' . slug($region);
    }
    $groups[] = 'politics:' . ($politics ?: 'neutral');
    if ($entityType === 'pj') {
        foreach ($cnpjs as $c) {
            if ($c !== '') {
                $groups[] = 'cnpj:' . $c;
            }
        }
    } else {
        $groups[] = 'pf:geral';
        if ($isConsumer) {
            $groups[] = 'pf:consumidor';
        }
    }
    return array_values(array_unique($groups));
}

function slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    return trim($value, '-');
}

function saveLead(array $lead): void
{
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO network_leads (
        id, request_id, name, email, phone, company, primary_cnpj, cpf, birthdate, address,
        region, area, objective, interest, message, political_pref, political_access,
        entity_type, consumer_mode, cv_link, skills, ecommerce_interest, consent, status,
        suggested_groups, assigned_groups, cnpjs, areas, pending_cnpjs, user_agent, ip,
        created_at, updated_at
    ) VALUES (
        :id, :request_id, :name, :email, :phone, :company, :primary_cnpj, :cpf, :birthdate, :address,
        :region, :area, :objective, :interest, :message, :political_pref, :political_access,
        :entity_type, :consumer_mode, :cv_link, :skills, :ecommerce_interest, :consent, :status,
        :suggested_groups, :assigned_groups, :cnpjs, :areas, :pending_cnpjs, :user_agent, :ip,
        :created_at, :updated_at
    )');

    $stmt->execute([
        'id' => $lead['id'],
        'request_id' => $lead['request_id'],
        'name' => $lead['name'],
        'email' => $lead['email'],
        'phone' => $lead['phone'],
        'company' => $lead['company'],
        'primary_cnpj' => $lead['cnpj'],
        'cpf' => $lead['cpf'],
        'birthdate' => $lead['birthdate'] ?: null,
        'address' => $lead['address'],
        'region' => $lead['region'],
        'area' => $lead['area'],
        'objective' => $lead['objective'],
        'interest' => $lead['interest'],
        'message' => $lead['message'],
        'political_pref' => $lead['political_pref'],
        'political_access' => json_encode($lead['political_access'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'entity_type' => $lead['entity_type'],
        'consumer_mode' => $lead['consumer_mode'] ? 1 : 0,
        'cv_link' => $lead['cv_link'],
        'skills' => $lead['skills'],
        'ecommerce_interest' => $lead['ecommerce_interest'] ? 1 : 0,
        'consent' => 1,
        'status' => $lead['status'],
        'suggested_groups' => json_encode($lead['suggested_groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'assigned_groups' => json_encode($lead['assigned_groups'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'cnpjs' => json_encode($lead['cnpjs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'areas' => json_encode($lead['areas'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'pending_cnpjs' => json_encode($lead['pending_cnpjs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'user_agent' => $lead['user_agent'],
        'ip' => $lead['ip'],
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function findAdminByEmail(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM network_admins WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requireAdmin(): array
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: /network/admin/login');
        exit;
    }
    $stmt = db()->prepare('SELECT id, name, email FROM network_admins WHERE id = :id');
    $stmt->execute(['id' => (int)$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        unset($_SESSION['admin_id'], $_SESSION['admin_name']);
        header('Location: /network/admin/login');
        exit;
    }
    return $admin;
}

function listLeads(): array
{
    $stmt = db()->query('SELECT * FROM network_leads ORDER BY created_at DESC LIMIT 500');
    $rows = $stmt->fetchAll();
    return array_map(fn($r) => normalizeJsonFields($r, ['suggested_groups', 'assigned_groups', 'cnpjs', 'areas', 'pending_cnpjs', 'political_access']), $rows);
}

function updateLeadStatus(string $id, string $status, string $note, string $by): void
{
    $stmt = db()->prepare('UPDATE network_leads SET status = :status, decision_note = :note, decision_by = :by, decision_at = :at, updated_at = :at WHERE id = :id');
    $stmt->execute([
        'status' => $status,
        'note' => $note,
        'by' => $by,
        'at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function setLeadGroups(string $id, array $groups): void
{
    $stmt = db()->prepare('UPDATE network_leads SET assigned_groups = :groups, updated_at = :at WHERE id = :id');
    $stmt->execute([
        'groups' => json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function loadActiveAds(): array
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare('SELECT * FROM network_ads WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now) ORDER BY created_at DESC LIMIT 50');
    $stmt->execute(['now' => $now]);
    return $stmt->fetchAll() ?: [];
}

function listAds(): array
{
    $stmt = db()->query('SELECT * FROM network_ads ORDER BY created_at DESC');
    return $stmt->fetchAll() ?: [];
}

function createAd(array $input): void
{
    $stmt = db()->prepare('INSERT INTO network_ads (title, image_url, target_url, starts_at, ends_at, is_active, created_at, updated_at) VALUES (:title, :image_url, :target_url, :starts_at, :ends_at, :is_active, :created_at, :updated_at)');
    $stmt->execute([
        'title' => trim((string)($input['title'] ?? '')), 
        'image_url' => trim((string)($input['image_url'] ?? '')) ?: null,
        'target_url' => trim((string)($input['target_url'] ?? '')),
        'starts_at' => normalizeDateTime($input['starts_at'] ?? null),
        'ends_at' => normalizeDateTime($input['ends_at'] ?? null),
        'is_active' => (string)($input['is_active'] ?? '1') === '1' ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

function updateAd(int $id, array $input): void
{
    $stmt = db()->prepare('UPDATE network_ads SET title = :title, image_url = :image_url, target_url = :target_url, starts_at = :starts_at, ends_at = :ends_at, is_active = :is_active, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        'title' => trim((string)($input['title'] ?? '')),
        'image_url' => trim((string)($input['image_url'] ?? '')) ?: null,
        'target_url' => trim((string)($input['target_url'] ?? '')),
        'starts_at' => normalizeDateTime($input['starts_at'] ?? null),
        'ends_at' => normalizeDateTime($input['ends_at'] ?? null),
        'is_active' => (string)($input['is_active'] ?? '1') === '1' ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function toggleAd(int $id): void
{
    $stmt = db()->prepare('UPDATE network_ads SET is_active = 1 - is_active, updated_at = :at WHERE id = :id');
    $stmt->execute(['at' => date('Y-m-d H:i:s'), 'id' => $id]);
}

function normalizeDateTime($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function normalizeJsonFields(array $row, array $fields): array
{
    foreach ($fields as $f) {
        if (isset($row[$f]) && is_string($row[$f])) {
            $dec = json_decode($row[$f], true);
            if (is_array($dec)) {
                $row[$f] = $dec;
            }
        }
    }
    return $row;
}

function jsonOk(): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Fun‡äes de autentica‡Æo e valida‡Æo ----------
function authBack(string $context, string $type, string $message, array $old = []): void
{
    $_SESSION['auth_feedback_' . $context] = ['type' => $type, 'message' => $message];
    $_SESSION['auth_old'] = $old;
    $targets = [
        'login' => '/network/auth/login',
        'register' => '/network/auth/register',
        'forgot' => '/network/auth/forgot',
        'reset' => '/network/auth/reset',
    ];
    $loc = $targets[$context] ?? '/network/auth/login';
    if ($context === 'reset' && !empty($old['token'])) {
        $loc .= '?token=' . urlencode((string)$old['token']);
    }
    header('Location: ' . $loc);
    exit;
}

function generateCaptcha(string $key): array
{
    $options = [
        ['id' => 'circle', 'label' => 'círculo azul', 'src' => svgData('circle'), 'alt' => 'círculo azul'],
        ['id' => 'square', 'label' => 'quadrado roxo', 'src' => svgData('square'), 'alt' => 'quadrado roxo'],
        ['id' => 'triangle', 'label' => 'triângulo verde', 'src' => svgData('triangle'), 'alt' => 'triângulo verde'],
    ];
    shuffle($options);
    $target = $options[array_rand($options)];
    $_SESSION['_captcha_' . $key] = ['target' => $target['id'], 'ts' => time()];
    return ['label' => $target['label'], 'options' => $options];
}

function validateCaptcha(string $key, string $choice): bool
{
    $data = $_SESSION['_captcha_' . $key] ?? null;
    unset($_SESSION['_captcha_' . $key]);
    if (!$data || !isset($data['target'])) return false;
    if (!is_string($choice) || $choice === '') return false;
    if (!isset($data['ts']) || (time() - (int)$data['ts']) > 300) return false;
    return hash_equals((string)$data['target'], $choice);
}

function svgData(string $shape): string
{
    $svg = match ($shape) {
        'square' => '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect x="20" y="20" width="160" height="160" rx="18" fill="#a855f7"/></svg>',
        'triangle' => '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><polygon points="100,20 180,180 20,180" fill="#22c55e"/></svg>',
        default => '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><circle cx="100" cy="100" r="80" fill="#22d3ee"/></svg>',
    };
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function sanitizeAccountInput(array $input): array
{
    $cnpjsExtra = array_filter(array_map(fn($c) => preg_replace('/\D+/', '', $c) ?? '', preg_split('/[,\\s]+/', (string)($input['cnpjs_extra'] ?? '')) ?: []));
    return [
        'type' => (string)($input['type'] ?? 'pf') === 'pj' ? 'pj' : 'pf',
        'name' => trim((string)($input['name'] ?? '')),
        'cpf' => preg_replace('/\D+/', '', (string)($input['cpf'] ?? '')) ?? '',
        'birthdate' => trim((string)($input['birthdate'] ?? '')),
        'email' => strtolower(trim((string)($input['email'] ?? ''))),
        'phone' => preg_replace('/[^\d+]/', '', (string)($input['phone'] ?? '')) ?? '',
        'address' => trim((string)($input['address'] ?? '')),
        'cnpj_primary' => preg_replace('/\D+/', '', (string)($input['cnpj_primary'] ?? '')) ?? '',
        'cnpjs_extra' => $cnpjsExtra,
        'password' => (string)($input['password'] ?? ''),
        'password_confirmation' => (string)($input['password_confirmation'] ?? ''),
    ];
}

function validateAccount(array $input): array
{
    $errors = [];
    if ($input['name'] === '' || $input['cpf'] === '' || $input['birthdate'] === '' || $input['email'] === '' || $input['phone'] === '' || $input['address'] === '') {
        $errors[] = 'Preencha todos os campos obrigat¢rios.';
    }
    if (!validateCpf($input['cpf'])) {
        $errors[] = 'CPF inv lido.';
    }
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail inv lido.';
    }
    if (strlen($input['phone']) < 10) {
        $errors[] = 'Telefone inv lido.';
    }
    if (!isValidDate($input['birthdate'])) {
        $errors[] = 'Data de nascimento inv lida.';
    }
    if ($input['type'] === 'pj') {
        if ($input['cnpj_primary'] === '' || !validateCnpj($input['cnpj_primary'])) {
            $errors[] = 'CNPJ principal inv lido.';
        }
        foreach ($input['cnpjs_extra'] as $c) {
            if (!validateCnpj($c)) {
                $errors[] = 'CNPJ adicional inv lido.';
                break;
            }
        }
    }
    if ($input['password'] === '' || $input['password_confirmation'] === '') {
        $errors[] = 'Informe a senha.';
    }
    if ($input['password'] !== $input['password_confirmation']) {
        $errors[] = 'Senhas nÆo conferem.';
    }
    if (!isStrongPassword($input['password'])) {
        $errors[] = 'Senha fraca. Use letras, n£meros e caractere especial (mín. 8).';
    }

    if (emailExists($input['email'])) $errors[] = 'E-mail j  cadastrado.';
    if (phoneExists($input['phone'])) $errors[] = 'Telefone j  cadastrado.';
    if (cpfExists($input['cpf'])) $errors[] = 'CPF j  cadastrado.';
    if ($input['type'] === 'pj') {
        foreach (array_merge([$input['cnpj_primary']], $input['cnpjs_extra']) as $c) {
            if ($c !== '' && cnpjExists($c)) {
                $errors[] = 'CNPJ j  cadastrado.';
                break;
            }
        }
    }

    return $errors;
}

function isValidDate(string $value): bool
{
    try { $dt = new DateTimeImmutable($value); return $dt !== false; } catch (Throwable) { return false; }
}

function isStrongPassword(string $password): bool
{
    if (strlen($password) < 8) return false;
    $hasLetter = (bool)preg_match('/[A-Za-z]/', $password);
    $hasNumber = (bool)preg_match('/\\d/', $password);
    $hasSpecial = (bool)preg_match('/[^A-Za-z0-9]/', $password);
    return $hasLetter && $hasNumber && $hasSpecial;
}

function validateCpf(string $cpf): bool
{
    $cpf = preg_replace('/\D+/', '', $cpf) ?? '';
    if (strlen($cpf) !== 11 || preg_match('/^(\\d)\\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += (int)$cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$t] !== $d) return false;
    }
    return true;
}

function validateCnpj(string $cnpj): bool
{
    $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
    if (strlen($cnpj) !== 14 || preg_match('/^(\\d)\\1{13}$/', $cnpj)) return false;
    $calc = function(array $n, array $p) {
        $s = 0;
        foreach ($p as $i => $v) $s += $n[$i] * $v;
        $r = $s % 11;
        return $r < 2 ? 0 : 11 - $r;
    };
    $nums = array_map('intval', str_split($cnpj));
    $dv1 = $calc($nums, [5,4,3,2,9,8,7,6,5,4,3,2]);
    $dv2 = $calc($nums, [6,5,4,3,2,9,8,7,6,5,4,3,2]);
    return $dv1 === $nums[12] && $dv2 === $nums[13];
}

function emailExists(string $email): bool
{
    $stmt = db()->prepare('SELECT 1 FROM network_accounts WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    return (bool)$stmt->fetchColumn();
}

function phoneExists(string $phone): bool
{
    $stmt = db()->prepare('SELECT 1 FROM network_accounts WHERE phone = :phone LIMIT 1');
    $stmt->execute(['phone' => $phone]);
    return (bool)$stmt->fetchColumn();
}

function cpfExists(string $cpf): bool
{
    $stmt = db()->prepare('SELECT 1 FROM network_accounts WHERE cpf = :cpf LIMIT 1');
    $stmt->execute(['cpf' => $cpf]);
    return (bool)$stmt->fetchColumn();
}

function cnpjExists(string $cnpj): bool
{
    $stmt = db()->prepare('SELECT 1 FROM network_account_cnpjs WHERE cnpj = :cnpj LIMIT 1');
    $stmt->execute(['cnpj' => $cnpj]);
    return (bool)$stmt->fetchColumn();
}

function findAccountByEmail(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM network_accounts WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    if ($row && isset($row['cnpjs']) && is_string($row['cnpjs'])) {
        $dec = json_decode($row['cnpjs'], true);
        if (is_array($dec)) $row['cnpjs'] = $dec;
    }
    return $row ?: null;
}

function createAccount(array $input): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $cnpjs = $input['type'] === 'pj' ? array_values(array_filter(array_unique(array_merge([$input['cnpj_primary']], $input['cnpjs_extra'] ?? [])))) : [];
    $stmt = $pdo->prepare('INSERT INTO network_accounts (type, name, cpf, birthdate, email, phone, address, city, state, region, segment, cnae, company_size, revenue_range, employees, sales_channels, objectives, political_pref, political_access, primary_cnpj, cnpjs, password_hash, created_at, updated_at) VALUES (:type, :name, :cpf, :birthdate, :email, :phone, :address, :city, :state, :region, :segment, :cnae, :company_size, :revenue_range, :employees, :sales_channels, :objectives, :political_pref, :political_access, :primary_cnpj, :cnpjs, :password_hash, :created_at, :updated_at)');
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'type' => $input['type'],
        'name' => $input['name'],
        'cpf' => $input['cpf'],
        'birthdate' => $input['birthdate'] ?: null,
        'email' => $input['email'],
        'phone' => $input['phone'],
        'address' => $input['address'],
        'city' => null,
        'state' => null,
        'region' => null,
        'segment' => '',
        'cnae' => null,
        'company_size' => null,
        'revenue_range' => null,
        'employees' => null,
        'sales_channels' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'objectives' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'political_pref' => 'neutral',
        'political_access' => json_encode(politicalAccessUser('neutral'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'primary_cnpj' => $input['type'] === 'pj' ? ($input['cnpj_primary'] ?: null) : null,
        'cnpjs' => json_encode($cnpjs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'password_hash' => password_hash($input['password'], PASSWORD_ARGON2ID),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $accountId = (int)$pdo->lastInsertId();
    if ($input['type'] === 'pj') {
        $stmtC = $pdo->prepare('INSERT INTO network_account_cnpjs (account_id, cnpj, created_at) VALUES (:account_id, :cnpj, :created_at)');
        foreach ($cnpjs as $cnpj) {
            $stmtC->execute(['account_id' => $accountId, 'cnpj' => $cnpj, 'created_at' => $now]);
        }
    }
    $pdo->commit();
    return $accountId;
}

function isAccountLocked(array $account): bool
{
    if (empty($account['locked_at'])) return false;
    return true;
}

function incrementAccountFails(int $id): int
{
    $pdo = db();
    $pdo->prepare('UPDATE network_accounts SET failed_attempts = failed_attempts + 1 WHERE id = :id')->execute(['id' => $id]);
    $stmt = $pdo->prepare('SELECT failed_attempts FROM network_accounts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function resetAccountFails(int $id): void
{
    db()->prepare('UPDATE network_accounts SET failed_attempts = 0, locked_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

function lockAccount(int $id): void
{
    db()->prepare('UPDATE network_accounts SET locked_at = :locked, updated_at = :locked WHERE id = :id')->execute([
        'locked' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function unlockAccount(int $id): void
{
    db()->prepare('UPDATE network_accounts SET locked_at = NULL, failed_attempts = 0, updated_at = :u WHERE id = :id')->execute([
        'u' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function createPasswordReset(string $type, int $accountId, string $ip = ''): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('INSERT INTO network_password_resets (account_type, account_id, token_hash, expires_at, created_at, ip) VALUES (:account_type, :account_id, :token_hash, :expires_at, :created_at, :ip)');
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'account_type' => $type,
        'account_id' => $accountId,
        'token_hash' => $hash,
        'expires_at' => $expires,
        'created_at' => $now,
        'ip' => $ip,
    ]);
    return $token;
}

function findValidResetToken(string $token, string $type): ?array
{
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM network_password_resets WHERE token_hash = :token_hash AND account_type = :type AND used_at IS NULL LIMIT 1');
    $stmt->execute(['token_hash' => $hash, 'type' => $type]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (strtotime((string)$row['expires_at']) < time()) return null;
    return $row;
}

function markResetUsed(int $id): void
{
    db()->prepare('UPDATE network_password_resets SET used_at = :used WHERE id = :id')->execute(['used' => date('Y-m-d H:i:s'), 'id' => $id]);
}

function updateAccountPassword(int $id, string $password): void
{
    db()->prepare('UPDATE network_accounts SET password_hash = :hash, updated_at = :updated WHERE id = :id')->execute([
        'hash' => password_hash($password, PASSWORD_ARGON2ID),
        'updated' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function logResetLink(string $email, string $token, string $type): void
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/reset-links.log';
    $line = sprintf("[%s] %s reset (%s): %s\n", date('Y-m-d H:i:s'), $email, $type, $token);
    file_put_contents($file, $line, FILE_APPEND);
}

function registerAudit(string $actorType, ?int $actorId, string $action, ?string $target, array $meta): void
{
    db()->prepare('INSERT INTO network_audit_logs (actor_type, actor_id, action, target, meta, ip, user_agent, created_at) VALUES (:actor_type, :actor_id, :action, :target, :meta, :ip, :user_agent, :created_at)')->execute([
        'actor_type' => $actorType,
        'actor_id' => $actorId,
        'action' => $action,
        'target' => $target,
        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function registerFailedAttempt(string $type, int $accountId, string $ip, string $ua): void
{
    registerAudit($type, $accountId, 'login.failed', null, ['ip' => $ip, 'ua' => $ua]);
}

function listAccounts(): array
{
    $stmt = db()->query('SELECT id, name, email, phone, type, segment, region, state, political_pref, locked_at, created_at FROM network_accounts ORDER BY created_at DESC LIMIT 200');
    return $stmt->fetchAll() ?: [];
}

function updateAccountPolitics(int $id, string $pref): void
{
    db()->prepare('UPDATE network_accounts SET political_pref = :pref, political_access = :access, updated_at = :u WHERE id = :id')->execute([
        'pref' => $pref,
        'access' => json_encode(politicalAccessUser($pref), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'u' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
    assignGroupsForAccount($id);
}

function isAdminLocked(array $admin): bool
{
    return !empty($admin['locked_at']);
}

function incrementAdminFails(int $id): int
{
    $pdo = db();
    $pdo->prepare('UPDATE network_admins SET failed_attempts = failed_attempts + 1 WHERE id = :id')->execute(['id' => $id]);
    $stmt = $pdo->prepare('SELECT failed_attempts FROM network_admins WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function resetAdminFails(int $id): void
{
    db()->prepare('UPDATE network_admins SET failed_attempts = 0, locked_at = NULL WHERE id = :id')->execute(['id' => $id]);
}

function lockAdmin(int $id): void
{
    db()->prepare('UPDATE network_admins SET locked_at = :locked WHERE id = :id')->execute(['locked' => date('Y-m-d H:i:s'), 'id' => $id]);
}

// ---------- Contas, perfil e grupos ----------
function requireAccount(): array
{
    if (empty($_SESSION['account_id'])) {
        header('Location: /network/auth/login');
        exit;
    }
    $stmt = db()->prepare('SELECT * FROM network_accounts WHERE id = :id');
    $stmt->execute(['id' => (int)$_SESSION['account_id']]);
    $acc = $stmt->fetch();
    if (!$acc) {
        unset($_SESSION['account_id'], $_SESSION['account_name']);
        header('Location: /network/auth/login');
        exit;
    }
    if (isset($acc['cnpjs']) && is_string($acc['cnpjs'])) {
        $dec = json_decode($acc['cnpjs'], true);
        if (is_array($dec)) $acc['cnpjs'] = $dec;
    }
    if (isset($acc['political_access']) && is_string($acc['political_access'])) {
        $dec = json_decode($acc['political_access'], true);
        if (is_array($dec)) $acc['political_access'] = $dec;
    }
    foreach (['sales_channels', 'objectives'] as $jsonField) {
        if (isset($acc[$jsonField]) && is_string($acc[$jsonField])) {
            $dec = json_decode($acc[$jsonField], true);
            if (is_array($dec)) $acc[$jsonField] = $dec;
        }
    }
    return $acc;
}

function sanitizeProfileInput(array $input, array $account): array
{
    $channels = array_values(array_filter(array_map('trim', explode(',', (string)($input['sales_channels'] ?? '')))));
    $objectives = array_values(array_filter(array_map('trim', explode(',', (string)($input['objectives'] ?? '')))));
    return [
        'segment' => trim((string)($input['segment'] ?? '')),
        'cnae' => preg_replace('/[^0-9\\.\\-]/', '', (string)($input['cnae'] ?? '')) ?? '',
        'company_size' => trim((string)($input['company_size'] ?? '')),
        'revenue_range' => trim((string)($input['revenue_range'] ?? '')),
        'employees' => (int)($input['employees'] ?? 0),
        'sales_channels' => $channels,
        'objectives' => $objectives,
        'political_pref' => in_array($input['political_pref'] ?? 'neutral', ['left','right','neutral'], true) ? $input['political_pref'] : 'neutral',
        'state' => strtoupper(trim((string)($input['state'] ?? ''))),
        'city' => trim((string)($input['city'] ?? '')),
        'region' => trim((string)($input['region'] ?? '')),
        'address' => trim((string)($input['address'] ?? ($account['address'] ?? ''))),
    ];
}

function validateProfile(array $input): array
{
    $errors = [];
    if ($input['segment'] === '') $errors[] = 'Informe o segmento.';
    if ($input['political_pref'] === '') $errors[] = 'Informe a posição política.';
    if ($input['state'] === '' || strlen($input['state']) > 3) $errors[] = 'UF inválida.';
    if ($input['city'] === '') $errors[] = 'Cidade obrigatória.';
    if ($input['region'] === '') $errors[] = 'Região obrigatória.';
    return $errors;
}

function updateAccountProfile(int $id, array $input): void
{
    $pdo = db();
    $pdo->prepare('UPDATE network_accounts SET segment = :segment, cnae = :cnae, company_size = :company_size, revenue_range = :revenue_range, employees = :employees, sales_channels = :sales_channels, objectives = :objectives, political_pref = :political_pref, political_access = :political_access, state = :state, city = :city, region = :region, address = :address, updated_at = :updated WHERE id = :id')->execute([
        'segment' => $input['segment'],
        'cnae' => $input['cnae'],
        'company_size' => $input['company_size'],
        'revenue_range' => $input['revenue_range'],
        'employees' => $input['employees'] > 0 ? $input['employees'] : null,
        'sales_channels' => json_encode($input['sales_channels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'objectives' => json_encode($input['objectives'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'political_pref' => $input['political_pref'],
        'political_access' => json_encode(politicalAccessUser($input['political_pref']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'state' => $input['state'],
        'city' => $input['city'],
        'region' => $input['region'],
        'address' => $input['address'],
        'updated' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function politicalAccessUser(string $pref): array
{
    return match ($pref) {
        'left' => ['left'],
        'right' => ['right'],
        default => ['left', 'right', 'neutral'],
    };
}

function isProfileComplete(array $account): bool
{
    $required = ['segment', 'political_pref', 'state', 'city', 'region'];
    foreach ($required as $r) {
        if (empty($account[$r])) return false;
    }
    return true;
}

function ensureBaseGroups(): void
{
    $base = [
        ['slug' => 'general', 'name' => 'Geral', 'type' => 'general'],
        ['slug' => 'politics-left', 'name' => 'Política - Esquerda', 'type' => 'politics'],
        ['slug' => 'politics-right', 'name' => 'Política - Direita', 'type' => 'politics'],
        ['slug' => 'politics-neutral', 'name' => 'Política - Neutro', 'type' => 'politics'],
    ];
    $stmt = db()->prepare('INSERT IGNORE INTO network_groups (slug, name, type, capacity, is_restricted, created_at, updated_at) VALUES (:slug, :name, :type, NULL, 0, :created_at, :updated_at)');
    $now = date('Y-m-d H:i:s');
    foreach ($base as $g) {
        $stmt->execute([
            'slug' => $g['slug'],
            'name' => $g['name'],
            'type' => $g['type'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

function assignGroupsForAccount(int $accountId): void
{
    $pdo = db();
    $stmtAcc = $pdo->prepare('SELECT * FROM network_accounts WHERE id = :id');
    $stmtAcc->execute(['id' => $accountId]);
    $acc = $stmtAcc->fetch();
    if (!$acc) return;
    ensureBaseGroups();
    $groups = [];
    $groups[] = findOrCreateGroup('general', 'Geral', 'general');
    $pref = $acc['political_pref'] ?? 'neutral';
    if ($pref === 'left') $groups[] = findOrCreateGroup('politics-left', 'Política - Esquerda', 'politics');
    elseif ($pref === 'right') $groups[] = findOrCreateGroup('politics-right', 'Política - Direita', 'politics');
    else $groups[] = findOrCreateGroup('politics-neutral', 'Política - Neutro', 'politics');

    $regionSlug = $acc['state'] ? 'region-' . slug((string)$acc['state']) : null;
    if ($regionSlug) $groups[] = findOrCreateGroup($regionSlug, 'Região ' . strtoupper((string)$acc['state']), 'region');

    $segmentSlug = $acc['segment'] ? 'segment-' . slug((string)$acc['segment']) : null;
    if ($segmentSlug) $groups[] = findOrCreateGroup($segmentSlug, 'Atividade: ' . $acc['segment'], 'activity');

    if ($segmentSlug) {
        $unique = findOrCreateActivityUniqueGroup((string)$acc['segment']);
        if ($unique) $groups[] = $unique;
    }

    $objectives = [];
    if (!empty($acc['objectives']) && is_string($acc['objectives'])) {
        $dec = json_decode($acc['objectives'], true);
        if (is_array($dec)) $objectives = $dec;
    }
    foreach ($objectives as $obj) {
        $slug = 'obj-' . slug((string)$obj);
        $groups[] = findOrCreateGroup($slug, 'Objetivo: ' . $obj, 'objective');
    }

    foreach ($groups as $g) {
        if (!$g) continue;
        addMemberToGroup($accountId, (int)$g['id']);
    }
}

function findOrCreateGroup(string $slug, string $name, string $type): ?array
{
    $stmt = db()->prepare('SELECT * FROM network_groups WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);
    $g = $stmt->fetch();
    if ($g) return $g;
    $now = date('Y-m-d H:i:s');
    $stmtIns = db()->prepare('INSERT INTO network_groups (slug, name, type, created_at, updated_at) VALUES (:slug, :name, :type, :created_at, :updated_at)');
    $stmtIns->execute(['slug' => $slug, 'name' => $name, 'type' => $type, 'created_at' => $now, 'updated_at' => $now]);
    $id = (int)db()->lastInsertId();
    return ['id' => $id, 'slug' => $slug, 'name' => $name, 'type' => $type];
}

function findOrCreateActivityUniqueGroup(string $segment): ?array
{
    $base = slug($segment);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT g.id, g.slug, (SELECT COUNT(*) FROM network_group_members m WHERE m.group_id = g.id) AS members FROM network_groups g WHERE g.type = :type AND g.slug LIKE :slug ORDER BY g.id ASC');
    $stmt->execute(['type' => 'activity_unique', 'slug' => 'activity-unique-' . $base . '%']);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        if ((int)$row['members'] < 1) {
            return ['id' => $row['id'], 'slug' => $row['slug'], 'name' => 'Atividade única: ' . $segment, 'type' => 'activity_unique'];
        }
    }
    $suffix = count($rows) + 1;
    return findOrCreateGroup('activity-unique-' . $base . '-' . $suffix, 'Atividade única: ' . $segment . ' #' . $suffix, 'activity_unique');
}

function addMemberToGroup(int $accountId, int $groupId): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO network_group_members (group_id, account_id, role, created_at) VALUES (:group_id, :account_id, :role, :created_at)');
    $stmt->execute([
        'group_id' => $groupId,
        'account_id' => $accountId,
        'role' => 'member',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function listGroupsForAccount(int $accountId): array
{
    $stmt = db()->prepare('SELECT g.* FROM network_group_members m INNER JOIN network_groups g ON g.id = m.group_id WHERE m.account_id = :id ORDER BY g.type, g.name');
    $stmt->execute(['id' => $accountId]);
    return $stmt->fetchAll() ?: [];
}

// ---------- Mensagens ----------
function canSendMessage(int $accountId): bool
{
    $key = 'msg_rate_' . $accountId;
    $now = time();
    $_SESSION[$key] = array_filter($_SESSION[$key] ?? [], fn($ts) => ($now - $ts) < 60);
    return count($_SESSION[$key]) < 10;
}

function rateLimitMessage(int $accountId): void
{
    $key = 'msg_rate_' . $accountId;
    $_SESSION[$key][] = time();
}

function canInteractByPolitics(array $from, array $to): bool
{
    $f = $from['political_pref'] ?? 'neutral';
    $t = $to['political_pref'] ?? 'neutral';
    if ($f === 'neutral') return true;
    if ($t === 'neutral') return true;
    return $f === $t;
}

function sendMessage(int $fromId, ?int $toId, ?int $groupId, string $body): int
{
    $stmt = db()->prepare('INSERT INTO network_messages (sender_id, recipient_id, group_id, body, created_at) VALUES (:sender_id, :recipient_id, :group_id, :body, :created_at)');
    $stmt->execute([
        'sender_id' => $fromId,
        'recipient_id' => $toId,
        'group_id' => $groupId,
        'body' => mb_substr($body, 0, 1000),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return (int)db()->lastInsertId();
}

function listMessages(array $account): array
{
    $id = (int)$account['id'];
    $stmt = db()->prepare('SELECT m.*, s.name AS sender_name, r.name AS recipient_name, r.email AS recipient_email, s.email AS sender_email, s.political_pref AS sender_pref, r.political_pref AS recipient_pref FROM network_messages m
        LEFT JOIN network_accounts s ON s.id = m.sender_id
        LEFT JOIN network_accounts r ON r.id = m.recipient_id
        WHERE (m.sender_id = :id OR m.recipient_id = :id) AND m.group_id IS NULL
        ORDER BY m.created_at DESC LIMIT 50');
    $stmt->execute(['id' => $id]);
    $rows = $stmt->fetchAll() ?: [];
    $out = [];
    foreach ($rows as $row) {
        $fromPref = $row['sender_pref'] ?? 'neutral';
        $toPref = $row['recipient_pref'] ?? 'neutral';
        $fromAcc = ['political_pref' => $fromPref];
        $toAcc = ['political_pref' => $toPref];
        if (!canInteractByPolitics($fromAcc, $toAcc)) continue;
        $out[] = $row;
    }
    return $out;
}
