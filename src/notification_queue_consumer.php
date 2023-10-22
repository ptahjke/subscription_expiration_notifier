<?php

declare(strict_types=1);

require_once __DIR__ . '/notification_queue_context.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailing.php';
require_once __DIR__ . '/data_access.php';

$email_from = (string) getenv('FROM_EMAIL');
if (!filter_var($email_from, FILTER_VALIDATE_EMAIL)) {
  fwrite(STDERR, sprintf('email from is invalid: %s', $email_from));
  exit(1);
}

$db = get_db_instance();
/**
 * транзация нужна для того, чтобы избежать конкурентного получения одной и той же записи другим косьюмером
 * исполнение управления транзакций не идеальное, но для данной задачи достаточно
 *
 * запускается 60 крон задач
 */
$db->beginTransaction();
$messages = get_subscription_notification_queue_messages();
foreach ($messages as $message) {
  try {
    $data = json_decode($message['context'], true, 512, JSON_THROW_ON_ERROR);
    send_email($email_from, $data[CONTEXT_FIELD_EMAIL], sprintf('%s, your subscription is expiring soon"!', $data[CONTEXT_FIELD_USERNAME]));
    set_subscription_notification_day($message['subscription_id'], (int)$data[CONTEXT_FIELD_NOTIFICATION_DAY_BEFORE_EXPIRE]);

    fwrite(STDOUT, sprintf('email was sent to %s', $data[CONTEXT_FIELD_EMAIL]));
  } catch (JsonException $_) {
    fwrite(STDERR, sprintf('notification message context cannot be deserialized; context: %s', $message['context']));
  } finally {
    delete_from_notification_queue($message['id']);
  }
}
$db->commit();
