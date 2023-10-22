<?php

declare(strict_types=1);

require_once __DIR__ . '/email_checker_queue_context.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailing.php';
require_once __DIR__ . '/data_access.php';

$db = get_db_instance();
/**
 * транзация нужна для того, чтобы избежать конкурентного получения одной и той же записи другим косьюмером
 * исполнение управления транзакций не идеальное, но для данной задачи достаточно
 *
 * отделил проверку почты от отправки письма -- можно было сделать все в одном потоке
 * но в случае, если почта была не проверена, то в этот момент нужно было обновлять запись пользователя
 * это блокировало бы строку юзера на обновление на время отправки письма (если почта валидна конечно), потенциально 10 секунд, что не очень хорошо
 *
 * 60 крон задач
*/
$db->beginTransaction();
$messages = get_email_checker_queue_messages();
foreach ($messages as $message) {
  try {
    $data = json_decode($message['context'], true, 512, JSON_THROW_ON_ERROR);
    $is_valid = check_email($data[EMAIL_CHECKER_CONTEXT_FIELD_EMAIL]);
    set_checked_user_email((int) $message['user_id'], $is_valid);

    fwrite(STDOUT, sprintf('email %s was checked; result %b', $data[EMAIL_CHECKER_CONTEXT_FIELD_EMAIL], $is_valid));
  } catch (JsonException $_) {
    fwrite(STDERR, sprintf('email checker message context cannot be deserialized; context: %s', $message['context']));
  } finally {
    delete_from_email_queue($message['id']);
  }
}
$db->commit();
