<?php
declare(strict_types=1);
namespace Manomite\Engine\Queue\Redis;

class Redis
{
  
  private $context;
  private $queueName;
  private $message;
  private $vt;
  private $delay;
  private $maxsize;

    public function __construct($message = 'Hello World', String $queueName = 'sample757489475847594758', int $vt = 0, int $delay = 0, int $maxsize = 1073741824)
    {
      $redis = new \Redis();
      $redis->connect('127.0.0.1', 6379);
      $this->context = new RSMQ($redis);
       
      $this->message = $message;
      $this->queueName = $queueName;
      $this->vt = $vt;
      $this->delay = $delay;
      $this->maxsize = $maxsize;

      //Register queue for new queue worker
      $this->create();
        
    }

    private function create():bool
    {
      //Check if queue already exist
      if(!$this->context->queueExist($this->queueName)){
        return $this->context->createQueue($this->queueName, $this->vt, $this->delay, $this->maxsize);
      } else {
        return false;
      }
    }

    public function listQueues():array
    {
      return $this->context->listQueues();
    }

    public function deleteQueue():bool
    {
      return $this->context->deleteQueue($this->queueName);
    }

    public function getQueueAttributes():array
    {
      return $this->context->getQueueAttributes($this->queueName);
    }

    public function setQueueAttributes():array
    {
      return $this->context->setQueueAttributes($this->queueName, $this->vt, $this->delay, $this->maxsize);
    }

    public function send():string
    {
      $this->create();
      return $this->context->sendMessage($this->queueName, $this->message);
    }

    public function receiveMessage():array
    {
      return $this->context->receiveMessage($this->queueName);
    }

    public function acknowledge(array $receive):bool
    {
      if (isset($receive['id'])) {
        return $this->context->deleteMessage($this->queueName, $receive['id']);
      } else {
        return false;
      }
    }
}
