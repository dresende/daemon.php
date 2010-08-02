<?php
	/**
	 * Interface for a daemon's client object
	 *
	 **/
	interface DaemonClient {
		/**
		 * DaemonClient::process($buffer)
		 *
		 * Process data coming from client.
		 *
		 * @param	string	$buffer		Data coming from client
		 *
		 **/
		public function process($buffer);
	}
?>