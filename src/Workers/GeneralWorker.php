<?php

// Manomite framework dependencies
use Manomite\{
    Exception\ManomiteException as ex,
    Engine\Queue\Redis\Redis,
    Controller\SimpleRoute as ServiceRoute,
    Engine\Security\PostFilter,
    Engine\Security\Encryption as Secret,
    Engine\DateHelper,
    Engine\File,
    Engine\Email,
    Route\Route,
    Controller\Reflect,
    Engine\Money\Helper as Money
};

// Set PHP execution limits to allow for long-running processes,
// which is typical for a message queue worker.
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

// Include the application's autoloader.
include_once __DIR__ . '/../../autoload.php';

// Initialize the Redis queue consumer for the 'GeneralWorker'.
$consumer = new Redis(null, 'GeneralWorker');
// Initialize the service router.
$serviceRoute = new ServiceRoute();
// Define the allowed routes for this worker.
$allowedRoutes = [
    '/notification',
];
$serviceRoute->registeredRoute = $allowedRoutes;

/**
 * The main worker service function.
 * This function handles the logic for a single message processing cycle.
 * It's designed to be run in a loop to continuously consume messages.
 *
 * @param callable $service The function itself, for recursive calls in the original loop.
 */
$service = function () use ($consumer, &$service, $serviceRoute) {
    /////////////////////////////////////////////////////////////WORKER KERNEL//////////////////////////////////////////////////////////
    // DO NOT EDIT OR TOUCH EXCEPT YOU ARE GIVEN PERMISSION TO DO SO.
    
    // Receive a message from the Redis queue.
    $receive = $consumer->receiveMessage();
    $workerID = 'GeneralWorker';
    $dir = SYSTEM_DIR . '/log/process';
    $processFile = $dir.'/' . $workerID . '.pid';
    
    // Create a PID file to indicate the worker is running.
    // The use of `dump` here is a custom function from the File class.
    (new File())->dump($processFile, 'an empty file');

    // Check if a message was successfully received.
    if (!empty($receive['message'])) {
        // Decode the JSON payload from the message.
        $coded = json_decode($receive['message'], true);
        $route = str_replace('/', '', $coded['service']['route']);

        // --- ROUTE: /review ---
        // This route handles the review process for new listings.
        $serviceRoute->route('/notification', function () use ($coded, $consumer, $receive) {
            $workerId = $coded['workerID'];
            $payload = $coded['service'];

            // Decompress and decrypt the message payload.
            $pipe = gzuncompress(base64_decode($payload['pipe']));
            $result = (new Secret($pipe, 'transit_key'))->decrypt();

            if ($result !== false) {
                try {
                    $result = json_decode($result, true);

                    $mail = MAIL_DRIVER;
                    ( new Email($result['subject'], array($result['email']), $result['message'], '', CONFIG->get('smtp_reply_email'), $result['file']) )->$mail();

                    $consumer->acknowledge($receive);
                } catch(\Throwable $e) {
                    (new ex($workerId, 3, $e))->return();
                }
            }
        });

      
        
        // Dispatch the message to the correct route based on its payload.
        $serviceRoute->dispatch('/'.$route);
    }
};

// Call the service function. In a production environment, this should
// be in a loop to continuously process messages.
$service();

while (true) {
    $service();
    usleep(10000);
}