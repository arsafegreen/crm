<?php
/**
 * AI Chat - admin vs publico
 * Admin: GitHub + OpenAI + FS write.
 * Publico (AI_CHAT_ALLOW_PUBLIC=1): OpenAI e FS somente leitura.
 */
session_start();

// ------------- Utils -----------------
function loadEnv($path)
{
    if (!is_readable($path)) return [];
    $env = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (is_array($env)) return $env;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $parsed = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $parsed[$key] = trim($val, "\"' ");
    }
    return $parsed;
}

function fsSafePath($base, $path)
{
    $normalized = str_replace(['\\', '//'], '/', $path);
    $normalized = ltrim($normalized, '/');
    $full = realpath($base . '/' . $normalized);
    if ($full === false) $full = $base . '/' . $normalized;
    $realBase = realpath($base);
    if ($realBase && strpos($full, $realBase) !== 0) return null;
    return $full;
}

function appendLog($path, array $entry)
{
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function loadRagIndex($path)
{
    if (!is_readable($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $rows = [];
    foreach ($lines ?: [] as $ln) {
        $row = json_decode($ln, true);
        if (!isset($row['path'], $row['embedding']) || !is_array($row['embedding'])) continue;
        $rows[] = $row;
    }
    return $rows;
}

function cosineSim(array $a, array $b)
{
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $len = min(count($a), count($b));
    for ($i=0; $i<$len; $i++) {
        $dot += $a[$i]*$b[$i];
        $na += $a[$i]*$a[$i];
        $nb += $b[$i]*$b[$i];
    }
    if ($na <= 0 || $nb <= 0) return 0.0;
    return $dot / (sqrt($na)*sqrt($nb));
}

function readLogTail($path, $limit = 20)
{
    if (!is_readable($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $entries = [];
    foreach (array_slice($lines, -$limit) as $ln) {
        $decoded = json_decode($ln, true);
        if ($decoded) $entries[] = $decoded;
    }
    return array_reverse($entries);
}

function fetchLatestAgentReply($owner, $repo, $issue, $token)
{
    if (!$token || !$issue) return ['reply' => '', 'error' => ''];
    $url = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$issue}/comments?per_page=10&sort=created&direction=desc";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: ai-chat-bridge',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $status < 200 || $status >= 300) return ['reply' => '', 'error' => $err ?: $status];
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) return ['reply' => '', 'error' => 'parse'];
    foreach ($decoded as $c) {
        $body = $c['body'] ?? '';
        if (stripos($body, '/ai ') === 0 || stripos($body, '/ai-apply ') === 0) continue;
        return ['reply' => $body, 'error' => ''];
    }
    return ['reply' => '', 'error' => ''];
}

function openaiEmbedding($openaiKey, $text)
{
    $url = 'https://api.openai.com/v1/embeddings';
    $payload = json_encode([
        'model' => 'text-embedding-3-large',
        'input' => $text,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $openaiKey,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr || $status < 200 || $status >= 300) return ['error' => $curlErr ?: $status, 'vector' => []];
    $decoded = json_decode($response, true);
    $vec = $decoded['data'][0]['embedding'] ?? [];
    if (!is_array($vec) || empty($vec)) return ['error'=>'no_vector','vector'=>[]];
    return ['error'=>'', 'vector'=>$vec];
}

function githubOauthExchange($clientId, $clientSecret, $code, $redirectUri)
{
    $url = 'https://github.com/login/oauth/access_token';
    $payload = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $status < 200 || $status >= 300) return ['error' => $err ?: $status, 'token' => ''];
    $decoded = json_decode($resp, true);
    return ['error' => '', 'token' => $decoded['access_token'] ?? ''];
}

function githubFetchUser($token)
{
    if (!$token) return ['login' => '', 'error' => 'no_token'];
    $ch = curl_init('https://api.github.com/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: ai-chat-bridge',
            'Authorization: Bearer ' . $token,
        ],
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $status < 200 || $status >= 300) return ['login' => '', 'error' => $err ?: $status];
    $decoded = json_decode($resp, true);
    return ['login' => $decoded['login'] ?? '', 'error' => ''];
}

// ------------- Env --------------------
$env = loadEnv(__DIR__ . '/.env');
$envPath = __DIR__ . '/.env';
$logPath = __DIR__ . '/storage/ai-chat.log';
$ragIndexPath = __DIR__ . '/storage/ai-rag.jsonl';
$ghClientId = getenv('GITHUB_CLIENT_ID') ?: ($env['GITHUB_CLIENT_ID'] ?? '');
$ghClientSecret = getenv('GITHUB_CLIENT_SECRET') ?: ($env['GITHUB_CLIENT_SECRET'] ?? '');

$token = getenv('GH_AGENT_TOKEN') ?: ($env['GH_AGENT_TOKEN'] ?? '');
$owner = getenv('GH_AGENT_OWNER') ?: ($env['GH_AGENT_OWNER'] ?? 'arsafegreen');
$repo = getenv('GH_AGENT_REPO') ?: ($env['GH_AGENT_REPO'] ?? 'crm');
$defaultIssue = getenv('GH_AGENT_ISSUE') ?: ($env['GH_AGENT_ISSUE'] ?? '');
$openaiKey = getenv('OPENAI_API_KEY') ?: ($env['OPENAI_API_KEY'] ?? '');
$authUser = getenv('AI_CHAT_USER') ?: ($env['AI_CHAT_USER'] ?? '');
$authPass = getenv('AI_CHAT_PASS') ?: ($env['AI_CHAT_PASS'] ?? '');
$allowPublic = getenv('AI_CHAT_ALLOW_PUBLIC') ?: ($env['AI_CHAT_ALLOW_PUBLIC'] ?? '0');
$allowPublic = in_array(strtolower((string)$allowPublic), ['1','true','yes','on'], true);
$isAdmin = false;
$fsRoot = realpath(__DIR__);

$message = $error = $responseText = $agentReply = '';
$issue = $pollIssue = $defaultIssue;
$startPoll = false;
$displayReply = $displayReplySource = '';

// ------------- Auth -------------------
if ($authUser && $authPass) {
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($u === $authUser && $p === $authPass) {
        $isAdmin = true;
    } elseif (!$allowPublic) {
        header('WWW-Authenticate: Basic realm="AI Chat"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Auth required.';
        exit;
    }
} else {
    $isAdmin = true; // compat
}

// ------------- API: Poll --------------
if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    header('Content-Type: application/json');
    $issue = trim($_GET['issue'] ?? '') ?: $defaultIssue;
    if (!$token) { echo json_encode(['status'=>'error','message'=>'Token ausente']); exit; }
    if (!$issue){ echo json_encode(['status'=>'error','message'=>'Issue ausente']); exit; }
    $latest = fetchLatestAgentReply($owner, $repo, $issue, $token);
    if ($latest['reply']) echo json_encode(['status'=>'ready','reply'=>$latest['reply']]);
    else echo json_encode(['status'=>'waiting']);
    exit;
}

// ------------- API: FS ----------------
if (isset($_GET['action']) && in_array($_GET['action'], ['fs-list','fs-read','fs-write'], true)) {
    header('Content-Type: application/json');
    $pathParam = $_GET['path'] ?? '';
    $target = fsSafePath($fsRoot, $pathParam);
    if (!$target) { echo json_encode(['status'=>'error','message'=>'Caminho invalido']); exit; }

    if ($_GET['action'] === 'fs-list') {
        if (!is_dir($target)) { echo json_encode(['status'=>'error','message'=>'Diretorio nao encontrado']); exit; }
        $items = @scandir($target) ?: [];
        $items = array_values(array_filter($items, fn($i) => $i !== '.' && $i !== '..'));
        $entries = [];
        foreach ($items as $i) {
            $full = $target . DIRECTORY_SEPARATOR . $i;
            $type = is_dir($full) ? 'dir' : (is_file($full) ? 'file' : 'other');
            $entries[] = ['name'=>$i,'type'=>$type];
        }
        echo json_encode(['status'=>'ok','entries'=>$entries]); exit;
    }

    if ($_GET['action'] === 'fs-read') {
        if (!is_file($target)) { echo json_encode(['status'=>'error','message'=>'Arquivo nao encontrado']); exit; }
        $content = @file_get_contents($target);
        if ($content === false) { echo json_encode(['status'=>'error','message'=>'Falha ao ler']); exit; }
        echo json_encode(['status'=>'ok','content'=>$content]); exit;
    }

    if ($_GET['action'] === 'fs-write') {
        if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Somente admin pode gravar']); exit; }
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $content = $payload['content'] ?? null;
        if ($content === null) { echo json_encode(['status'=>'error','message'=>'Conteudo ausente']); exit; }
        $dir = dirname($target);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents($target, $content) === false) { echo json_encode(['status'=>'error','message'=>'Falha ao gravar']); exit; }
        appendLog($logPath, ['ts'=>date('c'),'user'=>$_SERVER['PHP_AUTH_USER'] ?? 'n/a','fs_write'=>$target]);
        echo json_encode(['status'=>'ok']); exit;
    }
}

// ------------- API: Search (context helper) -------------
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');
    $term = trim($_GET['term'] ?? '');
    if ($term === '' || strlen($term) < 2) { echo json_encode(['status'=>'error','message'=>'Termo muito curto']); exit; }
    $maxFiles = 6;
    $maxPerFile = 2;
    $skipDirs = ['node_modules','vendor','storage','tmp','logs','cache','.git'];
    $results = [];
    $dirIter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($dirIter as $file) {
        if (count($results) >= $maxFiles) break;
        if (!$file->isFile()) continue;
        $rel = ltrim(str_replace($fsRoot, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        if (count(array_intersect($parts, $skipDirs)) > 0) continue;
        $size = $file->getSize();
        if ($size > 200_000) continue; // skip big
        $content = @file_get_contents($file->getPathname());
        if ($content === false) continue;
        $matches = [];
        $lines = preg_split('/\r?\n/', $content);
        foreach ($lines as $idx => $line) {
            if (stripos($line, $term) !== false) {
                $start = max(0, $idx - 2);
                $chunk = array_slice($lines, $start, 5);
                $matches[] = ['line'=>$idx+1,'snippet'=>implode("\n", $chunk)];
                if (count($matches) >= $maxPerFile) break;
            }
        }
        if ($matches) $results[] = ['file'=>$rel,'matches'=>$matches];
    }
    echo json_encode(['status'=>'ok','results'=>$results]); exit;
}

// ------------- API: RAG index/search --------------------
if (isset($_GET['action']) && $_GET['action'] === 'rag-index') {
    header('Content-Type: application/json');
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Somente admin']); exit; }
    if (!$openaiKey) { echo json_encode(['status'=>'error','message'=>'OPENAI_API_KEY ausente']); exit; }
    $skipDirs = ['node_modules','vendor','storage','tmp','logs','cache','.git'];
    $maxSize = 120000;
    $maxFiles = 80;
    $indexed = 0;
    $fh = @fopen($ragIndexPath, 'w');
    if (!$fh) { echo json_encode(['status'=>'error','message'=>'Nao foi possivel criar indice']); exit; }
    $dirIter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fsRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($dirIter as $file) {
        if ($indexed >= $maxFiles) break;
        if (!$file->isFile()) continue;
        $rel = ltrim(str_replace($fsRoot, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        if (count(array_intersect($parts, $skipDirs)) > 0) continue;
        $size = $file->getSize();
        if ($size > $maxSize) continue;
        $content = @file_get_contents($file->getPathname());
        if ($content === false) continue;
        $text = mb_substr($content, 0, 6000, 'UTF-8');
        $emb = openaiEmbedding($openaiKey, $text);
        if ($emb['error'] || empty($emb['vector'])) continue;
        fwrite($fh, json_encode(['path'=>$rel,'embedding'=>$emb['vector']], JSON_UNESCAPED_UNICODE) . "\n");
        $indexed++;
    }
    fclose($fh);
    echo json_encode(['status'=>'ok','indexed'=>$indexed]); exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'rag-search') {
    header('Content-Type: application/json');
    if (!$openaiKey) { echo json_encode(['status'=>'error','message'=>'OPENAI_API_KEY ausente']); exit; }
    $term = trim($_GET['term'] ?? '');
    if ($term === '') { echo json_encode(['status'=>'error','message'=>'Termo vazio']); exit; }
    if (!is_readable($ragIndexPath)) { echo json_encode(['status'=>'error','message'=>'Indice inexistente. Rode rag-index.']); exit; }
    $q = openaiEmbedding($openaiKey, $term);
    if ($q['error'] || empty($q['vector'])) { echo json_encode(['status'=>'error','message'=>'Falha ao gerar embedding']); exit; }
    $index = loadRagIndex($ragIndexPath);
    $scored = [];
    foreach ($index as $row) {
        $score = cosineSim($q['vector'], $row['embedding']);
        $scored[] = ['path'=>$row['path'], 'score'=>$score];
    }
    usort($scored, fn($a,$b)=>($b['score']<=>$a['score']));
    $top = array_slice($scored, 0, 6);
    $results = [];
    foreach ($top as $t) {
        $file = fsSafePath($fsRoot, $t['path']);
        if (!$file || !is_readable($file)) continue;
        $content = @file_get_contents($file);
        if ($content === false) continue;
        $snippet = mb_substr($content, 0, 900, 'UTF-8');
        $results[] = ['file'=>$t['path'],'score'=>round($t['score'],4),'snippet'=>$snippet];
    }
    echo json_encode(['status'=>'ok','results'=>$results]); exit;
}

// ------------- Pre-checks -------------
if (!function_exists('curl_init')) $error = 'Extensao cURL nao esta habilitada.';
$logDir = dirname($logPath);
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }

// ------------- Main submit ------------
$showAllHistory = isset($_GET['show_all_history']) && $_GET['show_all_history'] === '1';
$history = readLogTail($logPath, $showAllHistory ? 200 : 10);
$configHiddenAttr = $defaultIssue ? 'hidden' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (isset($_POST['save_token'])) {
        if (!$isAdmin) { $error = 'Somente admin pode salvar token.'; goto render; }
        $newToken = trim($_POST['token'] ?? '');
        if (!$newToken) { $error = 'Informe um token para salvar.'; goto render; }
        $envRaw = is_readable($envPath) ? file_get_contents($envPath) : '';
        $lines = $envRaw !== '' ? preg_split('/\r?\n/', $envRaw) : [];
        $updated = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*GH_AGENT_TOKEN\s*=\s*/', $line)) { $lines[$i] = 'GH_AGENT_TOKEN=' . $newToken; $updated = true; break; }
        }
        if (!$updated) $lines[] = 'GH_AGENT_TOKEN=' . $newToken;
        if (@file_put_contents($envPath, implode("\n", $lines)) === false) { $error = 'Nao foi possivel gravar no .env.'; goto render; }
        $token = $newToken; $message = 'Token salvo no .env.'; $env['GH_AGENT_TOKEN'] = $newToken; goto render;
    }
    if (isset($_POST['save_openai'])) {
        if (!$isAdmin) { $error = 'Somente admin pode salvar chave OpenAI.'; goto render; }
        $newKey = trim($_POST['openai_key'] ?? '');
        if (!$newKey) { $error = 'Informe uma chave OpenAI.'; goto render; }
        $envRaw = is_readable($envPath) ? file_get_contents($envPath) : '';
        $lines = $envRaw !== '' ? preg_split('/\r?\n/', $envRaw) : [];
        $updated = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*OPENAI_API_KEY\s*=\s*/', $line)) { $lines[$i] = 'OPENAI_API_KEY=' . $newKey; $updated = true; break; }
        }
        if (!$updated) $lines[] = 'OPENAI_API_KEY=' . $newKey;
        if (@file_put_contents($envPath, implode("\n", $lines)) === false) { $error = 'Nao foi possivel gravar no .env.'; goto render; }
        $openaiKey = $newKey; $message = 'Chave OpenAI salva no .env.'; $env['OPENAI_API_KEY'] = $newKey; goto render;
    }

    $prompt = trim($_POST['prompt'] ?? '');
    $context = trim($_POST['context'] ?? '');
    $apply = isset($_POST['apply']);
    $issue = trim($_POST['issue'] ?? '');
    $provider = $_POST['provider'] ?? 'openai';
    $fullPrompt = $prompt;
    if ($context !== '') $fullPrompt .= "\n\nContexto fornecido:\n" . $context;
    if ($defaultIssue && !$issue) $issue = $defaultIssue;

    if ($provider === 'github' && !$isAdmin) {
        $error = 'Somente admin pode usar GitHub.';
    } elseif (!$prompt) {
        $error = 'Informe um prompt.';
    } elseif ($provider === 'github') {
        if (!$token) {
            $error = 'GH_AGENT_TOKEN nao encontrado.';
        } elseif (!$issue) {
            $error = 'Informe o numero do issue.';
        } else {
            $body = ($apply ? '/ai-apply ' : '/ai ') . $fullPrompt;
            $url = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$issue}/comments";
            $payload = json_encode(['body' => $body], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'Content-Type: application/json',
                    'User-Agent: ai-chat-bridge',
                    'Authorization: Bearer ' . $token,
                ],
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($curlErr) {
                $error = 'Erro ao chamar GitHub: ' . $curlErr;
            } elseif ($status < 200 || $status >= 300) {
                $error = 'GitHub retornou status ' . $status . ': ' . $response;
            } else {
                $message = 'Comentario enviado. Aguardando resposta do agente...';
                appendLog($logPath, [
                    'ts' => date('c'), 'user' => $_SERVER['PHP_AUTH_USER'] ?? 'n/a',
                    'issue' => $issue, 'apply' => $apply, 'prompt' => $fullPrompt, 'provider' => 'github',
                ]);
                $startPoll = true; $pollIssue = $issue; $history = readLogTail($logPath, 15);
            }
        }
    } else {
        if (!$openaiKey) {
            $error = 'OPENAI_API_KEY nao encontrado.';
        } else {
            $url = 'https://api.openai.com/v1/chat/completions';
            $payload = json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $fullPrompt]],
                'temperature' => 0.2,
            ], JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json',
                ],
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($curlErr) {
                $error = 'Erro ao chamar OpenAI: ' . $curlErr;
            } elseif ($status < 200 || $status >= 300) {
                $error = 'OpenAI retornou status ' . $status . ': ' . $response;
            } else {
                $decoded = json_decode($response, true);
                $responseText = $decoded['choices'][0]['message']['content'] ?? '';
                $message = 'Resposta recebida.';
                appendLog($logPath, [
                    'ts' => date('c'), 'user' => $_SERVER['PHP_AUTH_USER'] ?? 'n/a',
                    'issue' => $issue, 'apply' => $apply, 'prompt' => $fullPrompt,
                    'provider' => 'openai', 'response' => $responseText,
                ]);
                $history = readLogTail($logPath, 15);
            }
        }
    }
}

