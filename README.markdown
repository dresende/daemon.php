Basic implementation
--------------------

	<?php
		require_once "daemon.class.php";
		
		// client
		class ChatClient implements DaemonClient {
			private $socket;
			public function __construct(&$socket) {
				$this->socket = $socket;
			}
			public function process($buffer) {
				$buffer = trim($buffer);
				printf("User sent: '%s'\n", $buffer);
				
				$this->send("you sent: '{$buffer}'\n");
			}
			private function send($text) {
				fwrite($this->socket, $text . "\n");
			}
		}
		
		// server
		class ChatServer extends Daemon {
			public function onConnect(&$socket, $idx) {
				printf("Client connected!\n");
				
				$cli = new ChatClient($socket);
				return $cli;
			}
		}
		
		$bind = "0.0.0.0:80";
		$server = new ChatServer();
		$server->listen($bind) or die("Could not start server on {$bind}!\n");
		
		printf("Listening for connections on %s.. (try telnet'ing, type something and hit ENTER)\n", $bind);
		$server->wait();
	?>