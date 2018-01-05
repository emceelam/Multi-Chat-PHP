#!/usr/bin/env php
<?php

define ("TIME_OUT_DURATION", 10);
  # client will be disconnected after 10 seconds of no activity

class SocketServer {
  private $ip_address;
  private $port;
  private $curr_write_socket = NULL;
    // The current read socket.
    // Used for signal handling.
  private $all_rw_sockets   = array();
  private $expiration_times = array();
  private $buffered_reads   = array();

  function __construct ($ip_address, $port) {
    $this->ip_address = $ip_address;
    $this->port = $port;
  }

  # -------------
  # Accessors
  # In OO theory, you use get/set accessors to provide access to 
  # the member variables, eschewing direct access.
  # -------------
  function get_ip_address () {
    return $this->ip_address;
  }

  function get_port() {
    return $this->port;
  }

  function set_curr_write_socket ($socket) {
    $this->curr_write_socket = $socket;
  }

  function get_curr_write_socket () {
    return $this->curr_write_socket;
  }

  function set_rw_socket ($socket) {
    $this->all_rw_sockets [ (int) $socket ] = $socket;
  }

  function get_rw_sockets() {
    return array_values($this->all_rw_sockets);
  }
  
  function copy_rw_sockets () {
    $copy = array();
    
    foreach ($this->all_rw_sockets as $key => $value) {
      $copy[ $key ] = $value;
    }
    return $copy;
  }

  function unset_rw_socket ($socket) {
    unset($this->all_rw_sockets[(int)$socket]);
  }

  function reset_expiration_time ($socket) {
    $this->expiration_times[(int)$socket] = time() + TIME_OUT_DURATION;
  }

  function get_expiration_time ($socket) {
    return $this->expiration_times[(int)$socket];
  }

  function unset_expiration_times ($socket) {
    unset($this->expiration_times[(int)$socket]);
  }

  function read_buffered_read ($socket) {
    return array_key_exists((int)$socket, $this->buffered_reads)
                ? $this->buffered_reads[(int)$socket] 
                : '';
  }

  function set_buffered_read ($socket, $read_string) {
    $this->buffered_reads[(int)$socket] = $read_string;
  }

  function unset_buffered_read ($socket) {
    unset($this->buffered_reads[(int)$socket]);
  }

  #-------------
  # Socket methods
  # This is where the real work begins
  #-------------

  # socket_write_now()
  #   $socket: socket to write to
  #   $text: text to write on socket
  function socket_write_now ($socket, $text) {
    $this->set_curr_write_socket($socket);

    socket_set_nonblock($socket);
    if (socket_write($socket, $text, strlen($text)) === FALSE) {
      print "$socket is not ready for writing.\n";
      pcntl_signal_dispatch();
        # check if SIGIPE triggered
        # yes this is stupid. PHP 7.1 will introduce asynchronous signals
      return;
    }
    socket_set_block ($socket);
  }

  # close_the socket()
  #    $socket: the socket you want to close
  function close_the_socket ($socket)
  {
    $this->unset_rw_socket($socket);
    $this->unset_expiration_times ($socket);
    $this->unset_buffered_read ($socket);
    socket_shutdown($socket);
    socket_close ($socket);
    print "socket_close($socket)\n";
  }

