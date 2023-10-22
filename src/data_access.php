<?php

declare(strict_types=1);

require_once __DIR__ . '/notification_queue_context.php';
require_once __DIR__ . '/email_checker_queue_context.php';

$db = get_db_instance();
function add_to_notification_queue(array $data): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
    INSERT INTO subscription_expiration_notification_queue (subscription_id, context) 
    VALUES(:subscription_id, :context)
SQL);

  try {
    $statement->execute([
      ':subscription_id' => (int) $data['subscription_id'],
      ':context' => json_encode([
        CONTEXT_FIELD_USERNAME => (string) $data['username'],
        CONTEXT_FIELD_EMAIL => (string) $data['email'],
        CONTEXT_FIELD_NOTIFICATION_DAY_BEFORE_EXPIRE => (int) $data['notification_day_before_expire'],
      ], JSON_THROW_ON_ERROR),
    ]);
  } catch (\JsonException $_) {
    fwrite(STDERR, sprintf('notification message context cannot be serialized; context: %s; subscription_id: %s', $message['context'], $data['subscription_id']));
  }
}

function add_to_email_checker_queue(array $data): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
      INSERT INTO email_checker_queue (user_id, context)
      VALUES(:user_id, :context)
SQL);

  try {
    $statement->execute([
      ':user_id' => (int) $data['user_id'],
      ':context' => json_encode([
        CONTEXT_FIELD_EMAIL => (string) $data['email'],
      ], JSON_THROW_ON_ERROR),
    ]);
  } catch (\JsonException $_) {
    fwrite(STDERR, sprintf('email checker message context cannot be serialized; context: %s; user_id: %s', $message['context'], $data['user_id']));
  }
}

function delete_from_notification_queue(int $id): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
    DELETE FROM subscription_expiration_notification_queue WHERE id = :id
SQL);
  $statement->execute([':id' => $id]);
}

function delete_from_email_queue(int $id): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
    DELETE FROM email_checker_queue WHERE id = :id
SQL);
  $statement->execute([':id' => $id]);
}

// проставляем бит того, что сообщение отправленно за определенное количество дней
function set_subscription_notification_day(int $subscription_id, int $day): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
    UPDATE subscriptions SET notification_bit_mask = notification_bit_mask | (1 << :day) WHERE id = :id
SQL);
  $statement->execute([
    ':day' => $day,
    ':id' => $subscription_id,
  ]);
}

/*
 * * в запросе указан лимит с учетом среднего потока сообщений в очередь, чтобы при старте на холодную не делать большие выборки
 * в дальнейшем лимит можно убрать
 *
 * notification_bit_mask -- битовая маска нужна для определения того, для каких подписок было выслано уведомления
 * при обновлении подписки - сбрасываем эту маску в ноль
 *
*/
function get_subscriptions_to_notify(int $limit): array {
  $db = get_db_instance();
  $statement = $db->query(<<<SQL
    SELECT u.username, u.email, s.id as subscription_id, 1 as notification_day_before_expire
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.expire_at > now()
      AND s.expire_at <= now() + interval '1 day'
      AND s.notification_bit_mask & (1 << 1) = 0
      AND
      (
        u.is_email_confirmed = true
        OR (u.is_email_checked = true AND u.is_email_valid = true)
      )
      AND NOT EXISTS(SELECT 1 FROM subscription_expiration_notification_queue q WHERE q.subscription_id = s.id)
    ORDER BY s.expire_at
    LIMIT $limit
SQL);
  $one_day_expiration = $statement->fetchAll();

  $statement = $db->query(<<<SQL
    SELECT u.username, u.email, s.id as subscription_id, 3 as notification_day_before_expire
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.expire_at > now() + interval '1 day'
      AND s.expire_at <= now() + interval '3 day'
      AND s.notification_bit_mask & (1 << 3) = 0
      AND
      (
        u.is_email_confirmed = true
        OR (u.is_email_checked = true AND u.is_email_valid = true)
      )
      AND NOT EXISTS(SELECT 1 FROM subscription_expiration_notification_queue q WHERE q.subscription_id = s.id)
    ORDER BY s.expire_at
    LIMIT $limit
SQL);
  $three_days_expiration = $statement->fetchAll();

  return array_merge($one_day_expiration, $three_days_expiration);
}

/**
 * в запросе указан лимит с учетом среднего потока сообщений в очередь, чтобы при старте на холодную не делать большие выборки
 * в дальнейшем лимит можно убрать
*/
function get_emails_to_check(int $limit): array {
  $db = get_db_instance();
  $statement = $db->query(<<<SQL
    SELECT u.id as user_id, u.email
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.expire_at > now() + interval '1 day'
      AND s.expire_at <= now() + interval '3 day'
      AND u.is_email_confirmed = false
      AND u.is_email_checked = false
      AND NOT EXISTS(SELECT 1 FROM email_checker_queue q WHERE q.user_id = s.id)
    ORDER BY expire_at DESC
    LIMIT $limit
SQL);

  $three_days_emails = $statement->fetchAll();

  /**
   * это дополнительный запрос для того, чтобы проверить почты юзеров, у которых подписка закончится в ближайшее время
   * после некоторого время этот запрос не будет возвращать ничего, т.к. все почты из запроса выше уже будут проверены
   * только если нет подписок на один день конечно:)
   */
  $statement = $db->query(<<<SQL
    SELECT u.id as user_id, u.email
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.expire_at > now() 
      AND s.expire_at <= now() + interval '1 day'
      AND u.is_email_confirmed = false
      AND u.is_email_checked = false
      AND NOT EXISTS(SELECT 1 FROM email_checker_queue q WHERE q.user_id = s.id)
    ORDER BY expire_at
    LIMIT $limit
SQL);

  $first_day_emails = $statement->fetchAll();

  return array_merge($first_day_emails, $three_days_emails);
}

function set_checked_user_email(int $user_id, bool $is_valid): void {
  $db = get_db_instance();
  $statement = $db->prepare(<<<SQL
        UPDATE users SET is_email_checked = true, is_email_valid = :is_valid WHERE id = :id
SQL);
  $statement->execute([':is_valid' => (int) $is_valid, ':id' => $user_id]);
}

function get_email_checker_queue_messages(): array {
  $db = get_db_instance();
  // LIMIT 1 нужен для того, чтобы не блокировать записи юзера в бд на долго, потенциально на ((n - 1) * 60) секунд
  $queue_query = $db->query(<<<SQL
    SELECT id, user_id, context 
    FROM email_checker_queue 
    ORDER BY id
    LIMIT 1
    FOR UPDATE SKIP LOCKED
SQL);
  return $queue_query->fetchAll();
}

function get_subscription_notification_queue_messages(): array {
  $db = get_db_instance();
  // LIMIT 1 нужен для того, чтобы не блокировать записи подписки на долго, потенциально на ((n - 1) * 10) секунд
  $queue_query = $db->query(<<<SQL
    SELECT id, subscription_id, context 
    FROM subscription_expiration_notification_queue 
    ORDER BY id
    LIMIT 1
    FOR UPDATE SKIP LOCKED
SQL);
  return $queue_query->fetchAll();
}
