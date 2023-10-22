<?php

declare(strict_types=1);

function get_db_instance(): \PDO {
  static $db;
  if (!$db) {
    $db_host = getenv('POSTGRES_HOST');
    $db_port = getenv('POSTGRES_PORT');
    $db_user = getenv('POSTGRES_USER');
    $db_password = getenv('POSTGRES_PASSWORD');
    $db_name = getenv('POSTGRES_DB');

    $db = new \PDO(sprintf('pgsql:host=%s;port=%s;user=%s;password=%s;dbname=%s', $db_host, $db_port, $db_user, $db_password, $db_name));;
  }

  return $db;
}
