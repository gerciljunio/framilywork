<?php

declare(strict_types=1);

/**
 * Framilywork — Swoole/OpenSwoole runner para API PSR-7/15
 * - Monta ServerRequest a partir do Swoole\Request
 * - Reusa seu pipeline: Env, Router, CorsMiddleware, Relay
 * - Emite headers/body direto no Swoole\Response
 * - Output buffering consistente com marcador de nível
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

use App\Http\Middleware\CorsMiddleware;
use App\Http\Router;
use App\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\UploadedFileInterface;
use Relay\Relay;

/* Detecta engine */
$httpNs = null;
if (extension_loaded('openswoole')) {
    $httpNs = 'OpenSwoole\\Http';
} elseif (extension_loaded('swoole')) {
    $httpNs = 'Swoole\\Http';
} else {
    fwrite(STDERR, '[' . (getenv('APP_NAME') ?: 'framilywork') . "] Swoole/OpenSwoole not found.\n");
    exit(1);
}

/* Boot básico da app */
Env::load(__DIR__ . '/.env');

$host = getenv('FW_HOST') ?: '0.0.0.0';
$port = (int) (getenv('FW_PORT') ?: 8080);

$serverClass = $httpNs . '\\Server';
$server = new $serverClass($host, $port, defined('SWOOLE_BASE') ? SWOOLE_BASE : 1);

$cpu = function_exists('swoole_cpu_num')
    ? swoole_cpu_num()
    : max(1, (int) (trim((string) @shell_exec('nproc 2>/dev/null')) ?: 1));

$server->set([
    'worker_num'         => $cpu,
    'enable_coroutine'   => true,
    'reload_async'       => true,
    'http_compression'   => true,  // deixe o Swoole decidir TE/CL
    'max_request'        => 10000,
    'package_max_length' => 32 * 1024 * 1024,
]);

$server->on('start', function () use ($host, $port, $httpNs) {
    error_log('[' . (getenv('APP_NAME') ?: 'framilywork') . "] API in http://{$host}:{$port} using {$httpNs}");
});

/**
 * Converte árvore $_FILES-like do Swoole para UploadedFileInterface[]
 */
$mapFiles = function (array $files, Psr17Factory $psr17): array {
    $normalize = function ($spec) use (&$normalize, $psr17) {
        if (is_array($spec) && isset($spec['tmp_name'])) {
            $stream = $psr17->createStreamFromFile($spec['tmp_name'], 'r');
            return $psr17->createUploadedFile(
                $stream,
                (int) ($spec['size'] ?? 0),
                (int) ($spec['error'] ?? UPLOAD_ERR_OK),
                (string) ($spec['name'] ?? ''),
                (string) ($spec['type'] ?? null)
            );
        }
        if (is_array($spec)) {
            $result = [];
            foreach ($spec as $key => $value) {
                $result[$key] = $normalize($value);
            }
            return $result;
        }
        return $spec;
    };
    $out = [];
    foreach ($files as $key => $spec) {
        $out[$key] = $normalize($spec);
    }
    return $out;
};

$server->on('request', function ($req, $res) use ($mapFiles) {
    // Marcador de nível do buffer e abertura de um novo nível
    $obLevel = ob_get_level();
    ob_start();

    try {
        $psr17   = new Psr17Factory();

        $server  = $req->server ?? [];
        $headers = $req->header ?? [];
        $method  = strtoupper($server['request_method'] ?? 'GET');
        $path    = $server['request_uri'] ?? '/';
        $query   = $server['query_string'] ?? '';

        // Proto/host/porta reais considerando proxies
        $proto = $headers['x-forwarded-proto']
            ?? (($server['https'] ?? null) ? 'https' : 'http');

        $hostHeader = $headers['x-forwarded-host']
            ?? ($headers['host'] ?? 'localhost');

        $portHeader = $headers['x-forwarded-port'] ?? null;
        if ($portHeader && !str_contains($hostHeader, ':')) {
            $hostHeader .= ':' . $portHeader;
        }

        $uriStr = sprintf(
            '%s://%s%s%s',
            $proto,
            $hostHeader,
            $path,
            $query ? ('?' . $query) : ''
        );

        // Server params estilo $_SERVER
        $serverParams = array_change_key_case($server, CASE_UPPER);
        $serverParams['REMOTE_ADDR'] = $headers['x-forwarded-for'] ?? ($server['remote_addr'] ?? '127.0.0.1');

        $request = $psr17->createServerRequest($method, $uriStr, $serverParams);

        // Headers
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        // Cookies / Query / Parsed body
        $cookies = $req->cookie ?? [];
        $get     = $req->get ?? [];
        $post    = $req->post ?? [];

        $request = $request
            ->withCookieParams($cookies)
            ->withQueryParams($get);

        // Body stream
        $raw  = method_exists($req, 'rawContent') ? (string) $req->rawContent() : '';
        $body = $psr17->createStream($raw);
        $request = $request->withBody($body);

        // Parsed body (JSON > form)
        $contentType = $headers['content-type'] ?? '';
        if ($raw !== '' && stripos($contentType, 'application/json') !== false) {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request = $request->withParsedBody($json);
            }
        } elseif (!empty($post)) {
            $request = $request->withParsedBody($post);
        }

        // Uploaded files
        $files = $req->files ?? [];
        if (!empty($files)) {
            /** @var UploadedFileInterface[] $uploaded */
            $uploaded = $mapFiles($files, $psr17);
            $request = $request->withUploadedFiles($uploaded);
        }

        // Pipeline PSR-15
        $router = new Router();
        (require __DIR__ . '/routes/api.php')($router);

        $queue = [
            new CorsMiddleware(),
            $router,
        ];

        $relay    = new Relay($queue);
        $response = $relay->handle($request);

        // Status
        $res->status($response->getStatusCode());

        // Headers da resposta PSR → Swoole
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $v) {
                if (strcasecmp($name, 'Content-Length') === 0) {
                    continue;
                }
                if (strcasecmp($name, 'Transfer-Encoding') === 0) {
                    continue;
                }
                $res->header($name, $v);
            }
        }

        // Corpo
        $psrBody = $response->getBody();

        $noBody = $request->getMethod() === 'HEAD'
               || in_array($response->getStatusCode(), [204, 304], true);

        // Captura qualquer saída acidental e limpa até o nível original
        $spurious = ob_get_contents();
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }

        if ($noBody) {
            return $res->end('');
        }

        if ($psrBody->isSeekable()) {
            $psrBody->rewind();
        }

        // Se houve saída indevida, concatena ao corpo final
        if ($spurious !== '') {
            return $res->end($psrBody->getContents() . $spurious);
        }

        return $res->end($psrBody->getContents());

    } catch (\Throwable $e) {
        // Limpa buffers até o nível original
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }

        $res->status(500);
        $res->header('Content-Type', 'application/json; charset=utf-8');
        return $res->end(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    } finally {
        // Segurança extra: garante que não ficou buffer aberto
        while (ob_get_level() > $obLevel) {
            ob_end_clean();
        }
        if (function_exists('gc_mem_caches')) {
            gc_mem_caches();
        }
        clearstatcache();
    }
});

/* Start */
$server->start();
