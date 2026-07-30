<?php
/**
 * Bootstrap MANUAL de la conexión OAuth con admin-mcp.tiendanube.com.
 *
 * Por qué existe: /authorize de admin-mcp.tiendanube.com sólo acepta
 * redirect_uri tipo loopback (localhost/127.0.0.1) — rechaza cualquier
 * dominio HTTPS de terceros con 400 "does not match allowed patterns",
 * aunque /register lo haya aceptado sin quejarse al registrar el cliente.
 * Confirmado empíricamente el 29/07/2026 (ver memoria de proyecto
 * "MCP Tiendanube: OAuth standalone" y specs/019-tiendanube-conexion-mcp/).
 *
 * Esto significa que el botón "Conectar con Tiendanube" del CRM
 * (TiendanubeOAuthController, con redirect_uri en contagramdemo.devstudioweb.com)
 * NUNCA va a funcionar contra la cuenta real — no es un bug, es una
 * restricción de la plataforma. La única forma de obtener/renovar un
 * access_token real es correr este script LOCAL (loopback), aprobar en el
 * navegador, y después pegar el resultado a mano en tn_configuracion de
 * producción (ver README abajo).
 *
 * Uso:
 *   php scripts/tiendanube_oauth_bootstrap.php
 *   -> imprime una URL, abrila y aprobá con la cuenta real de Tiendanube
 *   -> el resultado queda en scripts/tn_oauth_bootstrap_resultado.json
 *      (gitignored — contiene client_secret/access_token en claro)
 *
 * Para escribirlo en producción: generar un script de tinker a partir de
 * ese JSON (client_id, client_secret, access_token, scope, productos_total)
 * y subirlo con `python deploy.py --tinker <script>` — ver
 * .claude/skills/deploy/SKILL.md. No commitear nunca el JSON de resultado.
 */

$puerto = 8934;
$redirectUri = "http://127.0.0.1:{$puerto}/callback";
$resultadoPath = __DIR__ . '/tn_oauth_bootstrap_resultado.json';

function post_json(string $url, array $body, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode($respuesta, true)];
}

function post_form(string $url, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $respuesta = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, json_decode($respuesta, true)];
}

echo "1) Registrando cliente OAuth (redirect_uri loopback)...\n";
[$codigo, $registro] = post_json('https://admin-mcp.tiendanube.com/register', [
    'redirect_uris' => [$redirectUri],
    'client_name' => 'Contagram CRM (bootstrap local)',
    'grant_types' => ['authorization_code', 'refresh_token'],
    'response_types' => ['code'],
    'token_endpoint_auth_method' => 'client_secret_post',
]);
if ($codigo !== 201 || empty($registro['client_id'])) {
    fwrite(STDERR, "Registro fallo: HTTP $codigo " . json_encode($registro) . "\n");
    exit(1);
}
$clientId = $registro['client_id'];
$clientSecret = $registro['client_secret'];
echo "   OK client_id=$clientId\n";

$codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(16));

$scopes = 'read_products write_products read_orders write_orders read_customers write_customers read_content write_content read_coupons write_coupons write_scripts write_shipping';

$authorizeUrl = 'https://admin-mcp.tiendanube.com/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scopes,
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

echo "2) URL de autorizacion (abrila y aproba en el navegador):\n";
echo "AUTHORIZE_URL: $authorizeUrl\n";

echo "3) Esperando el callback en $redirectUri (timeout 240s, ignora requests que no sean /callback)...\n";
$socket = stream_socket_server("tcp://127.0.0.1:{$puerto}", $errno, $errstr);
if (!$socket) {
    fwrite(STDERR, "No se pudo abrir el listener local: $errstr\n");
    exit(1);
}

$deadline = time() + 240;
$query = null;

while (time() < $deadline) {
    $restante = $deadline - time();
    $conn = @stream_socket_accept($socket, min($restante, 10));
    if (!$conn) {
        continue; // timeout parcial del accept, seguir esperando hasta el deadline real
    }

    $request = fread($conn, 8192);
    preg_match('#^(GET|POST) (\S+) #', $request, $m);
    $requestPath = $m[2] ?? '/';
    $path = parse_url($requestPath, PHP_URL_PATH);

    if ($path !== '/callback') {
        // Ruido (favicon.ico, prefetch del navegador, etc.) — responder 404 y seguir esperando el real.
        fwrite($conn, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($conn);
        continue;
    }

    $query = [];
    parse_str((string) parse_url($requestPath, PHP_URL_QUERY), $query);

    $body = isset($query['error'])
        ? "<h1>Error: " . htmlspecialchars($query['error']) . "</h1><p>Podes cerrar esta pestana.</p>"
        : "<h1>Listo</h1><p>Conexion capturada, volve a la terminal. Podes cerrar esta pestana.</p>";
    $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    fwrite($conn, $response);
    fclose($conn);
    break;
}
fclose($socket);

if ($query === null) {
    fwrite(STDERR, "Timeout esperando el callback (nadie aprobo a tiempo, o no llego ningun /callback).\n");
    exit(1);
}

if (isset($query['error'])) {
    fwrite(STDERR, "Tiendanube devolvio error: " . ($query['error_description'] ?? $query['error']) . "\n");
    exit(1);
}
if (($query['state'] ?? null) !== $state) {
    fwrite(STDERR, "State no coincide, aborto.\n");
    exit(1);
}
if (empty($query['code'])) {
    fwrite(STDERR, "No llego 'code' en el callback.\n");
    exit(1);
}

echo "4) Code recibido, canjeando por token...\n";
[$codigoToken, $token] = post_form('https://admin-mcp.tiendanube.com/token', [
    'grant_type' => 'authorization_code',
    'code' => $query['code'],
    'redirect_uri' => $redirectUri,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code_verifier' => $codeVerifier,
]);

if ($codigoToken !== 200 || empty($token['access_token'])) {
    fwrite(STDERR, "Canje de token fallo: HTTP $codigoToken " . json_encode($token) . "\n");
    exit(1);
}

echo "5) Token obtenido. Verificando con list_products...\n";
$ch = curl_init('https://admin-mcp.tiendanube.com/');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'list_products', 'arguments' => ['page' => 1, 'page_size' => 1]],
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $token['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$verifBody = curl_exec($ch);
$verifCodigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$productosTotal = null;
foreach (preg_split('/\r?\n/', (string) $verifBody) as $linea) {
    if (str_starts_with(trim($linea), 'data:')) {
        $decoded = json_decode(trim(substr(trim($linea), 5)), true);
        $productosTotal = $decoded['result']['structuredContent']['pagination']['total_elements'] ?? null;
        break;
    }
}
echo "   HTTP $verifCodigo, productos_total=" . ($productosTotal ?? 'N/D') . "\n";

file_put_contents($resultadoPath, json_encode([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'access_token' => $token['access_token'],
    'scope' => $token['scope'] ?? $scopes,
    'expires_in' => $token['expires_in'] ?? null,
    'productos_total' => $productosTotal,
    'verificacion_http' => $verifCodigo,
], JSON_PRETTY_PRINT));

echo "6) OK. Resultado guardado en: $resultadoPath\n";
