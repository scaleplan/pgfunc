<?php
namespace PgFunc {
    use PDO;
    use PDOException;
    use PgFunc\Exception\Database;
    use PgFunc\Exception\Usage;

    /**
     * Class for dealing with transactions and savepoints.
     *
     * Don't instantiate new transaction objects directly. Use createTransaction() method instead.
     * @see Connection::createTransaction()
     *
     * @author red-defender
     * @package pgfunc
     */
    class Transaction {
        /**
         * @var array Savepoints by connections.
         */
        private static $savepoints = [];

        /**
         * @var PDO Current connection.
         */
        private $db;

        /**
         * @var string Unique connection ID.
         */
        private $connectionId;

        /**
         * @var int Current transaction ID (not the database XID).
         */
        private $transactionId;

        /**
         * @var int Current savepoint ID (zero means whole transaction).
         */
        private $savepointId = 0;

        /**
         * Initialize transaction.
         *
         * @param PDO $db Current connection.
         * @param string $connectionId Unique connection ID.
         */
        final public function __construct(PDO $db, $connectionId) {
            $this->db = $db;
            $this->connectionId = (string) $connectionId;
            $this->begin();
        }

        /**
         * Rollback current transaction automatically on object destroy.
         */
        final public function __destruct() {
            $this->rollback();
        }

        /**
         * Transaction cloning is forbidden.
         *
         * @throws Usage
         */
        final public function __clone() {
            throw new Usage('Transaction cloning is forbidden', Exception::TRANSACTION_ERROR);
        }

        /**
         * Rollback all pending transactions.
         *
         * @param string $connectionId Unique connection ID.
         */
        final public static function deactivateConnection($connectionId) {
            unset(self::$savepoints[$connectionId]);
        }

        /**
         * Commit transaction or release savepoint.
         *
         * @return bool Command was really invoked.
         */
        final public function commit() {
            if ($this->savepointId) {
                return $this->finalizeSavepoint('RELEASE SAVEPOINT sp' . $this->savepointId, $this->savepointId);
            } else {
                return $this->finalizeTransaction('commit');
            }
        }

        /**
         * Rollback transaction or savepoint.
         *
         * @return bool Command was really invoked.
         */
        final public function rollback() {
            if ($this->savepointId) {
                return $this->finalizeSavepoint(
                    'ROLLBACK TO SAVEPOINT sp' . $this->savepointId,
                    $this->savepointId + 1
                );
            } else {
                return $this->finalizeTransaction('rollBack');
            }
        }

        /**
         * Set local PostgreSQL configuration parameter.
         *
         * @param string $name Setting name.
         * @param mixed $value Setting value.
         * @throws Usage When setting name is invalid or transaction is inactive.
         * @throws Database When query fails.
         */
        final public function setLocal($name, $value) {
            $isActiveTransaction = $this->savepointId
                ? isset(self::$savepoints[$this->connectionId][$this->transactionId][$this->savepointId])
                : isset(self::$savepoints[$this->connectionId][$this->transactionId]);
            if (! $isActiveTransaction) {
                throw new Usage('Transaction or connection is already closed', Exception::TRANSACTION_ERROR);
            }

            if (! preg_match('/^[a-z0-9_\.\s]+$/isDS', $name)) {
                throw new Usage('Setting name is invalid: ' . $name, Exception::INVALID_IDENTIFIER);
            }
            try {
                $query = 'SET LOCAL ' . $name . ' TO :value';
                if (preg_match('/^\s*TIME\s+ZONE\s*$/isDS', $name)) {
                    $query = 'SET LOCAL TIME ZONE :value';
                }
                $this->db->prepare($query)->execute(['value' => $value]);
            } catch (PDOException $exception) {
                throw new Database(
                    'Error on setting configuration parameter: ' . $name,
                    Exception::FAILED_QUERY,
                    $exception
                );
            }
        }

        /**
         * Start transaction or create savepoint in database.
         */
        private function begin() {
            if ($this->invoke('inTransaction')) {
                $this->createSavepoint();
            } else {
                $this->beginTransaction();
            }
        }

        /**
         * Start new transaction in database.
         */
        private function beginTransaction() {
            static $transactionId = 0;
            $this->transactionId = ++$transactionId;
            $this->invoke('beginTransaction');
            self::$savepoints[$this->connectionId][$transactionId] = [];
        }

        /**
         * Create new savepoint in database.
         */
        private function createSavepoint() {
            static $savepointId = 0;
            $this->savepointId = ++$savepointId;
            $this->query('SAVEPOINT sp' . $savepointId);
            $transactionIdList = array_keys(self::$savepoints[$this->connectionId]);
            $this->transactionId = end($transactionIdList);
            self::$savepoints[$this->connectionId][$this->transactionId][$savepointId] = $savepointId;
        }

        /**
         * Commit or rollback transaction.
         *
         * @param string $method Connection method for invoking.
         * @return bool Command was really invoked.
         */
        private function finalizeTransaction($method) {
            if (! isset(self::$savepoints[$this->connectionId][$this->transactionId])) {
                return false;
            }

            $this->invoke($method);
            unset(self::$savepoints[$this->connectionId][$this->transactionId]);
            return true;
        }

        /**
         * Release or rollback savepoint.
         *
         * @param string $query SQL statement for querying.
         * @param int $currentSavepointId Minimal savepoint ID for dropping.
         * @return bool Command was really invoked.
         */
        private function finalizeSavepoint($query, $currentSavepointId) {
            if (empty(self::$savepoints[$this->connectionId][$this->transactionId][$this->savepointId])) {
                return false;
            }

            $this->query($query);
            self::$savepoints[$this->connectionId][$this->transactionId] = array_filter(
                self::$savepoints[$this->connectionId][$this->transactionId],
                function ($savepointId) use ($currentSavepointId) {
                    return $savepointId < $currentSavepointId;
                }
            );
            return true;
        }

        /**
         * Invoke connection method.
         *
         * @param string $method Connection method for invoking.
         * @return mixed Result of method invoking.
         * @throws Database When query fails.
         */
        private function invoke($method) {
            try {
                return $this->db->$method();
            } catch (PDOException $exception) {
                throw new Database('Transaction error in method: ' . $method, Exception::TRANSACTION_ERROR, $exception);
            }
        }

        /**
         * Query SQL statement.
         *
         * @param string $query SQL statement for querying.
         * @throws Database When query fails.
         */
        private function query($query) {
            try {
                $this->db->query($query);
            } catch (PDOException $exception) {
                throw new Database('Savepoint error in query: ' . $query, Exception::TRANSACTION_ERROR, $exception);
            }
        }
    }
}