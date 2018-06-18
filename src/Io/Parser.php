<?php

namespace React\MySQL\Io;

use React\MySQL\Exception;
use React\MySQL\Command;
use React\Stream\DuplexStreamInterface;

/**
 * @internal
 */
class Parser extends \Evenement\EventEmitter
{
    const PHASE_GOT_INIT   = 1;
    const PHASE_AUTH_SENT  = 2;
    const PHASE_AUTH_ERR   = 3;
    const PHASE_HANDSHAKED = 4;

    const RS_STATE_HEADER = 0;
    const RS_STATE_FIELD  = 1;
    const RS_STATE_ROW    = 2;

    const STATE_STANDBY = 0;
    const STATE_BODY    = 1;

    protected $user     = 'root';
    protected $passwd   = '';
    protected $dbname   = '';

    /**
     * Keeps a reference to the command that is currently being processed.
     *
     * The MySQL protocol is inherently sequential, the pending commands will be
     * stored in an `Executor` queue.
     *
     * The MySQL protocol communication starts with the server sending a
     * handshake message, so the current command will be `null` until it's our
     * turn.
     *
     * Similarly, when one command is finished, it will continue processing the
     * next command from the `Executor` queue. If no command is outstanding,
     * this will be reset to the `null` state.
     *
     * @var \React\MySQL\Command|null
     */
    protected $currCommand;

    protected $debug = false;

    protected $state = 0;

    protected $phase = 0;

    public $seq = 0;
    public $clientFlags = 239237;

    public $warnCount;
    public $message;

    protected $maxPacketSize = 0x1000000;

    public $charsetNumber = 0x21;

    protected $serverVersion;
    protected $threadId;
    protected $scramble;

    protected $serverCaps;
    protected $serverLang;
    protected $serverStatus;

    protected $rsState = 0;
    protected $pctSize = 0;
    protected $resultRows = [];
    protected $resultFields = [];

    protected $insertId;
    protected $affectedRows;

    public $protocalVersion = 0;

    protected $errno = 0;
    protected $errmsg = '';

    private $buffer;

    protected $connectOptions;

    /**
     * @var \React\Stream\DuplexStreamInterface
     */
    protected $stream;
    /**
     * @var Executor
     */
    protected $executor;

    /**
     * @deprecated
     * @see self::$currCommand
     */
    protected $queue;

    public function __construct(DuplexStreamInterface $stream, Executor $executor)
    {
        $this->stream   = $stream;
        $this->executor = $executor;

        // @deprecated unused, exists for BC only.
        $this->queue    = new \SplQueue();

        $this->buffer   = new Buffer();
        $executor->on('new', array($this, 'handleNewCommand'));
    }

    public function start()
    {
        $this->stream->on('data', array($this, 'parse'));
        $this->stream->on('close', array($this, 'onClose'));
    }

    public function handleNewCommand()
    {
        if ($this->currCommand === null) {
            $this->nextRequest();
        }
    }

    public function debug($message)
    {
        if ($this->debug) {
            $bt = debug_backtrace();
            $caller = array_shift($bt);
            printf("[DEBUG] <%s:%d> %s\n", $caller['class'], $caller['line'], $message);
        }
    }

    public function setOptions($options)
    {
        foreach ($options as $option => $value) {
            if (property_exists($this, $option)) {
                $this->$option = $value;
            }
        }
    }

