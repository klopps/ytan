ALTER TABLE user_token MODIFY COLUMN purpose ENUM('invite', 'reset', 'email_change') NOT NULL;
