BEGIN;
CREATE TABLE IF NOT EXISTS users (
   id SERIAL PRIMARY KEY,
   username TEXT NOT NULL,
   email TEXT NOT NULL,
   is_email_confirmed BOOLEAN NOT NULL DEFAULT false,
   is_email_checked BOOLEAN NOT NULL DEFAULT false,
   is_email_valid BOOLEAN NOT NULL DEFAULT false
);

CREATE TABLE IF NOT EXISTS subscriptions (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
  expire_at TIMESTAMP NOT NULL,
  notification_bit_mask INT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS subscription_expire_at ON subscriptions (expire_at);

CREATE TABLE IF NOT EXISTS subscription_expiration_notification_queue (
   id SERIAL PRIMARY KEY,
   subscription_id INT NOT NULL REFERENCES subscriptions (id) ON DELETE CASCADE ON UPDATE CASCADE,
   context JSON NOT NULL
);

CREATE TABLE IF NOT EXISTS email_checker_queue (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    context JSON NOT NULL
);

INSERT INTO users (username, email, is_email_confirmed, is_email_checked, is_email_valid)
SELECT CONCAT('username', i),
       CASE
           WHEN random() < 0.07 THEN 'invalid@email.com'
           ELSE CONCAT('valid', i, '@email.com')
       END,
       random() <= 0.15, false, false
FROM generate_series(1, 5000000) as t(i);

INSERT INTO subscriptions (user_id, expire_at)
SELECT i, now() + random() * 2592000 * interval '1 second'::interval
FROM generate_series(1, 5000000, 5) as t(i);

COMMIT;
