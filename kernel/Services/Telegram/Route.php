<?php
namespace Manomite\SDK\Telegram;

require_once __DIR__."/../../../autoload.php";
class Route
{
    private $routes = [];
    private $registeredRoute = array('/login', '/support', '/Login', '/startExam');
    
    public function route($action, $callback)
    {
        if ($this->validateRoute($action)) {
            global $routes;
            $action = trim($action, '/');
            $routes[$action] = $callback;
        }
    }

    public function dispatch($action)
    {
        if ($this->validateRoute($action)) {
            global $routes;
            $action = trim($action, '/');
            $callback = $routes[$action];

            echo call_user_func($callback);
        }
    }

    private function validateRoute($action)
    {
        foreach ($this->registeredRoute as $r) {
            if ($r === $action) {
                return true;
            }
        }
        return false;
    }
}