<?php

namespace MySQLConcentrator;

class Server
{
  public $connections = array();
  public $listen_socket;
  public $listen_address = '127.0.0.1';
  public $listen_port = 3307;
  public $log_file_name = 'mysql-concentrator.log';
  public $mysql_connection;
  public $mysql_address = '127.0.0.1';
  public $mysql_port = 3306;
  public $check_for_implicit_commits = TRUE;
  public $throw_exception_on_implicit_commits = TRUE;
  public $transform_truncates = TRUE;
  public static $original_error_handler = NULL;
  public static $error_handler_set = FALSE;

  function __construct($settings = array())
  {
    if (!self::$error_handler_set)
    {
      error_reporting(E_ALL);
      self::$error_handler_set = TRUE;
      self::$original_error_handler = set_error_handler(array('MySQLConcentrator\Server', 'error_handler'));
    }
    $this->log = new Log($this->log_file_name);
    $this->mysql_address = \GR\Hash::fetch($settings, 'host', $this->mysql_address);
    $this->mysql_port = \GR\Hash::fetch($settings, 'port', $this->mysql_port);
    $this->listen_port = \GR\Hash::fetch($settings, 'listen_port', $this->listen_port);
  }

  function create_mysql_connection()
  {
    $socket = $this->create_socket("mysql socket", '0.0.0.0');
    $this->mysql_connection = new MySQLConnection($this, "mysql socket", $socket, FALSE, $this->mysql_address, $this->mysql_port);
    $this->connections[] = $this->mysql_connection;
  }

  function create_socket($socket_name, $address, $port = 0)
  {
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === FALSE)
    {
      throw new SocketException("Error creating $socket_name socket", $socket);
    }
    $result = @socket_set_nonblock($socket);
    if ($result === FALSE)
    {
      throw new SocketException("Error setting $socket_name socket nonblocking", $socket);
    }
    $result = @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    if ($result === FALSE)
    {
      throw new SocketException("Error setting option SOL_SOCKET SO_REUSEADDR to 1 for $socket_name socket", $socket);
    }
    $result = @socket_bind($socket, $address, $port);
    if ($result === FALSE)
    {
      throw new SocketException("Error binding $socket_name socket to {$address}:{$port}", $socket);
    }
    return $socket;
  }

  static function error_handler($errno, $errstr, $errfile, $errline, $context)
  {
    if (self::$original_error_handler != NULL)
    {
      $function_name = self::$original_error_handler;
      $function_name($errno, $errstr, $errfile, $errline, $context);
    }
    if ((error_reporting() & $errno) > 0)
    {
      throw new FatalException("$errno: $errstr: $errfile: $errline");
    }
  }

  function get_connection_for_socket($socket)
  {
    foreach ($this->connections as $connection)
    {
      if ($connection->socket == $socket)
      {
	return $connection;
      }
    }
    return NULL;
  }

  function listen()
  {
    $this->listen_socket = $this->create_socket("listen socket", $this->listen_address, $this->listen_port);
    $result = @socket_listen($this->listen_socket);
    if ($result === FALSE)
    {
      throw new SocketException("Error listening to listen socket on {$this->listen_address}:{$this->listen_port}", $this->listen_socket);
    }
  }

  function remove_connection($connection_to_delete)
  {
    if ($this->mysql_connection == $connection_to_delete)
    {
      $this->mysql_connection = NULL;
    }
//    $connection_to_delete->log("removing from server connections.\n");
    $index = array_search($connection_to_delete, $this->connections);
    unset($this->connections[$index]);
  }

  function run()
  {
    $this->listen();
    $read_sockets = array();
    $write_sockets = array();
    while (1)
    {
      $read_sockets = array($this->listen_socket);
      $write_sockets = array();
      foreach ($this->connections as $connection)
      {
        $read_sockets[] = $connection->socket;
//        $connection->log("checking write\n");
        if ($connection->wants_to_write())
        {
          $write_sockets[] = $connection->socket;
        }
      }
      $exception_sockets = NULL;
//      $this->log->log("select(" . print_r($read_sockets, TRUE) . ", " . print_r($write_sockets, TRUE) . ", " . print_r($exception_sockets, TRUE) . ")\n");
      $num_changed_sockets = @socket_select($read_sockets, $write_sockets, $exception_sockets, NULL);
      if ($num_changed_sockets === FALSE)
      {
        throw new SocketException("Error selecting on read sockets " . print_r($read_sockets, TRUE) . ", write sockets " . print_r($write_sockets, TRUE), FALSE);
      }
      elseif ($num_changed_sockets > 0)
      {
        foreach ($write_sockets as $write_socket)
        {
          $connection = $this->get_connection_for_socket($write_socket);
          $connection->write();
        }
        foreach ($read_sockets as $read_socket)
        {
          if ($read_socket == $this->listen_socket)
          {
            $socket = socket_accept($this->listen_socket);
            if ($socket === FALSE)
            {
              throw new SocketException("Error accepting connection on listen socket", $this->listen_socket);
            }
            if ($this->mysql_connection == NULL)
            {
              $this->create_mysql_connection();
              $this->mysql_connection->connect();
            }
            $client_connection = new ClientConnection($this, "client", $socket, TRUE);
//            $client_connection->log("adding to server connections.\n");
            $this->connections[] = $client_connection;
          }
          else
          {
            $connection = $this->get_connection_for_socket($read_socket);
            $connection->read();
            if ($connection->closed)
	    {
	      $this->remove_connection($connection);
            }
          }
        }
      }
    }
  }
}
