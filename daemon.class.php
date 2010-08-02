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
							if ($message === false || !strlen($message)) {
								try {
									@$client->close();
									unset($this->_socket[$i], $this->_client[$i]);
								} catch (Exception $e) {
									// ignore it..
								}
							} else {
								$client->process($message);
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
		public function clearClient($index) {
			unset($this->_socket[$index], $this->_client[$index]);
		}
	}
?>