    public function parse($data)
    {
        $this->buffer->append($data);
packet:
        if ($this->state === self::STATE_STANDBY) {
            if ($this->buffer->length() < 4) {
                return;
            }

            $this->pctSize = $this->buffer->readInt3();
            //printf("packet size:%d\n", $this->pctSize);
            $this->state = self::STATE_BODY;
            $this->seq = $this->buffer->readInt1() + 1;
        }

        $len = $this->buffer->length();
        if ($len < $this->pctSize) {
            $this->debug('Buffer not enouth, return');

            return;
        }
        $this->state = self::STATE_STANDBY;
        //$this->stream->bufferSize = 4;
        if ($this->phase === 0) {
            $this->phase = self::PHASE_GOT_INIT;
            $this->protocalVersion = $this->buffer->readInt1();
            $this->debug(sprintf("Protocal Version: %d", $this->protocalVersion));
            if ($this->protocalVersion === 0xFF) { //error
                $fieldCount = $this->protocalVersion;
                $this->protocalVersion = 0;
                printf("Error:\n");

                $this->rsState = self::RS_STATE_HEADER;
                $this->resultFields = [];
                $this->resultRows = [];
                if ($this->phase === self::PHASE_AUTH_SENT || $this->phase === self::PHASE_GOT_INIT) {
                    $this->phase = self::PHASE_AUTH_ERR;
                }

                goto field;
            }
            if (($p = $this->buffer->search("\x00")) === false) {
                printf("Finish\n");
                //finish
                return;
            }

            $options = &$this->connectOptions;

            $options['serverVersion'] = $this->buffer->read($p, 1);
            $options['threadId']      = $this->buffer->readInt4();
            $this->scramble           = $this->buffer->read(8, 1);
            $options['ServerCaps']    = $this->buffer->readInt2();
            $options['serverLang']    = $this->buffer->readInt1();
            $options['serverStatus']  = $this->buffer->readInt2();
            $this->buffer->read(13);
            $restScramble             = $this->buffer->read(12, 1);
            $this->scramble          .= $restScramble;

            $this->nextRequest(true);
        } else {
            $fieldCount = $this->buffer->readInt1();
field:
            if ($fieldCount === 0xFF) {
                // error packet
                $this->errno   = $this->buffer->readInt2();
                $state = $this->buffer->read(6);
                $this->errmsg  = $this->buffer->read($this->pctSize - $len + $this->buffer->length());
                $this->debug(sprintf("Error Packet:%d %s\n", $this->errno, $this->errmsg));

                $this->onError();
                $this->nextRequest();
            } elseif ($fieldCount === 0x00) {
                // Empty OK Packet
                $this->debug('Ok Packet');

                $isAuthenticated = false;
                if ($this->phase === self::PHASE_AUTH_SENT) {
                    $this->phase = self::PHASE_HANDSHAKED;
                    $isAuthenticated = true;
                }

                $this->affectedRows = $this->buffer->readIntLen();
                $this->insertId     = $this->buffer->readIntLen();
                $this->serverStatus = $this->buffer->readInt2();
                $this->warnCount    = $this->buffer->readInt2();

                $this->message      = $this->buffer->read($this->pctSize - $len + $this->buffer->length());

                if ($this->rsState === self::RS_STATE_ROW) {
                    // Empty OK packet during result set => row with only empty strings
                    $row = array();
                    foreach ($this->resultFields as $field) {
                        $row[$field['name']] = '';
                    }
                    $this->onResultRow($row);
                } else {
                    // otherwise this terminates a query without a result set (UPDATE, INSERT etc.)
                    if ($isAuthenticated) {
                        $this->onAuthenticated();
                    } else {
                        $this->onSuccess();
                    }
                    $this->debug(sprintf("AffectedRows: %d, InsertId: %d, WarnCount:%d", $this->affectedRows, $this->insertId, $this->warnCount));
                    $this->nextRequest();
                }
            } elseif ($fieldCount === 0xFE) {
                // EOF Packet
                $this->debug('EOF Packet');
                if ($this->rsState === self::RS_STATE_ROW) {
                    // finalize this result set (all rows completed)
                    $this->debug('result done');

                    $this->onResultDone();
                    $this->nextRequest();
                } else {
                    // move to next part of result set (header->field->row)
                    ++$this->rsState;
                }

            } else {
                // Data packet
                $this->debug('Data Packet');
                $this->buffer->prepend($this->buffer->buildInt1($fieldCount));

                if ($this->rsState === self::RS_STATE_HEADER) {
                    $this->debug('Header packet of Data packet');
                    $extra = $this->buffer->readIntLen();
                    //var_dump($extra);
                    $this->rsState = self::RS_STATE_FIELD;
                } elseif ($this->rsState === self::RS_STATE_FIELD) {
                    $this->debug('Field packet of Data packet');
                    $field = [
                        'catalog'   => $this->buffer->readStringLen(),
                        'db'        => $this->buffer->readStringLen(),
                        'table'     => $this->buffer->readStringLen(),
                        'org_table' => $this->buffer->readStringLen(),
                        'name'      => $this->buffer->readStringLen(),
                        'org_name'  => $this->buffer->readStringLen()
                    ];

                    $this->buffer->skip(1);
                    $field['charset']     = $this->buffer->readInt2();
                    $field['length']      = $this->buffer->readInt4();
                    $field['type']        = $this->buffer->readInt1();
                    $field['flags']       = $this->buffer->readInt2();
                    $field['decimals']    = $this->buffer->readInt1();
                    //var_dump($field);
                    $this->resultFields[] = $field;
                } elseif ($this->rsState === self::RS_STATE_ROW) {
                    $this->debug('Row packet of Data packet');
                    $row = [];
                    foreach ($this->resultFields as $field) {
                        $row[$field['name']] = $this->buffer->readStringLen();
                    }
                    $this->onResultRow($row);
                }
            }
        }
        $this->buffer->restBuffer($this->pctSize - $len + $this->buffer->length());
        goto packet;
    }

