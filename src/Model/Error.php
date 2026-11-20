<?php

namespace Elastic\Apm\PhpAgent\Model;

use Elastic\Apm\PhpAgent\Interfaces\ModelInterface;
use Elastic\Apm\PhpAgent\Model\Context\Context;

class Error extends AbstractModel implements ModelInterface
{
    protected const ID_BYTES = 16;

    /**
     * Data for correlating errors with transactions
     *
     * @var  Transaction
     */
    private $transaction;

    /** @var Context */
    protected $context;

    /** @var string */
    protected $culprit;

    /** @var Exception */
    protected $exception;

    /** @var Log */
    protected $log;

    public function __construct(?array $config = [])
    {
        $transaction = $config['transaction'] ?? null;
        unset($config['transaction']);
        parent::__construct($config);
        if ($transaction !== null) {
            $this->setTransaction($transaction);
        }
        $this->timestamp ??= $this->getTimestampStart();
    }

    /**
     * @param Transaction $transaction
     */
    public function setTransaction(Transaction $transaction)
    {
        //$transaction->setSampled(false);
        $this->transaction = $transaction;
        $this->transaction_id = $transaction->id;
        $this->trace_id = $transaction->trace_id;
        if (null === $this->parent_id) {
            $this->parent_id = $transaction->id;
        }
    }

    /**
     * @param Context $context
     */
    public function setContext(Context $context)
    {
        $this->context = $context;
    }

    /**
     * @param string $culprit
     */
    public function setCulprit(string $culprit)
    {
        $this->culprit = $culprit;
    }

    /**
     * @param Exception $exception
     */
    public function setException(Exception $exception)
    {
        $this->exception = $exception;
    }

    /**
     * @param Log $log
     */
    public function setLog(Log $log)
    {
        $this->log = $log;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'trace_id' => $this->trace_id,
            'parent_id' => $this->parent_id,
            'transaction_id' => $this->transaction_id,
            'timestamp' => $this->timestamp,
            'transaction' => $this->transaction === null ? null : [
                'type' => $this->transaction->getType(),
                'sampled' => $this->transaction->isSampled(),
            ],
            'context' => $this->context,
            'culprit' => $this->culprit,
            'exception' => $this->exception,
            'log' => $this->log,
        ];
    }

    /**
     * Get start timestamp
     *
     * @return int
     */
    public function getTimestampStart(): int
    {
        return $this->timer->now();
    }

    /**
     * Define object validation rules
     *
     * @return array
     */
    public function validationRules(): array
    {
        return [
            'required' => ['id'],
            'depends' => [
                'transaction_id' => ['trace_id', 'parent_id'],
                'trace_id' => ['parent_id'],
                'parent_id' => ['trace_id'],
            ],
        ];
    }
}
