<?php

declare(strict_types=1);

function send_email(string $from, string $to, string $text): void {
  sleep(random_int(1, 10));
}


function check_email(string $email): bool {
  sleep(random_int(1, 60));

  return $email !== 'invalid@email.com';
}
