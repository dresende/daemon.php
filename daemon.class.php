<?php
	require_once dirname(__FILE__) . "/client.daemon.interface.php";

	/**
	 * PHP Generic daemon
	 *
	 * @author	Diogo Resende <dresende@thinkdigital.pt>
	 * @created	1 Aug 2010
	 **/
	class Daemon {
		// sockets list (server + clients)
		private $_socket = array();
		// client references (list of classes handling client sockets)
		private $_client = array(null);
		// error information
		private $_errno = null;
		private $_errstr = "";
		// timeout for no socket activity
		private $_timeout;

		/**
		 * Daemon::listen($address, $timeout = null)
		 *
		 * Start listening on address ("ip:port"), with a timeout. When
		 * reaching timeout (without activity), onTimeout is called. Timeout
		 * can be null to avoid this call.
		 *
		 * @param	string	$address	"ip:port" (e.g.: 0.0.0.0:80)
		 * @param	mixed	$timeout	Timeout in seconds (or null for none)
		 * @return	boolean			Success of binding to $address
		 *
		 **/
		public function listen($address, $timeout = null) {
			$this->_timeout = $timeout;
			$this->_socket[0] = @stream_socket_server("tcp://{$address}", $this->_errno, $this->_errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
			if (!$this->_socket[0]) {
				return false;
			}
			
			return true;
		}
		
		/**
		 * Daemon::wait()
		 *
		 * Start daemon loop. This is usually called after successfully calling Daemon::listen(). It
		 * starts the daemon listening loop.
		 *
		 **/
		public function wait() {
			$null = null;
			for ($this->cycle = 0; ; $this->cycle++) {
				$sockets = $this->_socket;
				
				if (stream_select($sockets, $null, $null, $this->_timeout) !== 0) {
					//printf("[%s sockets selected]\n", count($sockets));
					foreach ($sockets as &$socket) {
						if ($socket === $this->_socket[0]) {
							// connection request
							if ($socket = stream_socket_accept($this->_socket[0])) {
								$this->_socket[] = $socket;
								$this->_client[] = $this->onConnect($socket, count($this->_client));
							} else {
								// accept failed. ignore
							}
						} else {
							// communication
							$i = array_search($socket, $this->_socket);
							$client = &$this->_client[$i];

							$message = @fread($socket, 1024);
							if ($message !== false && strlen($message)) {
								$client->process($message);
							} else {
								// zombie clients (this avoids busy waiting bug)
								$data = stream_get_meta_data($socket);
								if ($data['eof']) {
									$this->closeClient($i);
									usleep(500000);
								}
							}
						}
					}
				} else {
					//printf("* Nothing is happening.. (cycle:%d)\n", $this->cycle);
					$this->onTimeout();
				}
			}
		}
		
		/**
		 * Daemon::onTimeout()
		 *
		 * Called by Daemon::listen() after N seconds timeout without any activity.
		 * You might leave this method empty (don't extend it) or just use timeout=null
		 * to avoid calling it in the first place.
		 *
		 **/
		public function onTimeout() {
			// after timeout, some other actions might be done..
		}
		
		/**
		 * Daemon::onConnect(&$socket, $client_index)
		 *
		 * Called by Daemon::listen() after a successfull client connection. You
		 * must return an object that implements DaemonClient interface.
		 *
		 * @param	reference	$socket		Socket reference
		 * @param	integer		$client_index	Client socket index (needed to call Daemon::clearClient)
		 * @return 	reference			DaemonClient implemented object
		 *
		 **/
		public function onConnect(&$socket, $client_index) {
			// should be extended and changed to handle the connection
		}
		
		/**
		 * Daemon::clearClient($index)
		 *
		 * Clear client trace. This is usually called by a client to close the connection.
		 **/
		public function closeClient($index) {
			stream_socket_shutdown($this->_socket[$index], STREAM_SHUT_RDWR);
			fclose($this->_socket[$index]);
			unset($this->_socket[$index], $this->_client[$index]);
			
			$this->_socket = array_values($this->_socket);
			$this->_client = array_values($this->_client);
		}
		
		/**
		 * Daemon::closeServer()
		 *
		 * Close all sockets.
		 **/
		public function closeServer() {
			while (count($this->_socket) > 0) {
				$this->closeClient(count($this->_socket) - 1);
			}
		}
		
		/**
		 * Daemon::stopInstance($pid_file)
		 *
		 * Send SIGQUIT to process with PID saved on $pid_file
		 *
		 * @param	string	$pid_file	PID file path
		 * @return	boolean			Success
		 **/
		public static function stopInstance($pid_file) {
			if (file_exists($pid_file) && posix_access($pid_file, POSIX_R_OK | POSIX_W_OK)) {
				$pid = file_get_contents($pid_file);
				if ($pid > 0) {
					posix_kill($pid, SIGQUIT);
					@unlink($pid_file);
						
					return true;
				}
			}
			return false;
		}
		
		/**
		 * Daemon::daemonize($pid_file)
		 *
		 * Daemonizes script and saves PID to $pid_file.
		 * It returns:
		 * 	'running'	If there is a process running with $pid_file PID
		 *	'error'		If an error ocurred with fork() or setsid()
		 *	'parent'	Successfull fork, this will be returned to parent (you should just exit)
		 *	'daemon'	Successfull fork, this will be returned to the child (the actual daemon)
		 *
		 * @param	string	$pid_file	PID file path
		 * @return	string			Daemonize status (check description)
		 **/
		public static function daemonize($pid_file = null) {
			// check if $pid_file has a valid PID
			if ($pid_file !== null) {
				if (file_exists($pid_file) && posix_access($pid_file, POSIX_R_OK | POSIX_W_OK)) {
					$pid = file_get_contents($pid_file);
					if ($pid > 0) {
						if (@posix_kill((double) $pid, SIGALRM)) {
							return 'running';
						}
					}
				}
			}
			
			// fork..
			$pid = pcntl_fork();
			if ($pid < 0) {
				return 'error';
			} elseif ($pid) {
				return 'parent';
			} else {
				// detach from parent..
				$sid = posix_setsid();
				if ($sid < 0) return 'error';
				
				// save PID
				if ($pid_file !== null)
					file_put_contents($pid_file, posix_getpid());
				
				// close resources (if runned using php exec(), this will ensure it does not hung
				if (is_resource(STDOUT)) fclose(STDOUT);
				if (is_resource(STDERR)) fclose(STDERR);
				
				return 'daemon';
			}
		}
	}
?>