    private function onResultRow($row)
    {
        // $this->debug('row data: ' . json_encode($row));
        $this->resultRows[] = $row;
        $command = $this->currCommand;
        $command->emit('result', array($row, $command, $command->getConnection()));
    }

    protected function onError()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        $error = new Exception($this->errmsg, $this->errno);
        $command->setError($error);
        $command->emit('error', array($error, $command, $command->getConnection()));
        $this->errmsg = '';
        $this->errno  = 0;
    }

    protected function onResultDone()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        $command->resultRows   = $this->resultRows;
        $command->resultFields = $this->resultFields;
        $command->emit('results', array($this->resultRows, $command, $command->getConnection()));
        $command->emit('end', array($command, $command->getConnection()));

        $this->rsState      = self::RS_STATE_HEADER;
        $this->resultRows   = $this->resultFields = [];
    }

    protected function onSuccess()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        if ($command->equals(Command::QUERY)) {
            $command->affectedRows = $this->affectedRows;
            $command->insertId     = $this->insertId;
            $command->warnCount    = $this->warnCount;
            $command->message      = $this->message;
        }
        $command->emit('success', array($command, $command->getConnection()));
    }

    protected function onAuthenticated()
    {
        $command = $this->currCommand;
        $this->currCommand = null;

        $command->emit('authenticated', array($this->connectOptions));
    }

    protected function onClose()
    {
        $this->emit('close');
        if ($this->currCommand !== null) {
            $command = $this->currCommand;
            $this->currCommand = null;

            if ($command->equals(Command::QUIT)) {
                $command->emit('success');
            } else {
                $command->emit('error', array(
                    new \RuntimeException('Connection lost'),
                    $command,
                    $command->getConnection()
                ));
            }
        }
    }

    public function authenticate()
    {
        if ($this->phase !== self::PHASE_GOT_INIT) {
            return;
        }
        $this->phase = self::PHASE_AUTH_SENT;

        $clientFlags = Constants::CLIENT_LONG_PASSWORD |
                Constants::CLIENT_LONG_FLAG |
                Constants::CLIENT_LOCAL_FILES |
                Constants::CLIENT_PROTOCOL_41 |
                Constants::CLIENT_INTERACTIVE |
                Constants::CLIENT_TRANSACTIONS |
                Constants::CLIENT_SECURE_CONNECTION |
                Constants::CLIENT_CONNECT_WITH_DB;

        $packet = pack('VVc', $clientFlags, $this->maxPacketSize, $this->charsetNumber)
                . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
                . $this->user . "\x00"
                . $this->getAuthToken($this->scramble, $this->passwd)
                . ($this->dbname ? $this->dbname . "\x00" : '');

        $this->sendPacket($packet);
        $this->debug('Auth packet sent');
    }

    public function getAuthToken($scramble, $password = '')
    {
        if ($password === '') {
            return "\x00";
        }
        $token = sha1($scramble . sha1($hash1 = sha1($password, true), true), true) ^ $hash1;

        return $this->buffer->buildStringLen($token);
    }

    public function sendPacket($packet)
    {
        return $this->stream->write($this->buffer->buildInt3(strlen($packet)) . $this->buffer->buildInt1($this->seq++) . $packet);
    }

    protected function nextRequest($isHandshake = false)
    {
        if (!$isHandshake && $this->phase != self::PHASE_HANDSHAKED) {
            return false;
        }

        if (!$this->executor->isIdle()) {
            $command = $this->executor->dequeue();
            $this->currCommand = $command;

            if ($command->equals(Command::INIT_AUTHENTICATE)) {
                $this->authenticate();
            } else {
                $this->seq = 0;
                $this->sendPacket($this->buffer->buildInt1($command->getId()) . $command->getSql());
            }
        }

        return true;
    }
}
