<?php
/**
 * Application bootstrap: autoloading, configuration, session and error handling.
 */

declare(strict_types=1);

require __DIR__ . '/Core/Autoloader.php';

App\Core\Autoloader::register(dirname(__DIR__));
require __DIR__ . '/Support/helpers.php';

// ---------------------------------------------------------------------
// Error / exception handling
// ---------------------------------------------------------------------
$debug = (bool) (getenv('APP_DEBUG') ?: false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    App\Core\Logger::error($e->getMessage(), [
        'exception' => get_class($e),
        'file'      => $e->getFile() . ':' . $e->getLine(),
        'trace'     => $debug ? $e->getTraceAsString() : null,
    ]);

    if ($debug) {
        echo '<h1>500 – Application Error</h1><pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        exit;
    }

    $view = App\Core\View::getInstance();
    echo $view->render('errors/500', ['message' => $e->getMessage()]);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        App\Core\Logger::critical('Fatal error: ' . $error['message'], [
            'file' => $error['file'] . ':' . $error['line'],
        ]);
    }
});

// ---------------------------------------------------------------------
// Locale + timezone
// ---------------------------------------------------------------------
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');
setlocale(LC_ALL, (getenv('APP_LOCALE') ?: 'en_US.UTF-8'));
