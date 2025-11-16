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
    $app->get('/test', function (Request $request, Response $response) {

        //$factory = new Manomite\Services\OpenAI\ClientFactory('gpt-4o', '2024-02-15-preview', new Manomite\Mouth\Migration());
        //$agent = new Manomite\Services\OpenAI\Agent($factory);

        //$result = $agent->execute('Can you generate a logo or enhance a logo like changing the colors');

        //$brain = new Manomite\Engine\Brain\TextBrain('AIzaSyCE3xr2OZ2BLcW4Xjs2stgmXUhbEEoV1bQ');
        //$article = "Yo, what’s good, everyone? 😎 I’m George, a tech enthusiast who’s been grinding in my coding lab to build something I’m super excited to share. If you’re a blogger struggling to craft posts that rank on Google, a content creator chasing viral vibes on social media, or a business owner needing ads and content that scream “Buy Now,” I’ve got something special for you. Say hello to TextBrain, my AI-powered tool that takes your raw ideas and turns them into fire content - SEO-friendly, viral, or sales-ready-in seconds! 🚀";
        //$profile = $brain->generate($article, 20, 'real estate', 0.7);

        //$response->getBody()->write('done');
        //return $response;
        //502885526237367_122143424414628021

        //$imagePostId = $fb->uploadPhoto($imagePath, true, $imageParams);
  

        //('Hello, Am testing our new page!', ['link' => 'https://estatewake.com'])
        //$response = $fb->getPostDetails('502885526237367_122143424414628021');

        //$note = new Manomite\Nerves\Notification;
        //$multiPhotoResponse = $note->TelegramHook('https://estatewake.com/telegram');

         //$rate = new Manomite\Engine\Money\Helper;
         //$rate = $rate->exchange('NGN');

          $walletManager = new Manomite\Zorah\MoonbeamWalletManager(CONFIG->get('zorah_environment'), 'moonbeam');
          //$wallet = $walletManager->createHDWallet();
          //file_put_contents(__DIR__.'/wallet.json', json_encode($wallet, JSON_PRETTY_PRINT));
          exit(pretty_print($walletManager->getERC20Balance('0xD1633F7Fb3d716643125d6415d4177bC36b7186b', '0x454b33e74d96f552a670f3371bf817dba5a0664d')));

        //$pdfTemp = file_get_contents(__DIR__.'/file.html');

        //$conPdf = (new Manomite\Route\Route())->textRender($pdfTemp, [
        //    
        //]);

        ////$pdf = new Manomite\Engine\Pdf();
        //$pdf->generate_pdf($conPdf, 'property_document', __DIR__.'/my_file');


        //xit(pretty_print($multiPhotoResponse));
    });

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
