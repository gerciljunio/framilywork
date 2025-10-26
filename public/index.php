<?php

declare(strict_types=1);

use App\Http\Middleware\CorsMiddleware;
use App\Http\Router; // Seu Router PSR-15 (deve implementar RequestHandlerInterface)
use App\Support\Env;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter; // Emite a Response para a SAPI atual (FPM, CLI server, Franken)
use Nyholm\Psr7\Factory\Psr17Factory; // Fábrica PSR-17 para Request/Response/Stream/UploadedFile/Uri
use Nyholm\Psr7Server\ServerRequestCreator; // Constrói ServerRequest a partir de superglobais
use Relay\Relay; // Executor de fila de middlewares PSR-15

require __DIR__ . '/../vendor/autoload.php';

/**
 * Suporte ao php -S (router embutido):
 * - Se a URL pedir um arquivo físico existente em /public, retorna false para o php -S servi-lo
 * - Normaliza SCRIPT_NAME e SCRIPT_FILENAME (alguns routers/creators dependem disso)
 */
if (PHP_SAPI === 'cli-server') { // Executando via "php -S"?
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';        // Rota solicitada (ex.: /health)
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';  // Extrai somente o path (sem query string)
    $path = rawurldecode($path);                   // Decodifica chars na URL (ex.: %20) para checar arquivo real

    $file = __DIR__ . $path;                       // Mapeia o path ao arquivo físico dentro de /public
    if ($path !== '/' && is_file($file)) {
        return false;
    }

    // Normalizações úteis para ambientes que dependem dessas variáveis
    $_SERVER['SCRIPT_NAME']     = '/index.php';   // Garante nome base
    $_SERVER['SCRIPT_FILENAME'] = __FILE__;       // Caminho absoluto do script atual

    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/';
    }
}

/** Bootstrap 1x */
Env::load(__DIR__ . '/../.env');

$psr17   = new Psr17Factory(); // Única factory para todos os objetos PSR-17 (Request/Response/Stream/Uri/UploadedFile)
$creator = new ServerRequestCreator(
    $psr17, // ServerRequestFactoryInterface
    $psr17, // UriFactoryInterface
    $psr17, // UploadedFileFactoryInterface
    $psr17  // StreamFactoryInterface
); // Responsável por montar a ServerRequest da request atual a partir de $_SERVER, $_GET, $_POST, etc.

$router = new Router(); // Instancia router PSR-15
(require __DIR__ . '/../routes/api.php')($router); // Carrega e registra as rotas (injeta no $router)

// Middlewares globais stateless
$globalQueue = [
    new CorsMiddleware(), // CORS antes do roteamento
    $router,              // Router atua como RequestHandler final
];

/** Processa UMA request (FPM/cli-server) e cada iteração do worker */
$handleOne = static function () use ($creator, $globalQueue): void {
    try {
        // Evita herdar buffers de requests anteriores
        while (ob_get_level() > 0) {
            @ob_end_clean(); // Limpa todos os níveis de output buffer sem emitir conteúdo
        }

        $request  = $creator->fromGlobals(); // Constrói a ServerRequest da requisição atual
        $queue    = $globalQueue; // Clona a fila se for modificar por request
        $response = (new Relay($queue))->handle($request); // Executa middlewares e obtém a Response final

        (new SapiEmitter())->emit($response);
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Internal Server Error']);
        // error_log((string) $e);
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close(); // Garante o fechamento da sessão para não travar concorrência
        }
        gc_collect_cycles(); // Evita vazamentos de memória (especialmente em worker)
        // Não limpe buffers depois do emit, para não apagar o body já enviado
    }
};

/** Dual-mode: FrankenPHP (worker) OU Clássico (FPM/php -S) */
if (function_exists('frankenphp_handle_request')) { // Se a função existe, estamos no ambiente FrankenPHP
    frankenphp_handle_request(function () use ($handleOne): void {
        // Antes de cada request no loop do worker, garanta ambiente limpo de buffers
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $handleOne(); // Processa uma request dentro do loop (Worker Mode)
    });
} else {
    // FPM / php -S
    $handleOne(); // Modo clássico: processa uma única request por ciclo
}
