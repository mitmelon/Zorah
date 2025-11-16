<?php
namespace Manomite\Services\Telegram;

use \Longman\TelegramBot\Request;
use \Longman\TelegramBot\Telegram;
use \Longman\TelegramBot\Exception\TelegramException;
use \Longman\TelegramBot\ChatAction;
use \ParagonIE\ConstantTime\Encoding;
use \Manomite\Protect\PostFilter;
use \Manomite\Exception\ManomiteException as ex;
use \Manomite\Protect\Secret;

/**
 *  The Telegram API Client
 *
 *  @link https://www.Manomite.mitnets.com/docs for Programming Tutorials
 *  @author Mitnets Technologies <developer@mitnets.com>
 */
require_once __DIR__."/../../../autoload.php";

class Client
{
    private $telegram;

    public function __construct($bot_api_key, $bot_username)
    {
        $this->telegram = new Telegram($bot_api_key, $bot_username);
    }

    public function telegramMethods()
    {
        return $this->telegram;
    }

    public function setMyWebhook($hook_url)
    {
        try {
            // Set webhook
            $result = $this->telegram->setWebhook($hook_url);
            if ($result->isOk()) {
                return $result->getDescription();
            }
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function deleteMyWebhook()
    {
        try {
            // Set webhook
            $result = $this->telegram->deleteWebhook();
            return $result->getDescription();
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function getSession()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $token = Encoding::hexEncode(random_bytes(64));
        if (isset($_SESSION['telegram_session'])) {
            $token_age = time() - (new PostFilter)->strip($_SESSION['tsession_time']);
            if ($token_age <= 1800) {
                return (new PostFilter)->strip($_SESSION['telegram_session']);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function setSession()
    {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $token = Encoding::hexEncode(random_bytes(64));
        if (isset($_SESSION['telegram_session'])) {
            $token_age = time() - (new PostFilter)->strip($_SESSION['tsession_time']);
            if ($token_age <= 1800) {
                return (new PostFilter)->strip($_SESSION['telegram_session']);
            } else {
                $_SESSION['telegram_session'] = $token;
                $_SESSION['tsession_time'] = time();
                return (new PostFilter)->strip($_SESSION['telegram_session']);
            }
        } else {
            $_SESSION['telegram_session'] = $token;
            $_SESSION['tsession_time'] = time();
            return (new PostFilter)->strip($_SESSION['telegram_session']);
        }
    }

    public function send($chat_id, $text, $reply_to_message_id = '')
    { 
        if($reply_to_message_id !== ''){
            $reply_to_message_id = array('reply_to_message_id' => $reply_to_message_id);
        }
        try {
            // Set webhook
            $result = Request::sendMessage([
                'chat_id' => $chat_id,
                'text'    => $text,
                'text'    => $text,
                'parse_mode' => 'html',
                $reply_to_message_id
            ]);
            return json_decode(json_encode($result), true);
        } catch (\TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function sendPhoto($chat_id, $photo)
    {
        try {
            // Set webhook
            $result = Request::sendPhoto([
                'chat_id' => $chat_id,
                'photo'   => Request::encodeFile($photo),
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function sendDocument($chat_id, $doc)
    {
        try {
            // Set webhook
            $result = Request::sendDocument([
                'chat_id' => $chat_id,
                'document'   => Request::encodeFile($doc),
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function sendChatAction($chat_id)
    {
        try {
            $result = Request::sendChatAction([
                'chat_id' => $chat_id,
                'action'  => ChatAction::TYPING,
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function deleteMessage($chat_id, $message_id)
    {
        try {
            $result = Request::deleteMessage([
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
        ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function ban_member($chat_id, $user_id, $date = null)
    {
        try {
            $result = Request::kickChatMember([
                'chat_id' => $chat_id,
                'user_id' => $user_id,
                'until_date' => $date
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function unban_member($chat_id, $user_id)
    {
        try {
            $result = Request::unbanChatMember([
                'chat_id' => $chat_id,
                'user_id' => $user_id,
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }

    public function approveChatJoinRequest($chat_id, $user_id)
    {
        try {
            $result = Request::approveChatJoinRequest([
                'chat_id' => $chat_id,
                'user_id' => $user_id,
            ]);
            return $result;
        } catch (TelegramException $e) {
            return $e->getMessage();
        }
    }
}