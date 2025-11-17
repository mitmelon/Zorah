<?php
/**
 * Manomite framework - Entry Point
 * Industrial and enterprise designed framework for PHP 8.4+
 * 
 * @version    4.0.0
 * @author     Manomite Limited
 *
 * @copyright  2025 Manomite Limited
 */

use Manomite\Exception\ManomiteException as ex;
use Manomite\{
    Controller\Auth,
    Controller\Views,
    Utility\Notification,
};
use Predis\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteCollectorProxy;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', false);
ini_set('log_errors', true);
ini_set('error_log', SYSTEM_DIR . '/log/entry.log');
ini_set('log_errors_max_len', 1024);

$_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], (strlen('/zorah')));

$app             = AppFactory::create();
$responseFactory = new ResponseFactory();
$view = new Views();

// Rate Limiting Middleware
$redisClient = new Client([
    'scheme' => 'tcp',
    'host'   => 'localhost',
    'port'   => 6379,
]);

function pretty_print($array)
{
    echo "<pre>";
    print_r($array);
    echo "</pre>";
}


try {

    $app->get('/sitemap', function (Request $request, Response $response) use($view) {

        $html = $view->sitemap();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'application/xml');
    });

    $app->get('/', function (Request $request, Response $response) use($view) {
        $html = $view->index();
        $response->getBody()->write($html);
        return $response;
    });

    $app->get('/index', function (Request $request, Response $response) use($view) {
         $html = $view->index();
        $response->getBody()->write($html);
        return $response;
    });

    $app->get('/register', function (Request $request, Response $response) use($view) {
        $html = $view->register();
        $response->getBody()->write($html);
        return $response;
    });

    $app->get('/email/verify/{id}', function (Request $request, Response $response, $args) use($view) {
        $html = $view->verifyEmail($args);
        $response->getBody()->write($html);
        return $response;
    });

    //Auntenticated area
    $app->group('/home', function (RouteCollectorProxy $group) use ($app, $view) {
        //Protected resource
        $group->get('', function (Request $request, Response $response) use($view) {
            $html = $view->home();
            $response->getBody()->write($html);
            return $response;
        });

        $group->get('/', function (Request $request, Response $response) use($view) {
            $html = $view->home();
            $response->getBody()->write($html);
            return $response;
        });
    });

   

    $app->run();

} catch (\Throwable $e) {
    echo $e->getMessage();
}
