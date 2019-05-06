<?php

	require __DIR__ . '/vendor/autoload.php';

	class LogToMysql {

		public $config = [
			'host' => 'localhost',
			'database' => 'logs'
		];

		public $conn;

		public $inputFilePath;

		public function __construct($arguments) {
			$Loader = new josegonzalez\Dotenv\Loader(__DIR__ . '/.env');
			$Loader->parse();
			$Loader->toEnv();

			$this->config['username'] = $this->_loadEnv('MYSQL_USERNAME');
			$this->config['password'] = $this->_loadEnv('MYSQL_PASSWORD');

			if($db = $this->_loadEnv('MYSQL_DATABASE', false)) {
				$this->config['database'] = $db;
			}

			if (!isset($arguments[1])) {
				$this->_out("No file provided as first argument", "error");
			}

			$this->inputFilePath = $arguments[1];

			if (file_exists($this->inputFilePath)) {
				preg_match("/([^\/]*)$/", $this->inputFilePath, $matches);
				$this->tableName = str_replace(".", "-", $matches[0]);

				$this->init();
			} else {
				$this->_out("Cannot find file: ".$this->inputFilePath, "error");
			}
		}

		public function init() {
			$c = $this->config;
			$this->conn = new mysqli($c['host'], $c['username'], $c['password']) or die($this->_out("Connect failed: %s\n". $this->conn->error, "error", true));

			$db = mysqli_select_db($this->conn, $c['database']);

			if (!$db) {
				$this->_createDatabase();
			}

			// Check if table exists for file provided
			$result = $this->_runQuery([ "SELECT table_name FROM information_schema.tables WHERE table_schema = '".$c['database']."' AND table_name = '".$this->tableName."';" ]);

			if (!$result) {
				$this->_createLogTable($this->tableName);
			}

			$result = $this->_runQuery([ "SELECT type FROM `".$c['database']."`.`log_configs` WHERE name = '".$this->tableName."';" ]);

			if (!$result) {
				$configType = $this->_createLogConfig($this->tableName);
			} elseif (count($result)) {
				$configType = (int) $result[0]['type'];
			}

			// Check if an existing "last log" exists
			$latestExisting = $this->_runQuery([ "SELECT timecode FROM `".$c['database']."`.`".$this->tableName."` ORDER BY timecode DESC LIMIT 1;" ]);
			$timecode = null;

			if ($latestExisting && count($latestExisting)) {
				$tc = $latestExisting[0]['timecode'];

				preg_match("/[0-9]{4}-[0-9]{2}-[0-9]{2}/", $tc, $matches);
				$timecode = $matches[0];
			}

			$output = $this->_parseLog($configType, $timecode);

			$this->_writeToMysql($output);
		}

		private function _parseLog($type = null, $startDate = null) {
			if ($type === null) {
				$this->_out("Did not find config type for this file", "error");
			}

			$file = fopen($this->inputFilePath, "r");

			$startDate = $startDate ? strtotime($startDate) : null;

			$response = [];
			while ( !feof($file) ) {
				$line = fgets($file);

				$output = $this->_parseLine($line, $type);

				if ($output && (!$startDate || $startDate < strtotime($output['timecode']))) {
					$response[] = $output;
				}
			}

			return $response;
		}

		private function _parseLine($input, $type = null) {
			if ($type === 0) {
				$timecodeRegex = "/(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})\.[0-9]*Z\s\d\s/";

				$r = preg_match($timecodeRegex, $input, $matches);

				if ($r && (isset($matches[1]) && !empty($matches[1]))) {
					$timecode = $matches[1]." ".$matches[2];
					$message = str_replace($matches[0], "", $input);
					$message = str_replace("\n", "", $message);
				} else {
					return false;
				}
			}

			return [
				'timecode' => $timecode,
				'message' => $message
			];
		}

		private function _writeToMysql($data) {
			$db = $this->config['database'];
			$table = $this->tableName;

			$chunkSize = 5000;

			$chunks = ceil(count($data) / $chunkSize);

			for ($i = 0; $i < $chunks; $i++) {
				$currChunk = array_slice($data, $i * $chunkSize, $chunkSize);

				$sql = [
					"INSERT INTO `${db}`.`${table}` (`timecode`, `message`) VALUES "
				];

				foreach($currChunk as $k => $item) {
					$tc = $item['timecode'];
					$msg = mysqli_real_escape_string($this->conn, $item['message']);

					$q = "( '${tc}', '${msg}')";

					if ( ($k + 1) === count($currChunk) ) {
						$q .= ';';
					} else {
						$q .= ',';
					}

					$sql[] = $q;
				}

				if (!$this->_runQuery($sql)) {
					$this->_out("Error while saving log data", "error");
				}
			}
		}

		private function _loadEnv($env, $kill = true) {
			if (isset($_ENV[$env]) && !empty($_ENV[$env])) {
				return $_ENV[$env];
			} elseif ($kill) {
				$this->_out("No ${env} env set", "error") ;
				exit;
			}

			return false;
		}

		private function _out($string, $type, $return = false) {
			$output = $string;
			$kill = false;

			switch($type) {
				case 'error':
					$output = "\e[31m${string}\e[0m";
					$kill = true;
				break;
				case 'warning':
					$output = "\e[33m${string}\e[0m";
				break;
				case 'info':
					$output = "\e[36m${string}\e[0m";
				break;
				default:
					$output = $string;
				break;
			}

			if ($return) {
				return $output;
			} else {
				echo $output;
				echo "\n";

				if ($kill) {
					exit;
				}
			}
		}

		private function _prompt($q, $options = null) {
			$valid = false;
			while (!$valid) {
				$query = $q;
				if ($options) {
					echo "\n";
					foreach($options as $key => $opt) {
						$this->_out("[${key}] ${opt}", "info");
					}

					$keys = implode(", ", array_keys($options));
					$query = $this->_out("${q} [${keys}]: ", "info", true);
				} else {
					$query = $this->_out($q." (Y/N): ", "info", true);
				}

				echo $query;
				$a = readline();
				$a = strtoupper($a);

				if($options) {
					if (isset($options[$a])) {
						$valid = true;
						$answer = $a;
					} else {
						$this->_out("Please select one of the given options", "info");
					}
				} else {
					if ($a === 'Y' || $a === 'YES' || $a === 'N' || $a === 'NO') {
						$valid = true;
						$answer = ($a === 'Y' || $a === 'YES');
					} else {
						$this->_out("Please answer with yes/no", "info");
					}
				}
			}

			return $answer;
		}

		private function _runQuery(Array $sql) {
			$result = $this->conn->query(implode("", $sql));

			if(!empty($this->conn->error)) {
				$this->_out($this->conn->error, "error");
			}

			if ($result) {
				if($result === true) {
					return $result;
				}

				$count = $result->num_rows;

				if ($count > 0) {
					$rows = [];
					while ($row = $result->fetch_assoc()) {
						$rows[] = $row;
					}
				}

				$result->close();
				if ($count === 0) {
					return false;
				} else {
					return $rows;
				}
			} else {
				return false;
			}
		}

		private function _createDatabase() {
			$db = $this->config['database'];
			$sql = [ "CREATE DATABASE ${db};" ];

			if(!$this->_runQuery($sql)) {
				$this->_out("Could not create database ${db}", "error");
			};

			$sql = [
				"CREATE TABLE `logs`.`log_configs` (",
				  "`id` INT NOT NULL AUTO_INCREMENT,",
				  "`name` VARCHAR(255) NOT NULL,",
				  "`type` INT NOT NULL,",
				  "PRIMARY KEY (`id`));"
			];

			if(!$this->_runQuery($sql)) {
				$this->_out("Could not create log_files table ${db}", "error");
			};
		}

		private function _createLogTable($name) {
			$db = $this->config['database'];
			$sql = [
				"CREATE TABLE `${db}`.`${name}` (",
				"`id` INT NOT NULL AUTO_INCREMENT,",
				"`timecode` DATETIME NOT NULL,",
				"`message` TEXT NOT NULL,",
				"PRIMARY KEY (`id`));"
			];

			if(!$this->_runQuery($sql)) {
				$this->_out("Could not create table ${name}", "error");
			}
		}

		private function _createLogConfig($name) {
			$db = $this->config['database'];

			$type = $this->_prompt("Please select a log format type", [
				"0" => "YYYY-MM-DDTHH:mm:ss.fracZ %number% [%type%] %MESSAGE%",
				"1" => "YYYY-MM-DD HH:mm:ss %MESSAGE%"
			]);
			$type = (int) $type;

			$sql = [
				"INSERT INTO `${db}`.`log_configs` (`name`, `type`) VALUES ('${name}', ${type});"
			];

			if (!$this->_runQuery($sql)) {
				$this->_out("Could not add new config settings", "error");
			}
		}

	}

	new LogToMysql($argv);
?>