$displayReply = $agentReply ?: $responseText;
if ($displayReply) {
    $displayReplySource = $agentReply ? 'GitHub Agent' : 'OpenAI direto';
} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST' && $defaultIssue && $token) {
    $latest = fetchLatestAgentReply($owner, $repo, $defaultIssue, $token);
    if (!empty($latest['reply'])) {
        $displayReply = $latest['reply'];
        $displayReplySource = 'GitHub Agent';
    }
}

render:
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Agent Chat</title>
  <style>
    :root { --bg:#0a0d18; --panel:#11182b; --panel2:#0c1222; --border:rgba(255,255,255,0.08); --text:#e5ecff; --muted:#9ba8c7; --accent:#4cc0ff; --ok:#5fe1a5; }
    *{box-sizing:border-box;} body{margin:0;font-family:'Inter',system-ui,-apple-system,'Segoe UI',sans-serif;background:#050913;color:var(--text);} h1,h2,p{margin:0;} a{color:var(--accent);}
    .card{max-width:1180px;margin:32px auto;padding:20px;background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,0.35);}
    .panel{border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--panel2);}
    textarea{width:100%;min-height:120px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#0f1429;color:var(--text);font:inherit;} input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid var(--border);background:#0f1429;color:var(--text);font:inherit;}
    button{padding:10px 14px;border-radius:10px;border:1px solid var(--border);background:var(--accent);color:#041022;font-weight:600;cursor:pointer;} button[disabled]{opacity:.5;cursor:not-allowed;}
    .ghost-btn{background:transparent;color:var(--text);} .ghost-btn:hover{border-color:var(--accent);color:var(--accent);} .toolbar,.inline-actions,.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .status-bar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;} .pill{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:999px;background:rgba(255,255,255,0.05);border:1px solid var(--border);font-size:.9rem;}
    .dot{width:10px;height:10px;border-radius:50%;display:inline-block;background:var(--muted);} .ok{background:var(--ok);} .warn{background:#ffb74d;}
    .alert{padding:10px 12px;border-radius:10px;margin:8px 0;} .alert.error{background:rgba(255,99,99,0.12);border:1px solid rgba(255,99,99,0.4);} .alert.success{background:rgba(95,225,165,0.12);border:1px solid rgba(95,225,165,0.4);}
    .small-note{color:var(--muted);font-size:.9rem;} .tag{display:inline-block;padding:4px 8px;border-radius:8px;background:rgba(255,255,255,0.06);color:var(--muted);font-size:.8rem;}
    .fs-panel{display:grid;gap:8px;} .fs-list{display:grid;gap:6px;max-height:260px;overflow:auto;border:1px solid var(--border);border-radius:8px;padding:8px;background:rgba(255,255,255,0.02);}
    .fs-entry{width:100%;text-align:left;padding:8px 10px;border-radius:8px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);cursor:pointer;} .fs-entry:hover{border-color:var(--accent);color:var(--accent);}
    .fs-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;} .toast-stack{position:fixed;top:16px;right:16px;display:grid;gap:10px;z-index:999;}
    .toast{min-width:220px;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:rgba(17,24,43,0.95);color:var(--text);box-shadow:0 8px 26px rgba(0,0,0,0.35);} .toast.ok{border-color:rgba(61,213,152,0.5);} .toast.err{border-color:rgba(255,107,107,0.5);}
    .tab-nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center;} .tab-btn{background:rgba(255,255,255,0.05);color:var(--text);border:1px solid var(--border);} .tab-btn.active{background:var(--accent);color:#041022;border-color:var(--accent);}
    .tab-panels{display:grid;gap:16px;} .topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
  </style>
</head>
<body>
  <div class="toast-stack" data-toast-stack></div>
  <div class="card">
    <div class="topbar">
      <div>
        <h1>AI Agent</h1>
        <p class="small-note">Chat, arquivos e config em abas simples.</p>
      </div>
      <div class="tab-nav">
        <button type="button" class="tab-btn active" data-tab-btn="chat" title="Conversar com OpenAI ou GitHub"><?= $isAdmin ? 'Chat (admin)' : 'Chat' ?></button>
        <button type="button" class="tab-btn" data-tab-btn="files" title="Explorar htdocs e editar (se admin)">Arquivos</button>
        <button type="button" class="tab-btn" data-tab-btn="history" title="Ver ultimos envios">Historico</button>
        <button type="button" class="tab-btn" data-tab-btn="config" title="Tokens, issue e chaves">Config</button>
        <button type="button" class="ghost-btn" data-refresh-latest title="Buscar ultima resposta agora">Atualizar</button>
      </div>
    </div>

    <div class="status-bar">
      <span class="pill" title="Token usado para /ai no GitHub"><span class="dot <?= $token ? 'ok' : 'warn' ?>"></span>Token <?= $token ? 'ok' : 'faltando' ?></span>
      <span class="pill" title="Issue padrao do repo"><span class="dot <?= $defaultIssue ? 'ok' : 'warn' ?>"></span>Issue #<?= $defaultIssue ? htmlspecialchars($defaultIssue, ENT_QUOTES, 'UTF-8') : 'defina' ?></span>
      <span class="pill" title="Permissao atual"><span class="dot ok"></span><?= $isAdmin ? 'Admin' : 'Leitura' ?></span>
      <span class="pill" title="Limite de escrita"><span class="dot ok"></span>FS htdocs<?= $isAdmin ? ' (rw)' : ' (r)' ?></span>
    </div>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php elseif ($message): ?><div class="alert success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="tab-panels">
      <div data-tab-panel="chat">
        <div data-poll-status class="small-note" hidden></div>
        <div data-agent-reply-container hidden></div>
        <?php if ($displayReply): ?>
          <div class="panel" style="margin:12px 0;">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
              <h2>Ultima resposta</h2><span class="small-note"><?= htmlspecialchars($displayReplySource, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div style="white-space:pre-wrap; margin-top:6px;">
              <?= htmlspecialchars($displayReply, ENT_QUOTES, 'UTF-8') ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="panel">
          <h2>Chat</h2>
          <form method="POST">
            <label for="provider">Canal</label>
            <?php $selectedProvider = $_POST['provider'] ?? 'openai'; ?>
            <select id="provider" name="provider" <?= $isAdmin ? '' : 'disabled' ?> title="<?= $isAdmin ? 'Escolha GitHub (/ai) ou OpenAI' : 'Somente OpenAI em modo leitura' ?>">
              <?php if ($isAdmin): ?><option value="github" <?= $selectedProvider === 'github' ? 'selected' : '' ?>>GitHub Agent</option><?php endif; ?>
              <option value="openai" <?= $selectedProvider === 'openai' ? 'selected' : '' ?>>OpenAI direto</option>
            </select>
            <?php if (!$isAdmin): ?><p class="small-note">Modo leitura usa apenas OpenAI.</p><?php endif; ?>

            <label for="issue_chat">Issue (GitHub)</label>
            <input id="issue_chat" name="issue" placeholder="Opcional, ex.: 1" value="<?= htmlspecialchars($defaultIssue, ENT_QUOTES, 'UTF-8') ?>" <?= $isAdmin ? '' : 'disabled' ?> title="Defina o issue que recebera /ai">

            <label for="prompt">Pedido</label>
            <div class="inline-actions">
              <button type="button" class="preset-btn" data-preset="Revisar e sugerir melhorias no arquivo X">Revisar arquivo</button>
              <button type="button" class="preset-btn" data-preset="Aplicar correcao de bug descrita no contexto e gerar patch /ai-apply">Aplicar correcao</button>
              <button type="button" class="preset-btn" data-preset="Gerar resumo tecnico do modulo X e listar riscos">Resumo tecnico</button>
            </div>
            <textarea id="prompt" name="prompt" placeholder="Ex.: Corrigir validacao do formulario de cadastro"></textarea>

            <label for="context">Contexto (opcional)</label>
            <div class="fs-actions" style="align-items:flex-start;">
              <textarea id="context" name="context" placeholder="Cole o trecho relevante de codigo." style="flex:1; min-height:120px;"></textarea>
              <div style="display:grid;gap:6px;min-width:180px;">
                <input id="context-search-term" placeholder="palavra-chave" title="Termo para buscar trechos no codigo">
                <button type="button" class="ghost-btn" data-search-context title="Busca trechos em htdocs e cola no contexto">Buscar contexto</button>
                <button type="button" class="ghost-btn" data-rag-context title="Usa embeddings para achar arquivos relevantes (precisa indice)">Buscar RAG</button>
                <button type="button" class="ghost-btn" data-rag-index title="Recria indice RAG (admin + chave OpenAI)">Recriar indice</button>
              </div>
            </div>

            <div class="actions">
              <label class="checkbox" title="Somente admin envia /ai-apply"><input type="checkbox" name="apply" <?= $isAdmin ? '' : 'disabled' ?>> Usar /ai-apply</label>
              <button type="submit">Enviar</button>
            </div>
          </form>
        </div>
      </div>

      <div data-tab-panel="files" hidden>
        <div class="panel fs-panel" style="align-self:flex-start;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <h2>Arquivos (htdocs)</h2><span class="small-note"><?= $isAdmin ? 'Leitura/gravar' : 'Somente leitura' ?></span>
          </div>
          <label for="fs-path">Caminho</label>
          <div class="fs-actions">
            <input id="fs-path" value="." style="flex:1; min-width:160px;" title="Diretorio atual">
            <button type="button" class="ghost-btn" data-fs-up title="Subir um nivel">Subir</button>
            <button type="button" class="ghost-btn" data-fs-list title="Listar diretorio">Listar</button>
          </div>
          <div class="fs-list" data-fs-listing><span class="small-note">Use "Listar" para ver o diretorio atual.</span></div>
          <label for="fs-current">Arquivo</label><input id="fs-current" readonly placeholder="Nenhum arquivo" title="Caminho selecionado">
          <label for="fs-content">Conteudo</label>
          <textarea id="fs-content" placeholder="Selecione um arquivo para editar" style="min-height:180px;" <?= $isAdmin ? '' : 'disabled' ?>></textarea>
          <div class="fs-actions">
            <button type="button" class="ghost-btn" data-fs-reload title="Recarregar">Recarregar</button>
            <button type="button" data-fs-save <?= $isAdmin ? '' : 'disabled' ?> title="<?= $isAdmin ? 'Salvar arquivo' : 'Somente admin salva' ?>">Salvar</button>
          </div>
          <p class="small-note">Somente caminhos sob htdocs. Gravacao apenas admin (logada).</p>
        </div>
      </div>

      <div data-tab-panel="history" hidden>
        <div class="panel" style="align-self:flex-start;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;"><h2>Historico</h2><span class="small-note">Ultimos envios</span></div>
          <?php if (empty($history)): ?>
            <p class="small-note">Nenhum registro ainda.</p>
          <?php else: ?>
            <div style="display:grid; gap:8px; max-height: 420px; overflow:auto;">
              <?php foreach ($history as $h): ?>
                <div style="border:1px solid var(--border); border-radius:10px; padding:8px; background:var(--panel2);">
                  <div style="display:flex; justify-content:space-between; gap:8px; font-size:0.85rem; color: var(--muted);">
                    <span><?= htmlspecialchars($h['ts'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= !empty($h['issue']) ? 'Issue #' . htmlspecialchars($h['issue'], ENT_QUOTES, 'UTF-8') : 'Sem issue' ?></span>
                  </div>
                  <div style="font-size:0.85rem; color: var(--muted); margin-top:4px;">
                    Usuario: <?= htmlspecialchars($h['user'] ?? 'n/a', ENT_QUOTES, 'UTF-8') ?> | <?= (!empty($h['apply']) ? '/ai-apply' : '/ai') ?> | Canal: <?= htmlspecialchars($h['provider'] ?? 'github', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div style="margin-top:6px; white-space:pre-wrap; font-size:0.95rem; color:var(--text);">
                    <?= htmlspecialchars($h['prompt'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <?php if (!empty($h['response'])): ?>
                    <div style="margin-top:6px; padding:8px; border:1px dashed var(--border); border-radius:8px; background:rgba(255,255,255,0.02);">
                      <div style="font-size:0.82rem; color: var(--muted); margin-bottom:4px;">Resposta</div>
                      <div style="white-space:pre-wrap; font-size:0.92rem; color: var(--text);">
                        <?= htmlspecialchars($h['response'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (!$showAllHistory): ?>
              <div class="toolbar" style="justify-content:flex-start;margin-top:8px;"><a class="ghost-btn" href="?show_all_history=1">Ver mais</a></div>
            <?php else: ?>
              <div class="toolbar" style="justify-content:flex-start;margin-top:8px;"><a class="ghost-btn" href="?">Ver menos</a></div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div data-tab-panel="config" hidden>
        <div class="panel" style="align-self:flex-start;">
          <div style="display:flex;justify-content:space-between;align-items:center;"><h2>Config</h2><span class="tag"><?= $isAdmin ? 'Admin' : 'Leitura' ?></span></div>
          <label for="owner" title="Owner do repo GitHub">Owner</label><input id="owner" value="<?= htmlspecialchars($owner, ENT_QUOTES, 'UTF-8') ?>" disabled>
          <label for="repo" title="Repositorio">Repo</label><input id="repo" value="<?= htmlspecialchars($repo, ENT_QUOTES, 'UTF-8') ?>" disabled>
          <label for="issue_default" style="margin-top:8px;">Issue padrao</label>
          <input id="issue_default" value="<?= htmlspecialchars($defaultIssue, ENT_QUOTES, 'UTF-8') ?>" placeholder="ex.: 1" <?= $isAdmin ? '' : 'disabled' ?> title="Defina GH_AGENT_ISSUE no .env para fixar">
          <hr style="border:0;border-top:1px solid var(--border);margin:12px 0;">
          <form method="POST" style="display:grid; gap:8px;" <?= $isAdmin ? '' : 'aria-disabled="true"' ?> aria-label="Salvar token">
            <label for="token">Atualizar token</label>
            <input id="token" name="token" type="password" placeholder="Cole o GH_AGENT_TOKEN..." <?= $isAdmin ? '' : 'disabled' ?>>
            <p class="small-note">Salva no .env (GH_AGENT_TOKEN).</p>
            <button type="submit" class="ghost-btn" name="save_token" value="1" style="justify-self:flex-start;" <?= $isAdmin ? '' : 'disabled' ?>>Salvar token</button>
          </form>
          <p class="small-note">Token nao vai para o navegador.</p>
          <hr style="border:0;border-top:1px solid var(--border);margin:12px 0;">
          <form method="POST" style="display:grid; gap:8px;" <?= $isAdmin ? '' : 'aria-disabled="true"' ?> aria-label="Salvar chave OpenAI">
            <label for="openai_key">Atualizar chave OpenAI</label>
            <input id="openai_key" name="openai_key" type="password" placeholder="Cole o OPENAI_API_KEY..." <?= $isAdmin ? '' : 'disabled' ?>>
            <p class="small-note">Salva no .env (OPENAI_API_KEY).</p>
            <button type="submit" class="ghost-btn" name="save_openai" value="1" style="justify-self:flex-start;" <?= $isAdmin ? '' : 'disabled' ?>>Salvar chave</button>
          </form>
          <p class="small-note">OpenAI direto usa a API local.</p>
        </div>
      </div>
    </div>
  </div>
</body>
<script>
(function(){
  const tabs = document.querySelectorAll('[data-tab-btn]');
  const panels = document.querySelectorAll('[data-tab-panel]');
  const setTab = (name)=>{
    tabs.forEach(b=>b.classList.toggle('active', b.getAttribute('data-tab-btn')===name));
    panels.forEach(p=>{
      if(p.getAttribute('data-tab-panel')===name) p.removeAttribute('hidden');
      else p.setAttribute('hidden','hidden');
    });
    try { localStorage.setItem('ai_tab', name); } catch(e) {}
  };
  const savedTab = (()=>{ try { return localStorage.getItem('ai_tab'); } catch(e){ return null; }})();
  if (savedTab && document.querySelector(`[data-tab-btn="${savedTab}"]`)) setTab(savedTab);
  tabs.forEach(btn=>btn.addEventListener('click',()=>setTab(btn.getAttribute('data-tab-btn'))));

  const pollStatus = document.querySelector('[data-poll-status]');
  const replyContainer = document.querySelector('[data-agent-reply-container]');
  const refreshBtn = document.querySelector('[data-refresh-latest]');
  const toastStack = document.querySelector('[data-toast-stack]');
  const promptEl = document.getElementById('prompt');
  const contextEl = document.getElementById('context');
  const presetBtns = document.querySelectorAll('[data-preset]');
  const searchBtn = document.querySelector('[data-search-context]');
  const searchTermInput = document.getElementById('context-search-term');
  const ragBtn = document.querySelector('[data-rag-context]');
  const ragIndexBtn = document.querySelector('[data-rag-index]');

  const shouldPoll = <?= $startPoll ? 'true' : 'false' ?>;
  const pollIssue = <?= json_encode($pollIssue, JSON_UNESCAPED_SLASHES) ?>;
  const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;

  const setStatus = (text) => {
    if (!pollStatus) return; if (!text) { pollStatus.textContent=''; pollStatus.hidden=true; return; }
    pollStatus.textContent=text; pollStatus.hidden=false;
  };
  const escapeHtml = (str) => (str || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[ch]||ch));
  const renderReply = (text) => {
    if (!replyContainer) return;
    replyContainer.innerHTML = `<div class="panel" style="margin:12px 0;"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center;"><h2>Ultima resposta</h2><span class="small-note">Atualizada agora</span></div><div style="white-space:pre-wrap; margin-top:6px;">${escapeHtml(text)}</div></div>`;
    replyContainer.removeAttribute('hidden');
  };
  const showToast = (msg, type='ok') => {
    if (!toastStack) return; const div = document.createElement('div');
    div.className = `toast ${type==='err'?'err':'ok'}`; div.textContent = msg; toastStack.appendChild(div); setTimeout(()=>div.remove(),3200);
  };
  const fetchLatestOnce = () => {
    if (!pollIssue) return; setStatus('Buscando ultima resposta...');
    fetch(`?action=poll&issue=${encodeURIComponent(pollIssue)}`, { headers:{'Accept':'application/json'} })
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{
        if (!data) { setStatus('Erro ao buscar.'); showToast('Erro ao buscar','err'); return; }
        if (data.status==='ready' && data.reply){ renderReply(data.reply); setStatus('Ultima resposta carregada.'); showToast('Resposta atualizada'); }
        else if (data.status==='waiting'){ setStatus('Nenhuma resposta do agente ainda.'); }
        else { setStatus('Erro ao buscar resposta.'); showToast('Erro ao buscar','err'); }
      })
      .catch(()=>{ setStatus('Erro ao buscar resposta.'); showToast('Erro ao buscar','err'); });
  };
  const startPolling = () => {
    if (!pollIssue) return; let attempts=0; const max=30; const delay=2500;
    const tick=()=>{ if(attempts>=max){ setStatus('Nenhuma resposta do agente ainda.'); return; }
      attempts++;
      fetch(`?action=poll&issue=${encodeURIComponent(pollIssue)}`, { headers:{'Accept':'application/json'} })
        .then(r=>r.ok?r.json():{status:'error'})
        .then(data=>{
          if(!data){ setStatus('Erro ao buscar resposta.'); return; }
          if (data.status==='ready' && data.reply){ renderReply(data.reply); setStatus('Resposta recebida.'); }
          else if (data.status==='waiting'){ setStatus('Aguardando resposta do agente...'); setTimeout(tick, delay); }
          else { setStatus('Erro ao buscar resposta.'); }
        })
        .catch(()=>setStatus('Erro ao buscar resposta.'));
    };
    setStatus('Aguardando resposta do agente...'); tick();
  };
  if (shouldPoll) startPolling();
  refreshBtn?.addEventListener('click', fetchLatestOnce);

  const LS_PROMPT='ai_chat_prompt', LS_CONTEXT='ai_chat_context';
  if (promptEl && localStorage.getItem(LS_PROMPT)) promptEl.value = localStorage.getItem(LS_PROMPT);
  if (contextEl && localStorage.getItem(LS_CONTEXT)) contextEl.value = localStorage.getItem(LS_CONTEXT);
  promptEl?.addEventListener('input', ()=>localStorage.setItem(LS_PROMPT,promptEl.value));
  contextEl?.addEventListener('input', ()=>localStorage.setItem(LS_CONTEXT,contextEl.value));
  presetBtns.forEach(btn=>btn.addEventListener('click',()=>{ if(!promptEl) return; promptEl.value = btn.getAttribute('data-preset'); localStorage.setItem(LS_PROMPT,promptEl.value); promptEl.focus(); }));
  if (!shouldPoll) fetchLatestOnce();

  const runSearch = () => {
    if (!searchTermInput || !contextEl) return;
    const term = searchTermInput.value.trim();
    if (term.length < 2) { showToast('Termo muito curto','err'); return; }
    showToast('Buscando contexto...');
    fetch(`?action=search&term=${encodeURIComponent(term)}`, { headers:{'Accept':'application/json'} })
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{
        if (!data || data.status!=='ok') { showToast('Falha na busca','err'); return; }
        if (!data.results || !data.results.length) { showToast('Nenhum trecho encontrado'); return; }
        const parts = [];
        data.results.forEach(item=>{
          (item.matches||[]).forEach(m=>{
            parts.push(`// ${item.file} #${m.line}\n${m.snippet}`);
          });
        });
        const block = parts.join('\n\n');
        if (block) {
          contextEl.value = (contextEl.value ? contextEl.value + "\n\n" : '') + block;
          localStorage.setItem(LS_CONTEXT, contextEl.value);
          showToast('Contexto colado no prompt');
        }
      })
      .catch(()=>showToast('Erro na busca','err'));
  };
  searchBtn?.addEventListener('click', runSearch);

  const runRagSearch = () => {
    if (!searchTermInput || !contextEl) return;
    const term = searchTermInput.value.trim();
    if (term.length < 2) { showToast('Termo muito curto','err'); return; }
    showToast('Buscando RAG...');
    fetch(`?action=rag-search&term=${encodeURIComponent(term)}`, { headers:{'Accept':'application/json'} })
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{
        if (!data || data.status!=='ok') { showToast(data?.message || 'Falha no RAG','err'); return; }
        if (!data.results || !data.results.length) { showToast('RAG sem resultados'); return; }
        const parts = [];
        data.results.forEach(item=>{
          parts.push(`// ${item.file} (sim=${item.score})\n${item.snippet}`);
        });
        const block = parts.join('\n\n');
        if (block) {
          contextEl.value = (contextEl.value ? contextEl.value + "\n\n" : '') + block;
          localStorage.setItem(LS_CONTEXT, contextEl.value);
          showToast('RAG colado no contexto');
        }
      })
      .catch(()=>showToast('Erro no RAG','err'));
  };
  ragBtn?.addEventListener('click', runRagSearch);

  const runRagIndex = () => {
    showToast('Indexando RAG (pode levar tempo)...');
    fetch(`?action=rag-index`, { headers:{'Accept':'application/json'} })
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{
        if (!data || data.status!=='ok') { showToast(data?.message || 'Falha ao indexar','err'); return; }
        showToast(`Indice criado (${data.indexed||0} arquivos)`);
      })
      .catch(()=>showToast('Erro ao indexar','err'));
  };
  ragIndexBtn?.addEventListener('click', runRagIndex);

  // FS helper
  const fsPathInput=document.getElementById('fs-path');
  const fsListContainer=document.querySelector('[data-fs-listing]');
  const fsCurrent=document.getElementById('fs-current');
  const fsContent=document.getElementById('fs-content');
  const fsListBtn=document.querySelector('[data-fs-list]');
  const fsUpBtn=document.querySelector('[data-fs-up]');
  const fsSaveBtn=document.querySelector('[data-fs-save]');
  const fsReloadBtn=document.querySelector('[data-fs-reload]');
  let fsPath='.';
  const joinPath=(base,name)=>{ if(!base||base==='.') return name; return `${base.replace(/\\/g,'/').replace(/\/+$/,'')}/${name}`; };
  const renderFsEntries=(entries)=>{ if(!fsListContainer) return; if(!entries||!entries.length){ fsListContainer.innerHTML='<span class="small-note">Vazio.</span>'; return; }
    fsListContainer.innerHTML = entries.map(e=>{ const icon = e.type==='dir'?'[DIR]':'[FILE]'; return `<button class="fs-entry" data-name="${e.name}" data-type="${e.type}">${icon} ${escapeHtml(e.name)}</button>`; }).join(''); };
  const listDir=(path)=>{ if(!fsListContainer) return;
    fetch(`?action=fs-list&path=${encodeURIComponent(path)}`,{headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{ if(data.status!=='ok'){ showToast('Erro ao listar diretorio','err'); return; } fsPath=path; fsPathInput.value=path; renderFsEntries(data.entries||[]); })
      .catch(()=>showToast('Erro ao listar diretorio','err'));
  };
  const readFile=(path)=>{
    fetch(`?action=fs-read&path=${encodeURIComponent(path)}`,{headers:{'Accept':'application/json'}})
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{ if(data.status!=='ok'){ showToast('Erro ao ler arquivo','err'); return; } fsCurrent.value=path; fsContent.value=data.content||''; showToast('Arquivo carregado'); })
      .catch(()=>showToast('Erro ao ler arquivo','err'));
  };
  const saveFile=()=>{
    const path=fsCurrent.value.trim(); if(!path){ showToast('Selecione um arquivo primeiro','err'); return; }
    fetch(`?action=fs-write&path=${encodeURIComponent(path)}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({content:fsContent.value})})
      .then(r=>r.ok?r.json():{status:'error'})
      .then(data=>{ if(data.status!=='ok'){ showToast('Erro ao salvar arquivo','err'); return; } showToast('Arquivo salvo'); })
      .catch(()=>showToast('Erro ao salvar arquivo','err'));
  };
  fsListBtn?.addEventListener('click',()=>{ const path=fsPathInput.value.trim()||'.'; listDir(path); });
  fsUpBtn?.addEventListener('click',()=>{ const parts=(fsPath||'.').replace(/\\/g,'/').split('/').filter(Boolean); parts.pop(); const next=parts.length?parts.join('/') : '.'; listDir(next); });
  fsListContainer?.addEventListener('click',(ev)=>{ const btn=ev.target.closest('.fs-entry'); if(!btn) return; const name=btn.getAttribute('data-name'); const type=btn.getAttribute('data-type'); const target=joinPath(fsPath,name); if(type==='dir') listDir(target); else readFile(target); });
  fsSaveBtn?.addEventListener('click',()=>{ if(!isAdmin){ showToast('Somente admin pode salvar','err'); return;} saveFile(); });
  fsReloadBtn?.addEventListener('click',()=>{ const path=fsCurrent.value.trim(); if(path) readFile(path); else showToast('Selecione um arquivo primeiro','err'); });
  listDir(fsPath);
})();
</script>
</html>