  # run()
  # run forever, receiving incoming socket connections, copying out
  # incoming chat text, timing out sockets, and
  # gracefully shutting down sockets
  function run () {
    $ip_address = $this->get_ip_address();
    $port       = $this->get_port();

    set_time_limit(0);  // don't time out the server

    # If a process tries to write to a pipe that has no reader,
    # it will be sent the SIGPIPE signal
    # SIGPIPE will be checked when pcntl_signal_dispatch() is called.
    # PHP 7.1 will introduce asynchronous signals, removing the
    # need for pcntl_signal_dispatch()
    $this_obj = $this;
    pcntl_signal(SIGPIPE, function ( $signo ) use ($this_obj) {
        $socket = $this_obj->curr_write_socket;
        print "$socket has disconnected\n";
        $this->close_the_socket ($socket);
      }
    );

    // Create the passive socket.
    // This socket will listen for clients connecting to this server
    if (($passive = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) {
      echo "socket_create() failed: " . socket_strerror(socket_last_error()) . "\n";
      exit;
    }
    if (socket_bind($passive, $ip_address, $port) === false) {
      echo "socket_bind($passive, $ip_address, $port) failed: " . 
            socket_strerror(socket_last_error($passive)) . "\n";
      exit;
    }
    if (socket_listen($passive, 5) === false) {
      echo "socket_listen() failed: " .
            socket_strerror(socket_last_error($passive)) . "\n";
      exit;
    }

    $this->set_rw_socket($passive);

    print "Home brew chat server\n" .
          "To connect to this server, type\n" .
          "  telnet $ip_address $port\n" .
          "Clients will be timed out after being idle for " .
            TIME_OUT_DURATION . " seconds\n";

    // Let the chatting begin
    $curr_rw_sockets = array();
    $null1 = NULL;  // null variable for pass-by-reference parameter
    $null2 = NULL;  // null variable for pass-by-reference parameter
    while(true) {
      $curr_rw_sockets = $this->copy_rw_sockets();
      socket_select ($curr_rw_sockets, $null1, $null2, 1);

      // Read data from read sockets.
      $chatter = array();
      foreach ($curr_rw_sockets as $rw_socket) {
        // incoming connection
        if ($rw_socket == $passive) {
          if (($active = socket_accept($passive)) === false) {
            echo "socket_accept() failed: " .
                  socket_strerror(socket_last_error($passive)) . "\n";
            continue;
          }
          print "$active has connected\n";

          socket_set_option (
            $active, SOL_SOCKET, SO_LINGER, array ('l_onoff' => 1, 'l_linger' =>0));
            // set active socket to immediate death on close
          $this->set_rw_socket ($active);
          $this->reset_expiration_time ($active);
          $chatter[ (int) $active] = "<arrives>";
          $this->socket_write_now($active,
            "Welcome user" . (int) $active . ".\r\n" .
            "This is a chat server\r\n" .
            "What you type will be seen by other connected clients.\r\n" .
            "Clients are timed out after being idle for ".
              TIME_OUT_DURATION . " seconds.\r\n" .
            "To quit, type 'quit'.\r\n"
          );

          continue;
        }

        // incoming data from clients
        $read_returns = socket_read($rw_socket, 2048, PHP_BINARY_READ);
        if (strlen($read_returns) == 0) {
          print "$rw_socket has no more data to read\n";
          $this->close_the_socket ($rw_socket);
        }
        else {
          $buff = $this->read_buffered_read($rw_socket);
          $read_string = $buff . $read_returns;

          if (!preg_match('{\n$}', $read_returns)) {
            $this->set_buffered_read($rw_socket, $read_string);
          }
          elseif (preg_match ('{\s*quit\s*}i', $read_string)) {
            $this->close_the_socket($rw_socket);
            $chatter[(int)$rw_socket] = "<quits>";
            print ("socket " . (int) $rw_socket . " quits peacefully\n");
          }
          else {
            $read_string = trim($read_string);
            $this->unset_buffered_read ($rw_socket);
            $this->reset_expiration_time ($rw_socket);
            $chatter[(int)$rw_socket] = $read_string;
            print "$rw_socket says '$read_string'\n";
          }
        }
      }

      // Write chatter data to clients
      if ($chatter) {
        foreach ($this->get_rw_sockets() as $socket) {

          // skip passive socket
          if ($socket == $passive) {
            continue;
          }

          // Detect disconnected clients.
          if (array_key_exists ((int)$socket, $chatter)
            && strlen ($chatter[(int)$socket]) == 0)
          {
            // Send non-printable character.
            // Forces SIGPIPE to occur if client is disconnected.
            $this->socket_write_now ($socket, "\0");
          }

          // Send chatter data to client
          $buffer = '';
          foreach ($chatter as $chatty_id => $text) {
            if ($chatty_id != (int)$socket && strlen($text)) {
              $buffer .= "user{$chatty_id}: $text\r\n";
            }
          }
          if (!strlen($buffer)) {
            continue;
          }
          $this->socket_write_now($socket, $buffer);
        }
      }

      // Time out clients that have been idle too long
      $now_time = time();
      foreach ($this->get_rw_sockets() as $socket) {
        // skip passive socket
        if ($socket == $passive) {
          continue;
        }

        if ($this->get_expiration_time($socket) < $now_time) {
          print "$socket times out\n";
          $this->close_the_socket ($socket);
        }
      }
    }
  }
}

$ip_address = $argv[1] ?? '127.0.0.1';  # default ip address for loopback
$port       = $argv[2] ?? 4024;         # default port number, not used by IANA
if ( !preg_match ('{^\d{1,3}[.]\d{1,3}[.]\d{1,3}[.]\d{1,3}$}', $ip_address)
  || !preg_match ('{^\d+$}', "$port") )
{
  print "Usage: php $argv[0] ip_address port_num\n";
  exit;
}

$socket_server = new SocketServer($ip_address, $port);
$socket_server->run();


