<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Core\Daemon;
use PHPDaemon\FS\File;
use PHPDaemon\Network\IOStream;

/**
 * Process
 *
 * @property null onReadData
 * @property callable onEOF
 * @property callable onRead
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class ShellCommand extends IOStream {

	/** @var */
	public $writeState;
	/** @var */
	public $finishWrite;

	/**
	 * Command string
	 * @var string
	 */
	protected $cmd;

	/**
	 * Executable path
	 * @var string
	 */
	public $binPath;

	/**
	 * Opened pipes
	 * @var array
	 */
	protected $pipes;

	/**
	 * Process descriptor
	 * @var resource
	 */
	protected $pd;

	/**
	 * FD write
	 * @var resource
	 */
	protected $fdWrite;

	/**
	 * Output errors?
	 * @var boolean
	 */
	protected $outputErrors = true;

	/**
	 * SUID
	 * @var string
	 */
	public $setUser;
	/**
	 * SGID
	 * @var string
	 */
	public $setGroup;

	/**
	 * Chroot
	 * @var string
	 */
	public $chroot = '/';

	/**
	 * Hash of environment's variables
	 * @var array
	 */
	protected $env = []; // 

	/**
	 * Chdir
	 * @var string
	 */
	public $cwd;
	/**
	 * Path to error logfile
	 * @var string
	 */
	protected $errlogfile = null;
	/**
	 * Array of arguments
	 * @var array
	 */
	protected $args;

	/**
	 * Process priority
	 * @var int
	 */
	protected $nice;

	/**
	 * @var \EventBufferEvent
	 */
	protected $bevWrite;
	/**
	 * @var \EventBufferEvent
	 */
	protected $bevErr;

	/**
	* Got EOF?
	 * @var bool
	 */
	protected $EOF = false;

	/**
	 * Get command string
	 * @return string
	 */
	public function getCmd() {
		return $this->cmd;
	}

	/**
	 * Set group
	 * @param string $group
	 * @return object $this
	 */
	public function setGroup($val) {
		$this->setGroup = $val;
		return $this;
	}

	/**
	 * Set cwd
	 * @param string $dir
	 * @return object $this
	 */
	public function setCwd($dir) {
		$this->cwd = $dir;
		return $this;
	}

	/**
	 * Set group
	 * @param string $user
	 * @return object $this
	 */
	public function setUser($val) {
		$this->setUser = $val;
		return $this;
	}

	/**
	 * Set chroot
	 * @param string $dir
	 * @return object $this
	 */
	public function setChroot($dir) {
		$this->chroot = $dir;
		return $this;
	}

	/**
	 * Execute
	 * @param string $binPath Optional. Binpath.
	 * @param callable $cb 	  Callback
	 * @param array $args     Optional. Arguments.
	 * @param array $env      Optional. Hash of environment's variables.
	 * @return object ShellCommand
	 */
	public static function exec($binPath = null, $cb = null, $args = null, $env = null) {
		$o = new static;
		$data = '';
		$o->bind('read', function($o, $buf) use (&$data, $o) {
			$data .= $buf;
		});
		$o->bind('eof', function($o) use (&$data, $cb) {
			call_user_func($cb, $o, $data);
			$o->close();
		});
		$o->execute($binPath, $args, $env);
	}


	/**
	 * Sets fd
	 * @param mixed File descriptor
	 * @param [object EventBufferEvent]
	 * @return void
	 */

	public function setFd($fd, $bev = null) {
		$this->fd = $fd;
		if ($fd === false) {
			$this->finish();
			return;
		}
		$this->fdWrite = $this->pipes[0];
		$flags         = !is_resource($this->fd) ? \EventBufferEvent::OPT_CLOSE_ON_FREE : 0;
		$flags |= \EventBufferEvent::OPT_DEFER_CALLBACKS; /* buggy option */
		$this->bev      = new \EventBufferEvent(Daemon::$process->eventBase, $this->fd, 0, [$this, 'onReadEv'], null, [$this, 'onStateEv']);
		$this->bevWrite = new \EventBufferEvent(Daemon::$process->eventBase, $this->fdWrite, 0, null, [$this, 'onWriteEv'], null);
		if (!$this->bev || !$this->bevWrite) {
			$this->finish();
			return;
		}
		if ($this->priority !== null) {
			$this->bev->priority = $this->priority;
		}
		if ($this->timeout !== null) {
			$this->setTimeout($this->timeout);
		}
		if (!$this->bev->enable(\Event::READ | \Event::TIMEOUT | \Event::PERSIST)) {
			$this->finish();
			return;
		}
		if (!$this->bevWrite->enable(\Event::WRITE | \Event::TIMEOUT | \Event::PERSIST)) {
			$this->finish();
			return;
		}
		$this->bev->setWatermark(\Event::READ, $this->lowMark, $this->highMark);

		init:
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}

	/**
	 * Sets an array of arguments
	 * @param array Arguments
	 * @return object ShellCommand
	 */
	public function setArgs($args = NULL) {
		$this->args = $args;

		return $this;
	}

	/**
	 * Set a hash of environment's variables
	 * @param array Hash of environment's variables
	 * @return object ShellCommand
	 */
	public function setEnv($env = NULL) {
		$this->env = $env;

		return $this;
	}

	/**
	 * Called when got EOF
	 * @return void
	 */
	public function onEofEvent() {
		if ($this->EOF) {
			return;
		}
		$this->EOF = true;

		$this->event('eof');
	}

	/**
	 * Set priority.
	 * @param integer $nice Priority
	 * @return object ShellCommand
	 */
	public function nice($nice = NULL) {
		$this->nice = $nice;

		return $this;
	}

	/**
	 * Called when new data received
	 * @return boolean
	 */
	protected function onRead() {
		if (func_num_args() === 1) {
			$this->onRead = func_get_arg(0);
			return $this;
		}
		$this->event('read');
	}

	/**
	 * Build arguments string from associative/enumerated array (may be mixed)
	 * @param array $args
	 * @return string
	 */
	public static function buildArgs($args) {
		if (!is_array($args)) {
			return '';
		}
		$ret = '';
		foreach ($args as $k => $v) {
			if (!is_int($v) && ($v !== null)) {
				$v = escapeshellarg($v);
			}
			if (is_int($k)) {
				$ret .= ' ' . $v;
			} else {
				if ($k{0} !== '-') {
					$ret .= ' --' . $k . ($v !== null ? '=' . $v : '');
				} else {
					$ret .= ' ' . $k . ($v !== null ? '=' . $v : '');
				}
			}
		}
		return $ret;
	}

	/**
	 * Execute
	 * @param string $binPath Optional. Binpath.
	 * @param array $args     Optional. Arguments.
	 * @param array $env      Optional. Hash of environment's variables.
	 * @return object ShellCommand
	 */
	public function execute($binPath = NULL, $args = NULL, $env = NULL) {
		if ($binPath !== NULL) {
			$this->binPath = $binPath;
		}

		if ($env !== NULL) {
			$this->env = $env;
		}

		if ($args !== NULL) {
			$this->args = $args;
		}
		$this->cmd = $this->binPath . static::buildArgs($this->args) . ($this->outputErrors ? ' 2>&1' : '');

		if (
				isset($this->setUser)
				|| isset($this->setGroup)
		) {
			if (
					isset($this->setUser)
					&& isset($this->setGroup)
					&& ($this->setUser !== $this->setGroup)
			) {
				$this->cmd = 'sudo -g ' . escapeshellarg($this->setGroup) . '  -u ' . escapeshellarg($this->setUser) . ' ' . $this->cmd;
			}
			else {
				$this->cmd = 'su ' . escapeshellarg($this->setGroup) . ' -c ' . escapeshellarg($this->cmd);
			}
		}

		if ($this->chroot !== '/') {
			$this->cmd = 'chroot ' . escapeshellarg($this->chroot) . ' ' . $this->cmd;
		}

		if ($this->nice !== NULL) {
			$this->cmd = 'nice -n ' . ((int)$this->nice) . ' ' . $this->cmd;
		}

		$pipesDescr = [
			0 => ['pipe', 'r'], // stdin is a pipe that the child will read from
			1 => ['pipe', 'w'] // stdout is a pipe that the child will write to
		];

		if (
				($this->errlogfile !== NULL)
				&& !$this->outputErrors
		) {
			$pipesDescr[2] = ['file', $this->errlogfile, 'a']; // @TODO: refactoring
		}

		$this->pd = proc_open($this->cmd, $pipesDescr, $this->pipes, $this->cwd, $this->env);
		if ($this->pd) {
			$this->setFd($this->pipes[1]);
		}

		return $this;
	}

	/**
	 * Finish write stream
	 * @return bool
	 */
	public function finishWrite() {
		if (!$this->writeState) {
			$this->closeWrite();
		}

		$this->finishWrite = true;

		return true;
	}

	/**
	 * Close the process
	 * @return void
	 */
	public function close() {
		parent::close();
		$this->closeWrite();
		if (is_resource($this->pd)) {
			proc_close($this->pd);
		}
	}

	/**
	 * Called when stream is finished
	 */
	public function onFinish() {
		$this->onEofEvent();
	}

	/**
	 * Close write stream
	 * @return $this
	 */
	public function closeWrite() {
		if ($this->bevWrite) {
			if (isset($this->bevWrite)) {
				$this->bevWrite->free();
			}
			$this->bevWrite = null;
		}

		if ($this->fdWrite) {
			fclose($this->fdWrite);
			$this->fdWrite = null;
		}

		return $this;

	}

	/**
	 * Got EOF?
	 * @return bool
	 */
	public function eof() {
		return $this->EOF;

	}

	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function write($data) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');
			return false;
		}
		if (!isset($this->bevWrite)) {
			return false;
		}
		if (!strlen($data)) {
			return true;
		}
		$this->writing   = true;
		Daemon::$noError = true;
		if (!$this->bevWrite->write($data) || !Daemon::$noError) {
			$this->close();
			return false;
		}
		return true;
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer flushed at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function writeln($data) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');
			return false;
		}
		if (!isset($this->bevWrite)) {
			return false;
		}
		if (!strlen($data) && !strlen($this->EOL)) {
			return true;
		}
		$this->writing = true;
		$this->bevWrite->write($data);
		$this->bevWrite->write($this->EOL);
		return true;
	}

	/**
	 * Sets callback which will be called once when got EOF 
	 * @param callable $cb
	 * @return $this
	 */
	public function onEOF($cb = NULL) {
		$this->onEOF = CallbackWrapper::wrap($cb);
		return $this;
	}
}
