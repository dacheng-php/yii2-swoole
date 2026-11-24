<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Db;

use Throwable;
use yii\db\Command as BaseCommand;

/**
 * Coroutine-aware DB Command that marks broken pooled PDO connections for discard
 * when a connection-lost error occurs during execution.
 *
 * Intentionally does NOT silently retry failed statements to preserve explicitness
 * and avoid duplicating non-idempotent writes.
 */
class Command extends BaseCommand
{
    protected function internalExecute($rawSql)
    {
        try {
            parent::internalExecute($rawSql);
        } catch (Throwable $e) {
            if ($this->db instanceof Connection && Connection::isConnectionLostError($e)) {
                $this->db->markFailed();
                if ($this->db->getTransaction() === null) {
                    $this->db->close();
                }
            }

            throw $e;
        }
    }
}
