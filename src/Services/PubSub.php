<?php

namespace Sumra\SDK\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Sumra\SDK\Jobs\QueueJob;
use Sumra\SDK\Models\PublisherMessage;

class PubSub
{
    /**
     * Config array
     *
     * @var array
     */
    public $config;

    /**
     * Model class for publisher message
     *
     * @var string
     */
    public $publisherModelClass;

    /**
     * Model class for subscriber message
     * @var string
     */
    public $subscriberModelClass;

    /**
     * TODO
     * @var int
     */
    public $maxTime = 600;

    /**
     * @var bool
     */
    public bool $transaction = false;

    /**
     * PubSub constructor.
     */
    public function __construct()
    {
        $this->config = config('pubsub');

        // TODO
        $this->publisherModelClass = 'Sumra\SDK\Models\PublisherMessage';
        $this->subscriberModelClass = 'Sumra\SDK\Models\SubscriberMessage';
    }

    /**
     * Begin transaction and call function
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function transaction(callable $callback)
    {
        $this->transaction = true;

        DB::beginTransaction();

        try {
            $callback();
        } catch (Exception $e) {
            DB::rollBack();

            throw $e;
        }

        return $this;
    }

    /**
     * Publish message to rabbitmq broker.
     * For every message we save a record in database.
     *
     * @param $event
     * @param $message
     * @param null $queue
     * @param null $exchange
     */
    public function publish($event, $message, $queue, $exchange = null)
    {
        try {
            $model = new $this->publisherModelClass;
            $model->uniq_id = uniqid('', true);
            $model->queue = $queue;
            $model->exchange = $exchange ?? $queue;
            $model->event = $event;
            $model->message = $message;
            $model->save();

            if ($this->transaction) {
                DB::commit();
            }
        } catch (Exception $e) {
            if ($this->transaction) {
                DB::rollBack();
            }

            throw $e;
        }

        $job = new QueueJob($model->toArray());
        dispatch($job->onQueue($queue));

        // TODO
        $model->status = PublisherMessage::STATUS_SENT;
        $model->save();
    }

    /**
     * Job handler
     *
     * @param QueueJob $job
     *
     * @throws Exception
     */
    public function handle(QueueJob $job)
    {
        try {
            $message = $this->subscriberModelClass::where('uniq_id', $job->data['uniq_id'])->first();
            $time = time();

            if (empty($message)) {
                $message = new $this->subscriberModelClass();
                $message->fill($job->data);
                $message->updated_at = $time;
                // TODO Need to develop algorithm to move unacked messages to end of queue or to another exchange
            } elseif ($message->updated_at->timestamp + $this->maxTime < $time) {
                $message->updated_at = $time;
            } else {
                // TODO If don't throw exception the queue extension send to rabbitmq ack and rabbitmq delete the message
                //throw new \Exception();
                $job->getJob()->markAsFailed();
                $message->status = 6;
            }

            $message->save();

            if ($message->status != 6) {
                event($message->event, [$message->message]);
            }
        } catch (Exception $e) {
            $job->getJob()->markAsFailed();

            throw $e;
        }
    }
}
